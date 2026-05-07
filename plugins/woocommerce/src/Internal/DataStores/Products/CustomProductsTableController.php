<?php
/**
 * CustomProductsTableController class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Utilities\PluginUtil;
use WC_Admin_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the High-Performance Product Storage (HPPS) feature.
 *
 * Responsibilities:
 *  - Register the `custom_product_tables` feature with FeaturesController.
 *  - Register the `product_placeholder` post type used by the data store to
 *    obtain a real wp_posts ID without firing CPT hooks (mirrors HPOS' use of
 *    `shop_order_placehold`). See HPPS-review-and-plan.md issue #2.
 *  - Provide the settings UI block for "Product data storage" with a clear
 *    radio toggle between legacy (CPT) and HPPS storage.
 *  - Swap the product data stores via `woocommerce_product*_data_store`
 *    filters when the feature is enabled.
 *
 * The class deliberately does no database access — that's the data store's job.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class CustomProductsTableController {

	/**
	 * Option key controlling whether HPPS is the active product storage.
	 */
	public const CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION = 'woocommerce_custom_product_tables_enabled';

	/**
	 * Feature slug used by FeaturesController and FeaturesUtil::declare_compatibility().
	 */
	public const FEATURE_ID = 'custom_product_tables';

	/**
	 * Placeholder post type used to obtain a real wp_posts ID for new products
	 * without firing `save_post_product` and other CPT hooks.
	 */
	public const PLACEHOLDER_POST_TYPE = 'product_placeholder';

	/**
	 * Query arg used by the "Sync products now" link on the Features page
	 * and on the admin notice. Mirrors the HPOS `wc_hpos_sync_now` arg.
	 */
	private const SYNC_QUERY_ARG = 'wc_hpps_sync_now';

	/**
	 * Query arg used by the "Stop sync" link.
	 */
	private const STOP_SYNC_QUERY_ARG = 'wc_hpps_stop_sync';

	/**
	 * Features controller dependency.
	 *
	 * @var FeaturesController
	 */
	private FeaturesController $features_controller;

	/**
	 * Plugin util dependency.
	 *
	 * @var PluginUtil
	 */
	private PluginUtil $plugin_util;

	/**
	 * HPPS simple/external data store instance.
	 *
	 * @var ProductsTableDataStore
	 */
	private ProductsTableDataStore $simple_data_store;

	/**
	 * HPPS variable data store instance.
	 *
	 * @var ProductsTableVariableDataStore
	 */
	private ProductsTableVariableDataStore $variable_data_store;

	/**
	 * HPPS variation data store instance.
	 *
	 * @var ProductsTableVariationDataStore
	 */
	private ProductsTableVariationDataStore $variation_data_store;

	/**
	 * HPPS grouped data store instance.
	 *
	 * @var ProductsTableGroupedDataStore
	 */
	private ProductsTableGroupedDataStore $grouped_data_store;

	/**
	 * Synchronizer used for table-create / migration enqueue / status calls.
	 *
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $data_synchronizer;

	/**
	 * Postmeta → wc_products column listener used in CPT ↔ HPPS sync mode.
	 *
	 * @var ProductDataSyncListener
	 */
	private ProductDataSyncListener $sync_listener;

	/**
	 * CPT ↔ HPPS structural verifier shared with the WP-CLI command.
	 *
	 * @var ProductMigrationVerifier
	 */
	private ProductMigrationVerifier $verifier;

	/**
	 * Constructor: register hooks. Dependencies are injected later via init().
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Inject dependencies via the DI container.
	 *
	 * Importantly, we receive the *instances* of the four HPPS data stores
	 * (not class names). The container has already resolved each one's own
	 * `init()` graph (meta store, database util, etc.), so when we hand the
	 * instance back via `woocommerce_product*_data_store`, `WC_Data_Store`
	 * uses it as-is — bypassing the `new $class()` short-circuit that
	 * would otherwise leave dependencies unwired.
	 *
	 * @internal
	 *
	 * @param FeaturesController              $features_controller   Features controller.
	 * @param PluginUtil                      $plugin_util           Plugin util helper.
	 * @param ProductsTableDataStore          $simple_data_store     Simple/external HPPS data store.
	 * @param ProductsTableVariableDataStore  $variable_data_store   Variable HPPS data store.
	 * @param ProductsTableVariationDataStore $variation_data_store  Variation HPPS data store.
	 * @param ProductsTableGroupedDataStore   $grouped_data_store    Grouped HPPS data store.
	 * @param ProductDataSynchronizer         $data_synchronizer     Data synchronizer (table lifecycle + migration).
	 * @param ProductDataSyncListener         $sync_listener         Postmeta → wc_products listener.
	 * @param ProductMigrationVerifier        $verifier              Shared verifier (Tools page + WP-CLI).
	 */
	final public function init(
		FeaturesController $features_controller,
		PluginUtil $plugin_util,
		ProductsTableDataStore $simple_data_store,
		ProductsTableVariableDataStore $variable_data_store,
		ProductsTableVariationDataStore $variation_data_store,
		ProductsTableGroupedDataStore $grouped_data_store,
		ProductDataSynchronizer $data_synchronizer,
		ProductDataSyncListener $sync_listener,
		ProductMigrationVerifier $verifier
	): void {
		$this->features_controller  = $features_controller;
		$this->plugin_util          = $plugin_util;
		$this->simple_data_store    = $simple_data_store;
		$this->variable_data_store  = $variable_data_store;
		$this->variation_data_store = $variation_data_store;
		$this->grouped_data_store   = $grouped_data_store;
		$this->data_synchronizer    = $data_synchronizer;
		$this->sync_listener        = $sync_listener;
		$this->verifier             = $verifier;

		// The listener gates itself on the data-sync option, so registering
		// once is safe even when sync is off — it just no-ops on every hook.
		$this->sync_listener->register_hooks();
	}

	/**
	 * Register WordPress and WooCommerce hooks owned by this controller.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'register_placeholder_post_type' ), 10, 0 );
		add_action( 'init', array( $this, 'maybe_self_heal_tables' ), 11, 0 );

		// Data store swaps. Use a high priority so we win against late filters.
		add_filter( 'woocommerce_product_data_store', array( $this, 'filter_product_data_store' ), 999, 1 );
		add_filter( 'woocommerce_product-variable_data_store', array( $this, 'filter_product_data_store' ), 999, 1 );
		add_filter( 'woocommerce_product-variation_data_store', array( $this, 'filter_product_data_store' ), 999, 1 );
		add_filter( 'woocommerce_product-grouped_data_store', array( $this, 'filter_product_data_store' ), 999, 1 );
		add_filter( 'woocommerce_product-external_data_store', array( $this, 'filter_product_data_store' ), 999, 1 );

		// Lifecycle: create tables and enqueue migration when the feature is
		// toggled on. `add_option_*` covers the very first time the option is
		// stored; `update_option_*` covers any subsequent toggle. Both fire
		// only when the value actually changes, so a no-op save is free.
		add_action( 'add_option_' . self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, array( $this, 'on_feature_option_added' ), 10, 2 );
		add_action( 'update_option_' . self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, array( $this, 'on_feature_option_updated' ), 10, 2 );

		// Admin UX: banner on every admin screen + Tools page entries +
		// Features-page "Sync now / Stop sync" handler.
		add_action( 'admin_notices', array( $this, 'render_pending_sync_notice' ) );
		add_action( 'woocommerce_sections_advanced', array( $this, 'handle_sync_now_action' ) );
		add_filter( 'woocommerce_debug_tools', array( $this, 'add_hpps_tools' ), 999 );
		add_filter( 'removable_query_args', array( $this, 'register_removable_query_args' ) );

		// Untrash recovery: the data store deletes the lookup row on trash
		// (so analytics readers don't drift), so we have to repopulate it
		// when the post comes back. Hooking `untrashed_post` keeps us in
		// step with `wp_untrash_post()` for both UI- and CLI-initiated
		// untrash and for variations untrashed via their parent.
		add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ), 20, 1 );
	}

	/**
	 * Re-establish the wc_products status and refresh the lookup row when
	 * an HPPS-native product is untrashed. The data store's trash branch
	 * sets `wc_products.status = 'trash'` and drops the lookup row; the
	 * mirror happens here to keep both tables in sync with `wp_posts`.
	 *
	 * @internal
	 *
	 * @param int $post_id Post id being untrashed.
	 * @return void
	 */
	public function on_post_untrashed( $post_id ): void {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'product', 'product_placeholder', 'product_variation' ), true ) ) {
			return;
		}

		// Only act if HPPS is the authoritative store and the row exists in wc_products.
		if ( ! $this->custom_product_tables_usage_is_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d LIMIT 1',
				ProductsTableDataStore::get_products_table_name(),
				$post_id
			)
		);
		if ( ! $exists ) {
			return;
		}

		$post_status = (string) get_post_status( $post_id );
		if ( '' === $post_status || 'trash' === $post_status ) {
			$post_status = 'publish';
		}

		// Mirror the new wp_posts.post_status onto wc_products.status so the
		// REST/admin queries that read the column directly stop filtering us
		// out as trashed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			ProductsTableDataStore::get_products_table_name(),
			array(
				'status'            => $post_status,
				'date_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->simple_data_store->update_lookup_table( $post_id );
	}

	/**
	 * Fired the first time the HPPS option is stored (no previous value).
	 *
	 * @internal
	 *
	 * @param string $option_name  Option name (unused).
	 * @param string $option_value New value (`'yes'` or `'no'`).
	 * @return void
	 */
	public function on_feature_option_added( $option_name, $option_value ): void {
		unset( $option_name );
		if ( 'yes' === $option_value ) {
			$this->ensure_tables_and_enqueue_migration();
		}
	}

	/**
	 * Fired when the HPPS option flips between `yes` and `no`.
	 *
	 * @internal
	 *
	 * @param string $old_value Previous value.
	 * @param string $new_value New value.
	 * @return void
	 */
	public function on_feature_option_updated( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}
		if ( 'yes' === $new_value ) {
			$this->ensure_tables_and_enqueue_migration();
			return;
		}
		// Toggle off: stop the background migration so we don't keep churning
		// when no consumer reads HPPS anymore.
		$this->data_synchronizer->dequeue_background_migration();
	}

	/**
	 * Make sure HPPS tables exist and the background migration is scheduled.
	 *
	 * Idempotent: safe to call repeatedly. The synchronizer caches the
	 * "tables created" check in an option so we don't re-run dbDelta on
	 * every request.
	 *
	 * @return void
	 */
	private function ensure_tables_and_enqueue_migration(): void {
		if ( ! $this->data_synchronizer->check_products_table_exists() ) {
			$this->data_synchronizer->create_database_tables();
		}

		if ( $this->data_synchronizer->has_products_pending_sync() ) {
			$this->data_synchronizer->enqueue_background_migration();
		}
	}

	/**
	 * Self-heal once per request: if the feature is on but the HPPS tables
	 * are missing (typical when the option was flipped before the install
	 * routine had a chance to run), create them on the fly. Cheap when
	 * everything is already in place because the synchronizer reads a cached
	 * option first; the dbDelta side runs at most once per request.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function maybe_self_heal_tables(): void {
		static $attempted = false;
		if ( $attempted ) {
			return;
		}
		if ( ! $this->custom_product_tables_usage_is_enabled() ) {
			return;
		}
		if ( $this->data_synchronizer->get_table_exists() ) {
			return;
		}
		$attempted = true;
		$this->ensure_tables_and_enqueue_migration();
	}

	/*
	|--------------------------------------------------------------------------
	| Admin UX: pending-migration banner, sync handler, Tools page entries.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render a non-dismissable yellow banner on every admin screen while the
	 * feature is on and there are still products to copy across to HPPS.
	 *
	 * The banner mirrors the HPOS pending-sync notice. It appears on every
	 * `wp-admin` page (not just WC pages) so an operator can't miss the fact
	 * that a migration is in flight.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function render_pending_sync_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! $this->custom_product_tables_usage_is_enabled() ) {
			return;
		}
		if ( ! $this->data_synchronizer->get_table_exists() ) {
			return;
		}

		$pending = $this->data_synchronizer->get_pending_count();
		if ( $pending <= 0 ) {
			return;
		}

		$features_page_url = $this->features_controller->get_features_page_url();
		$sync_now_url      = wp_nonce_url(
			add_query_arg( array( self::SYNC_QUERY_ARG => 'true' ), $features_page_url ),
			'hpps-sync-now'
		);

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'High-performance product storage', 'woocommerce' ); ?></strong>
				—
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s is a count of products waiting to be migrated. */
						_n(
							'%s product still needs to be copied into the HPPS tables before storage is fully migrated.',
							'%s products still need to be copied into the HPPS tables before storage is fully migrated.',
							$pending,
							'woocommerce'
						),
						number_format_i18n( $pending )
					)
				);
				?>
				<a class="button button-primary" style="margin-left: 8px;" href="<?php echo esc_url( $sync_now_url ); ?>">
					<?php esc_html_e( 'Sync products now', 'woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the `wc_hpps_sync_now` and `wc_hpps_stop_sync` query args fired
	 * from the Features-page link or the admin notice. Verifies the nonce,
	 * then either kicks off the background processor or removes it.
	 *
	 * Hooked to `woocommerce_sections_advanced` (same as HPOS) so the action
	 * runs while the user is still on the Settings → Advanced screen and
	 * `WC_Admin_Settings::add_*` messages render at the top of the page.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function handle_sync_now_action(): void {
		$section = filter_input( INPUT_GET, 'section' );
		if ( 'features' !== $section ) {
			return;
		}

		if ( filter_input( INPUT_GET, self::SYNC_QUERY_ARG, FILTER_VALIDATE_BOOLEAN ) ) {
			$action = 'sync-now';
		} elseif ( filter_input( INPUT_GET, self::STOP_SYNC_QUERY_ARG, FILTER_VALIDATE_BOOLEAN ) ) {
			$action = 'stop-sync';
		} else {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handled below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, "hpps-{$action}" ) ) {
			WC_Admin_Settings::add_error(
				'sync-now' === $action
					? __( 'Unable to start product sync. The link you followed may have expired.', 'woocommerce' )
					: __( 'Unable to stop product sync. The link you followed may have expired.', 'woocommerce' )
			);
			return;
		}

		if ( 'sync-now' === $action ) {
			if ( ! $this->data_synchronizer->check_products_table_exists() && ! $this->data_synchronizer->create_database_tables() ) {
				WC_Admin_Settings::add_error( __( 'Unable to create the HPPS tables for sync. Check the WooCommerce logs for details.', 'woocommerce' ) );
				return;
			}

			$this->data_synchronizer->enqueue_background_migration();
			WC_Admin_Settings::add_message( __( 'Product sync to HPPS has been queued. It will run in the background via Action Scheduler.', 'woocommerce' ) );
			return;
		}

		$this->data_synchronizer->dequeue_background_migration();
		WC_Admin_Settings::add_message( __( 'Product sync to HPPS has been stopped.', 'woocommerce' ) );
	}

	/**
	 * Strip the sync query args from the URL after the action runs so that a
	 * page refresh doesn't replay the request.
	 *
	 * @internal
	 *
	 * @param array $query_args The query args WP considers removable.
	 * @return array
	 */
	public function register_removable_query_args( array $query_args ): array {
		$query_args[] = self::SYNC_QUERY_ARG;
		$query_args[] = self::STOP_SYNC_QUERY_ARG;
		return $query_args;
	}

	/**
	 * Add HPPS entries to WooCommerce → Status → Tools.
	 *
	 * Three tools:
	 *  - "Sync products to HPPS" — kicks the migration synchronously (small
	 *    batch) so an operator can move work along without waiting for the
	 *    Action Scheduler tick.
	 *  - "Verify HPPS integrity" — quick structural sample diff (legacy CPT
	 *    vs. HPPS) for the first 25 migrated products. Reports any per-field
	 *    mismatches as a flash message and points the operator at the
	 *    `wp wc hpps verify` CLI for the full report.
	 *  - "Delete the HPPS tables" — destructive, only enabled when the
	 *    feature is off and no migration is queued. Mirrors HPOS exactly.
	 *
	 * @internal
	 *
	 * @param array $tools_array Array of tools as built by core.
	 * @return array
	 */
	public function add_hpps_tools( array $tools_array ): array {
		$tools_array['sync_products_to_hpps'] = array(
			'name'             => __( 'Sync products to HPPS', 'woocommerce' ),
			'desc'             => __( 'Copy any products that are still in wp_posts/wp_postmeta into the HPPS tables. Safe to run repeatedly — already-migrated products are skipped.', 'woocommerce' ),
			'requires_refresh' => true,
			'callback'         => function () {
				if ( ! $this->custom_product_tables_usage_is_enabled() ) {
					/* translators: %s is the path to the Features settings page. */
					return sprintf( __( 'HPPS is not enabled. Turn it on under %s before running this tool.', 'woocommerce' ), 'WooCommerce → Settings → Advanced → Features' );
				}
				if ( ! $this->data_synchronizer->check_products_table_exists() && ! $this->data_synchronizer->create_database_tables() ) {
					return __( 'Unable to create the HPPS tables. Check the WooCommerce logs for details.', 'woocommerce' );
				}

				$summary = $this->data_synchronizer->run_synchronously( 50, 20 );
				return sprintf(
					/* translators: 1: products migrated; 2: products skipped; 3: errors; 4: iterations. */
					__( 'Sync completed: %1$d migrated, %2$d skipped, %3$d errors across %4$d iterations.', 'woocommerce' ),
					(int) $summary['migrated'],
					(int) $summary['skipped'],
					(int) $summary['errors'],
					(int) $summary['iterations']
				);
			},
			'button'           => __( 'Sync', 'woocommerce' ),
		);

		$tools_array['verify_hpps_integrity'] = array(
			'name'             => __( 'Verify HPPS integrity', 'woocommerce' ),
			'desc'             => __( 'Quick structural check that compares a sample of migrated products read through the legacy CPT data store with the same products read through HPPS, and reports any per-field mismatches. For a full audit, run <code>wp wc hpps verify</code> from the command line.', 'woocommerce' ),
			'requires_refresh' => true,
			'callback'         => array( $this, 'run_verify_integrity_tool' ),
			'button'           => __( 'Verify', 'woocommerce' ),
		);

		$can_delete = ! $this->custom_product_tables_usage_is_enabled();
		$tools_array['delete_hpps_tables'] = array(
			'name'             => __( 'Delete the HPPS tables', 'woocommerce' ),
			'desc'             => sprintf(
				'<strong class="red">%1$s</strong> %2$s',
				__( 'Note:', 'woocommerce' ),
				$can_delete
					? __( 'Drop every HPPS table. To recreate them, re-enable HPPS under Settings → Advanced → Features.', 'woocommerce' )
					: __( 'HPPS tables can only be deleted when the feature is turned off (Settings → Advanced → Features).', 'woocommerce' )
			),
			'requires_refresh' => true,
			'callback'         => function () use ( $can_delete ) {
				if ( ! $can_delete ) {
					return __( 'HPPS is currently enabled — cannot delete its tables.', 'woocommerce' );
				}
				$this->data_synchronizer->delete_database_tables();
				return __( 'HPPS tables have been deleted.', 'woocommerce' );
			},
			'button'           => __( 'Delete', 'woocommerce' ),
			'disabled'         => ! $can_delete,
		);

		return $tools_array;
	}

	/**
	 * Tools-page callback for "Verify HPPS integrity".
	 *
	 * Runs a 25-product sample diff via {@see ProductMigrationVerifier} and
	 * returns an HTML-safe summary string suitable for the admin notice that
	 * the Tools page renders. Drilling into individual fields is intentionally
	 * delegated to `wp wc hpps verify` — keeps the admin UX terse and the
	 * report formatting in one place.
	 *
	 * @internal Tool callback; rendered into the Tools page admin notice.
	 *
	 * @return string Translated, escaped status message.
	 */
	public function run_verify_integrity_tool(): string {
		if ( ! $this->custom_product_tables_usage_is_enabled() ) {
			return esc_html__( 'HPPS is not enabled. Turn it on under WooCommerce → Settings → Advanced → Features before verifying.', 'woocommerce' );
		}
		if ( ! $this->data_synchronizer->check_products_table_exists() ) {
			return esc_html__( 'HPPS tables are not set up yet — turn the feature on first so they can be created.', 'woocommerce' );
		}

		// Sample size: 25 keeps the request well under the admin-page time
		// budget on a busy box. The CLI is the right tool for whole-catalog
		// audits.
		$report = $this->verifier->verify( array( 'limit' => 25 ) );

		if ( 0 === $report['checked'] ) {
			return esc_html__( 'No products live in the HPPS tables yet. Run "Sync products to HPPS" above first.', 'woocommerce' );
		}

		if ( empty( $report['mismatches'] ) ) {
			return sprintf(
				/* translators: %d: number of products successfully verified. */
				esc_html__( 'Verified %d product(s). All fields match between the legacy CPT data store and HPPS.', 'woocommerce' ),
				(int) $report['checked']
			);
		}

		$preview        = array_slice( $report['mismatches'], 0, 3 );
		$preview_chunks = array();
		foreach ( $preview as $entry ) {
			if ( ! empty( $entry['error'] ) ) {
				$preview_chunks[] = sprintf(
					/* translators: 1: product ID; 2: error message describing why the product could not be loaded. */
					esc_html__( '#%1$d: %2$s', 'woocommerce' ),
					(int) $entry['id'],
					esc_html( (string) $entry['error'] )
				);
				continue;
			}
			$field_names      = array_keys( (array) ( $entry['fields'] ?? array() ) );
			$preview_chunks[] = sprintf(
				/* translators: 1: product ID; 2: comma-separated list of mismatching field names. */
				esc_html__( '#%1$d (%2$s)', 'woocommerce' ),
				(int) $entry['id'],
				esc_html( implode( ', ', $field_names ) )
			);
		}

		// Use one composite message rather than two paragraphs because the
		// admin notice container collapses double <br> sequences.
		return sprintf(
			/* translators: 1: number of mismatched products; 2: number of products checked; 3: comma-separated preview of the first mismatched products; 4: <code>wp wc hpps verify</code>. */
			esc_html__( 'Found %1$d mismatched product(s) out of %2$d checked. First mismatches: %3$s. Run %4$s for the full diff.', 'woocommerce' ),
			count( $report['mismatches'] ),
			(int) $report['checked'],
			implode( '; ', $preview_chunks ),
			'<code>wp wc hpps verify</code>'
		);
	}

	/**
	 * Register the placeholder post type.
	 *
	 * The placeholder is non-public, hidden from search, has no permalink and
	 * registers no UI. Its only job is to give us a wp_posts row (and therefore
	 * an ID) for a new product without triggering the CPT hooks that listeners
	 * for `product` rely on.
	 *
	 * @return void
	 */
	public function register_placeholder_post_type(): void {
		register_post_type(
			self::PLACEHOLDER_POST_TYPE,
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'capabilities'        => array(),
				'capability_type'     => 'post',
			)
		);
	}

	/**
	 * Add the HPPS feature definition to the Features Controller.
	 *
	 * Called from FeaturesController::get_feature_definitions(), in the same
	 * lazy section that registers HPOS, to avoid container init loops.
	 *
	 * @internal For exclusive usage of WooCommerce core.
	 *
	 * @param FeaturesController $features_controller The features controller instance.
	 * @return void
	 */
	public function add_feature_definition( FeaturesController $features_controller ): void {
		$definition = array(
			'option_key'                   => self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION,
			'is_experimental'              => true,
			'enabled_by_default'           => false,
			'order'                        => 51,
			'setting'                      => $this->get_hpps_setting_for_feature(),
			'default_plugin_compatibility' => FeaturePluginCompatibility::INCOMPATIBLE,
			'additional_settings'          => array(
				$this->get_hpps_setting_for_sync(),
			),
		);

		$features_controller->add_feature_definition(
			self::FEATURE_ID,
			__( 'High-Performance product storage', 'woocommerce' ),
			$definition
		);
	}

	/**
	 * Whether the HPPS option is enabled.
	 *
	 * @return bool
	 */
	public function custom_product_tables_usage_is_enabled(): bool {
		return 'yes' === get_option( self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION );
	}

	/**
	 * Filter callback that swaps the data store class when HPPS is enabled.
	 *
	 * Returns the legacy store untouched when the feature is disabled so a
	 * site can flip back without restart. When enabled, routes each
	 * `woocommerce_product*_data_store` filter to the matching HPPS
	 * subclass:
	 *
	 *  - `woocommerce_product_data_store` and `woocommerce_product-external_data_store`
	 *    → ProductsTableDataStore
	 *  - `woocommerce_product-variable_data_store`
	 *    → ProductsTableVariableDataStore
	 *  - `woocommerce_product-variation_data_store`
	 *    → ProductsTableVariationDataStore
	 *  - `woocommerce_product-grouped_data_store`
	 *    → ProductsTableGroupedDataStore
	 *
	 * The router uses `current_filter()` because the `woocommerce_*_data_store`
	 * filters all pass the same shape; we'd otherwise need four near-identical
	 * callbacks.
	 *
	 * @param mixed $current_data_store Current data store class name or instance.
	 * @return mixed
	 */
	public function filter_product_data_store( $current_data_store ) {
		if ( ! $this->custom_product_tables_usage_is_enabled() ) {
			return $current_data_store;
		}

		// Belt-and-braces: if the HPPS tables don't physically exist (option
		// flipped before install ran), fall back to the legacy data store
		// rather than letting reads explode against missing tables. The
		// option-flip handler and `init`-time self-heal hook usually create
		// the tables, but if creation is still pending or failed (permissions,
		// race) we stay on the safe path.
		$this->maybe_self_heal_tables();
		if ( ! $this->data_synchronizer->get_table_exists() ) {
			return $current_data_store;
		}

		// Authoritative-source filter: extensions can flip reads back to the
		// legacy CPT store while HPPS continues to receive writes (Phase 2
		// dual-mode cutover). The default value is `'hpps'`.
		if ( 'hpps' !== $this->data_synchronizer->authoritative_source() ) {
			return $current_data_store;
		}

		switch ( current_filter() ) {
			case 'woocommerce_product-variable_data_store':
				return $this->variable_data_store;
			case 'woocommerce_product-variation_data_store':
				return $this->variation_data_store;
			case 'woocommerce_product-grouped_data_store':
				return $this->grouped_data_store;
			case 'woocommerce_product-external_data_store':
			case 'woocommerce_product_data_store':
			default:
				return $this->simple_data_store;
		}
	}

	/**
	 * Build the inline status string that appears under the HPPS radio on
	 * the Features page. Returns an empty string when no status applies
	 * (feature off and tables absent).
	 *
	 * Three states are reported:
	 *  - Migration finished cleanly.
	 *  - Migration in progress (with a "Stop sync" link).
	 *  - Migration pending (with a "Sync now" link).
	 *
	 * @return string HTML-safe status string, or empty.
	 */
	private function build_sync_status_description(): string {
		if ( ! isset( $this->data_synchronizer ) ) {
			return '';
		}
		if ( ! $this->data_synchronizer->get_table_exists() ) {
			return '';
		}

		$pending = $this->data_synchronizer->get_pending_count();
		if ( $pending <= 0 ) {
			return esc_html__( 'All products are synchronised with the HPPS tables.', 'woocommerce' );
		}

		$features_page_url = $this->features_controller->get_features_page_url();
		$sync_now_url      = wp_nonce_url(
			add_query_arg( array( self::SYNC_QUERY_ARG => 'true' ), $features_page_url ),
			'hpps-sync-now'
		);
		$stop_sync_url     = wp_nonce_url(
			add_query_arg( array( self::STOP_SYNC_QUERY_ARG => 'true' ), $features_page_url ),
			'hpps-stop-sync'
		);

		$lines = array();
		if ( $this->data_synchronizer->is_background_migration_enqueued() ) {
			$lines[] = sprintf(
				/* translators: %s: pending product count. */
				esc_html__( 'Currently syncing products to HPPS. %s pending.', 'woocommerce' ),
				esc_html( number_format_i18n( $pending ) )
			);
			$lines[] = sprintf(
				'<a href="%1$s" class="button-link">%2$s</a>',
				esc_url( $stop_sync_url ),
				esc_html__( 'Stop sync', 'woocommerce' )
			);
		} else {
			$lines[] = sprintf(
				/* translators: %s: pending product count. */
				esc_html__( '%s products still need to be migrated to the HPPS tables.', 'woocommerce' ),
				esc_html( number_format_i18n( $pending ) )
			);
			$lines[] = sprintf(
				'<a href="%1$s" class="button-link">%2$s</a>',
				esc_url( $sync_now_url ),
				esc_html__( 'Sync products now', 'woocommerce' )
			);
		}

		return implode( ' ', $lines );
	}

	/**
	 * Build the radio-button setting block displayed under
	 * WooCommerce > Settings > Advanced > Features.
	 *
	 * @return array Feature setting object compatible with FeaturesController.
	 */
	private function get_hpps_setting_for_feature(): array {
		if ( 'yes' === get_transient( 'wc_installing' ) ) {
			return array();
		}

		$get_value = function () {
			return $this->custom_product_tables_usage_is_enabled() ? 'yes' : 'no';
		};

		// The FeaturesController instance is captured in closures so that we
		// don't trigger circular DI during registration. See HPOS for the same
		// pattern.
		$get_desc = function () {
			$plugin_compatibility = $this->features_controller->get_compatible_plugins_for_feature( self::FEATURE_ID, true );
			$warning              = $this->plugin_util->generate_incompatible_plugin_feature_warning( self::FEATURE_ID, $plugin_compatibility );

			$status = $this->build_sync_status_description();
			if ( '' === $status ) {
				return $warning;
			}
			if ( '' === $warning ) {
				return $status;
			}
			return $warning . '<br />' . $status;
		};

		$get_disabled = function () {
			$compatibility_info   = $this->features_controller->get_compatible_plugins_for_feature( self::FEATURE_ID, true );
			$incompatible_plugins = $this->plugin_util->get_items_considered_incompatible( self::FEATURE_ID, $compatibility_info );
			$incompatible_plugins = array_diff( $incompatible_plugins, $this->plugin_util->get_plugins_excluded_from_compatibility_ui() );

			return count( $incompatible_plugins ) > 0 ? array( 'yes' ) : array();
		};

		return array(
			'id'          => self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION,
			'title'       => __( 'Product data storage', 'woocommerce' ),
			'type'        => 'radio',
			'options'     => array(
				'no'  => __( 'WordPress posts storage (legacy)', 'woocommerce' ),
				'yes' => __( 'High-performance product storage (experimental)', 'woocommerce' ),
			),
			'value'       => $get_value,
			'disabled'    => $get_disabled,
			'desc'        => $get_desc,
			'desc_at_end' => true,
			'row_class'   => self::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION,
		);
	}

	/**
	 * Build the "Enable compatibility mode" checkbox displayed under the
	 * HPPS radio on the Features page.
	 *
	 * Wraps {@see ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION},
	 * which in turn drives {@see ProductDataSyncListener}: with the
	 * checkbox on, postmeta writes mirror into the wc_products columns
	 * and HPPS-side saves push values back into postmeta. Mirrors the
	 * "compatibility mode" toggle HPOS exposes for orders so operators
	 * coming from the orders side find the same lever in the same place.
	 *
	 * @return array Feature setting object compatible with FeaturesController.
	 */
	private function get_hpps_setting_for_sync(): array {
		if ( 'yes' === get_transient( 'wc_installing' ) ) {
			return array();
		}

		$get_value = function (): string {
			return 'yes' === get_option( ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION, 'no' ) ? 'yes' : 'no';
		};

		$get_desc_tip = function (): string {
			// Nothing useful to say while HPPS itself is off — the option
			// persists but has no observable effect.
			if ( ! $this->custom_product_tables_usage_is_enabled() ) {
				return esc_html__( 'Has no effect while High-performance product storage is disabled.', 'woocommerce' );
			}

			// Migration still pending: surface it the same way the HPOS
			// sync row does, with a "Sync now" link. Operators usually
			// expect to flip compatibility mode on AFTER the back-fill
			// finishes, but the option itself is safe either way.
			if ( ! isset( $this->data_synchronizer ) ) {
				return '';
			}
			if ( $this->data_synchronizer->has_products_pending_sync() ) {
				$sync_now_url = wp_nonce_url(
					add_query_arg( array( self::SYNC_QUERY_ARG => 'true' ), $this->features_controller->get_features_page_url() ),
					'hpps-sync-now'
				);

				return wp_kses_post(
					sprintf(
						/* translators: %s: HTML link to "Sync products now". */
						__( 'There are products still pending migration to the HPPS tables. %s', 'woocommerce' ),
						sprintf(
							'<a href="%1$s" class="button-link">%2$s</a>',
							esc_url( $sync_now_url ),
							esc_html__( 'Sync products now', 'woocommerce' )
						)
					)
				);
			}

			if ( $this->data_synchronizer->data_sync_is_enabled() ) {
				return esc_html__( 'Compatibility mode is on. Postmeta writes mirror into the HPPS tables, and HPPS saves mirror back into postmeta.', 'woocommerce' );
			}

			return esc_html__( 'Without compatibility mode, plugins that read or write product data via wp_postmeta directly may see stale values.', 'woocommerce' );
		};

		return array(
			'id'        => ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION,
			'title'     => '',
			'type'      => 'checkbox',
			'desc'      => __( 'Enable compatibility mode (Synchronize products between High-performance product storage and WordPress posts storage).', 'woocommerce' ),
			'value'     => $get_value,
			'desc_tip'  => $get_desc_tip,
			'row_class' => ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION,
		);
	}
}
