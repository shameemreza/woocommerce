<?php
/**
 * HPPS CLI runner.
 *
 * @package WooCommerce\Database\Migrations\CustomProductsTable
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Database\Migrations\CustomProductsTable;

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableGroupedDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariationDataStore;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Factory;
use WC_Product_Grouped_Data_Store_CPT;
use WC_Product_Variable_Data_Store_CPT;
use WC_Product_Variation_Data_Store_CPT;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp wc hpps ...` commands for the High-Performance Product Storage feature.
 *
 * Surfaces:
 *
 *  - `wp wc hpps status`              — print pending count + feature flag.
 *  - `wp wc hpps enable` / `disable`  — flip the feature option.
 *  - `wp wc hpps migrate [--batch-size=<n>]` — run the migration to
 *    completion in the foreground.
 *  - `wp wc hpps verify`              — basic post/wc_products row-count
 *    parity check.
 *
 * Mirrors the UX of `wp wc hpos` so operators don't have to learn a new
 * command surface.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class CLIRunner {

	/**
	 * Synchronizer dependency.
	 *
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $synchronizer;

	/**
	 * HPPS controller dependency.
	 *
	 * @var CustomProductsTableController
	 */
	private CustomProductsTableController $controller;

	/**
	 * HPPS data stores keyed by product type. Used by `verify` to read
	 * directly through the HPPS path without going through the
	 * woocommerce_product*_data_store filter chain.
	 *
	 * @var array<string, ProductsTableDataStore>
	 */
	private array $hpps_stores = array();

	/**
	 * Inject dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param ProductDataSynchronizer         $synchronizer         Synchronizer.
	 * @param CustomProductsTableController   $controller           Controller.
	 * @param ProductsTableDataStore          $simple_store         HPPS simple/external store.
	 * @param ProductsTableVariableDataStore  $variable_store       HPPS variable store.
	 * @param ProductsTableVariationDataStore $variation_store      HPPS variation store.
	 * @param ProductsTableGroupedDataStore   $grouped_store        HPPS grouped store.
	 */
	final public function init(
		ProductDataSynchronizer $synchronizer,
		CustomProductsTableController $controller,
		ProductsTableDataStore $simple_store,
		ProductsTableVariableDataStore $variable_store,
		ProductsTableVariationDataStore $variation_store,
		ProductsTableGroupedDataStore $grouped_store
	): void {
		$this->synchronizer = $synchronizer;
		$this->controller   = $controller;
		$this->hpps_stores  = array(
			'simple'    => $simple_store,
			'external'  => $simple_store,
			'variable'  => $variable_store,
			'variation' => $variation_store,
			'grouped'   => $grouped_store,
		);
	}

	/**
	 * Register the WP-CLI commands.
	 *
	 * @return void
	 */
	public function register_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'wc hpps status', array( $this, 'status' ) );
		WP_CLI::add_command( 'wc hpps enable', array( $this, 'enable' ) );
		WP_CLI::add_command( 'wc hpps disable', array( $this, 'disable' ) );
		WP_CLI::add_command( 'wc hpps migrate', array( $this, 'migrate' ) );
		WP_CLI::add_command( 'wc hpps verify', array( $this, 'verify' ) );
	}

	/**
	 * `wp wc hpps status` — pending count + feature flag.
	 *
	 * @return void
	 */
	public function status(): void {
		$pending = $this->synchronizer->get_pending_count();
		$enabled = $this->controller->custom_product_tables_usage_is_enabled();

		WP_CLI::log( sprintf( 'HPPS feature enabled: %s', $enabled ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Products pending migration: %d', $pending ) );
		WP_CLI::log( sprintf( 'Migration option: %s', (string) get_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION, 'pending' ) ) );

		if ( $enabled && $pending > 0 ) {
			WP_CLI::warning( 'HPPS is enabled but there are unmigrated products. Run `wp wc hpps migrate`.' );
		} elseif ( ! $enabled && 0 === $pending ) {
			WP_CLI::log( 'All products migrated. You can enable HPPS with `wp wc hpps enable`.' );
		}
	}

	/**
	 * `wp wc hpps enable` — flip the feature option to "yes".
	 *
	 * @return void
	 */
	public function enable(): void {
		update_option( CustomProductsTableController::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, 'yes' );
		WP_CLI::success( 'HPPS feature enabled.' );

		if ( $this->synchronizer->has_products_pending_sync() ) {
			WP_CLI::warning( 'There are still products in the legacy storage. Run `wp wc hpps migrate` to finish moving them.' );
		}
	}

	/**
	 * `wp wc hpps disable` — flip the feature option to "no".
	 *
	 * @return void
	 */
	public function disable(): void {
		update_option( CustomProductsTableController::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, 'no' );
		WP_CLI::success( 'HPPS feature disabled. The legacy CPT data store is back in use.' );
	}

	/**
	 * `wp wc hpps migrate [--batch-size=<n>] [--max-iterations=<n>]`
	 *
	 * @param array<int, string>    $args        Positional args (unused).
	 * @param array<string, string> $assoc_args  Associative args.
	 * @return void
	 */
	public function migrate( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );

		$batch_size     = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 50;
		$max_iterations = isset( $assoc_args['max-iterations'] ) ? max( 1, (int) $assoc_args['max-iterations'] ) : 10000;

		$pending = $this->synchronizer->get_pending_count();
		if ( $pending <= 0 ) {
			WP_CLI::success( 'Nothing to migrate. All products already live in wc_products.' );
			return;
		}

		WP_CLI::log( sprintf( 'Migrating %d products in batches of %d...', $pending, $batch_size ) );
		$progress = WP_CLI\Utils\make_progress_bar( 'Migrating', $pending );

		$migrated_total = 0;
		$skipped_total  = 0;
		$errors_total   = 0;
		$iterations     = 0;

		$migrator = wc_get_container()
			->get( PostsToProductsMigrationController::class )
			->get_migrator();

		while ( $iterations < $max_iterations ) {
			++$iterations;

			$batch = wc_get_container()
				->get( PostsToProductsMigrationController::class )
				->get_next_batch_to_process( $batch_size );

			if ( empty( $batch ) ) {
				break;
			}

			$result          = $migrator->migrate_products( $batch );
			$migrated_total += count( $result['migrated'] );
			$skipped_total  += count( $result['skipped'] );
			$errors_total   += count( $result['errors'] );

			$progress->tick( count( $batch ) );

			if ( ! empty( $result['errors'] ) ) {
				$progress->finish();
				foreach ( $result['errors'] as $product_id => $message ) {
					WP_CLI::warning( sprintf( 'Product %d failed: %s', (int) $product_id, $message ) );
				}
				WP_CLI::error( 'Migration stopped after errors. Inspect the log source `posts-to-products-migration` and re-run.' );
				return;
			}
		}

		$progress->finish();
		update_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION, 'done' );

		WP_CLI::success(
			sprintf(
				'Done. Migrated: %d, skipped (already migrated): %d, errors: %d, batches: %d.',
				$migrated_total,
				$skipped_total,
				$errors_total,
				$iterations
			)
		);
	}

	/**
	 * `wp wc hpps verify [--id=<id>] [--limit=<n>] [--ignore=<csv>] [--format=<table|json>]`
	 *
	 * Cross-checks each migrated product by reading it once through the
	 * legacy CPT data store and once through the HPPS data store, then
	 * diffing the resulting WC_Product property arrays. The CPT side is
	 * the source of truth; HPPS is the candidate.
	 *
	 * Useful as a post-migration audit, and as a regression check before
	 * flipping the feature on for real traffic.
	 *
	 * Default sample is the first 100 wc_products rows. Pass `--id=<id>`
	 * to verify a single product or `--limit=0` to verify everything (slow
	 * on large catalogs).
	 *
	 * @param array<int,string>     $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function verify( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );

		// First, the row-count summary for context.
		$this->print_count_summary();

		$single_id = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;
		$limit     = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 100;
		$format    = $assoc_args['format'] ?? 'table';
		$ignore    = isset( $assoc_args['ignore'] )
			? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['ignore'] ) ) )
			: array( 'date_modified', 'date_modified_gmt' );

		$ids = $single_id > 0
			? array( $single_id )
			: $this->fetch_hpps_product_ids( $limit );

		if ( empty( $ids ) ) {
			WP_CLI::log( 'No HPPS products to verify yet.' );
			return;
		}

		WP_CLI::log( sprintf( 'Verifying %d product(s)...', count( $ids ) ) );

		$mismatches = array();
		$progress   = WP_CLI\Utils\make_progress_bar( 'Verifying', count( $ids ) );

		foreach ( $ids as $product_id ) {
			$diff = $this->diff_product( (int) $product_id, $ignore );
			if ( null === $diff ) {
				$progress->tick();
				continue;
			}
			if ( ! empty( $diff['fields'] ) || ! empty( $diff['error'] ) ) {
				$mismatches[] = $diff;
			}
			$progress->tick();
		}

		$progress->finish();

		if ( empty( $mismatches ) ) {
			WP_CLI::success( sprintf( 'All %d product(s) match between legacy and HPPS storage.', count( $ids ) ) );
			return;
		}

		WP_CLI::warning( sprintf( 'Found %d mismatched product(s) out of %d checked.', count( $mismatches ), count( $ids ) ) );

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $mismatches, JSON_PRETTY_PRINT ) );
			WP_CLI::halt( 1 );
		}

		$rows = array();
		foreach ( $mismatches as $entry ) {
			if ( ! empty( $entry['error'] ) ) {
				$rows[] = array(
					'id'       => $entry['id'],
					'field'    => '(error)',
					'cpt'      => '',
					'hpps'     => $entry['error'],
				);
				continue;
			}
			foreach ( $entry['fields'] as $field => $values ) {
				$rows[] = array(
					'id'    => $entry['id'],
					'field' => $field,
					'cpt'   => $this->stringify_for_diff( $values['cpt'] ),
					'hpps'  => $this->stringify_for_diff( $values['hpps'] ),
				);
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'field', 'cpt', 'hpps' ) );
		WP_CLI::halt( 1 );
	}

	/**
	 * Print the wp_posts vs wc_products row-count summary used by both
	 * `status` and `verify`.
	 *
	 * @return void
	 */
	private function print_count_summary(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from a constant getter and is not user input.
		$products_table = ProductsTableDataStore::get_products_table_name();

		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					( SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( %s, %s ) AND post_status NOT IN ( %s, %s ) ) AS posts,
					( SELECT COUNT(*) FROM {$products_table} ) AS hpps",
				'product',
				'product_variation',
				'auto-draft',
				'inherit'
			)
		);

		WP_CLI::log( sprintf( 'wp_posts (product / product_variation): %d', (int) ( $counts->posts ?? 0 ) ) );
		WP_CLI::log( sprintf( 'wc_products: %d', (int) ( $counts->hpps ?? 0 ) ) );
		WP_CLI::log( sprintf( 'Posts not yet in wc_products: %d', $this->synchronizer->get_pending_count() ) );
	}

	/**
	 * Pull a sample of product IDs that exist in the HPPS table. We take
	 * the first `$limit` ordered by ID so the result is deterministic and
	 * useful for narrow follow-ups (re-run with `--id=<id>` after fixing).
	 *
	 * @param int $limit Maximum rows to return; 0 means everything.
	 * @return int[]
	 */
	private function fetch_hpps_product_ids( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from a constant getter and is not user input.
		$products_table = ProductsTableDataStore::get_products_table_name();

		if ( $limit > 0 ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$products_table} ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_col(
				"SELECT id FROM {$products_table} ORDER BY id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Diff one product's WC_Product properties between legacy CPT and HPPS.
	 *
	 * Returns:
	 *   - null if no diff (product matches),
	 *   - array<string, mixed> with `id`, `fields` (per-field cpt/hpps values), `error`
	 *     when the product can't be loaded.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $ignore     Field names to skip.
	 * @return array<string, mixed>|null
	 */
	private function diff_product( int $product_id, array $ignore ): ?array {
		try {
			$legacy = $this->load_product_via_legacy( $product_id );
			$hpps   = $this->load_product_via_hpps( $product_id );
		} catch ( \Throwable $e ) {
			return array(
				'id'    => $product_id,
				'error' => $e->getMessage(),
			);
		}

		if ( null === $legacy && null === $hpps ) {
			return null;
		}
		if ( null === $legacy || null === $hpps ) {
			return array(
				'id'    => $product_id,
				'error' => null === $legacy ? 'Product missing from legacy storage.' : 'Product missing from HPPS storage.',
			);
		}

		$legacy_data = $legacy->get_data();
		$hpps_data   = $hpps->get_data();

		$fields = array();
		foreach ( $legacy_data as $field => $value ) {
			if ( in_array( $field, $ignore, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $field, $hpps_data ) ) {
				continue;
			}

			$legacy_value = $this->normalize_for_diff( $value );
			$hpps_value   = $this->normalize_for_diff( $hpps_data[ $field ] );
			if ( $legacy_value === $hpps_value ) {
				continue;
			}

			$fields[ $field ] = array(
				'cpt'  => $legacy_value,
				'hpps' => $hpps_value,
			);
		}

		if ( empty( $fields ) ) {
			return null;
		}

		return array(
			'id'     => $product_id,
			'fields' => $fields,
		);
	}

	/**
	 * Build a fresh WC_Product (correct subclass) and read it via the
	 * legacy CPT data store, regardless of whether HPPS is the active
	 * data store globally.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	private function load_product_via_legacy( int $product_id ): ?WC_Product {
		$type = $this->resolve_product_type( $product_id, 'cpt' );
		if ( '' === $type ) {
			return null;
		}

		$class = WC_Product_Factory::get_classname_from_product_type( $type );
		if ( ! $class || ! class_exists( $class ) ) {
			return null;
		}

		$product = new $class( 0 );
		$product->set_id( $product_id );

		$store = $this->resolve_legacy_store( $type );
		$store->read( $product );

		return $product;
	}

	/**
	 * Same as {@see load_product_via_legacy()} but reads through the
	 * HPPS data store. Returns null when the row doesn't exist in the
	 * wc_products table.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	private function load_product_via_hpps( int $product_id ): ?WC_Product {
		$type = $this->resolve_product_type( $product_id, 'hpps' );
		if ( '' === $type ) {
			return null;
		}

		$class = WC_Product_Factory::get_classname_from_product_type( $type );
		if ( ! $class || ! class_exists( $class ) ) {
			return null;
		}

		$product = new $class( 0 );
		$product->set_id( $product_id );

		$store = $this->hpps_stores[ $type ] ?? $this->hpps_stores['simple'];
		$store->read( $product );

		return $product;
	}

	/**
	 * Look up the product type from the requested side. We can't ask the
	 * data store layer here because either side may be the active filter
	 * target; instead we read directly from the underlying tables.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $side       'cpt' or 'hpps'.
	 * @return string Empty string if the product is not present on that side.
	 */
	private function resolve_product_type( int $product_id, string $side ): string {
		global $wpdb;

		if ( 'hpps' === $side ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from a constant getter and is not user input.
			$products_table = ProductsTableDataStore::get_products_table_name();
			$type           = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT type FROM {$products_table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$product_id
				)
			);
			return is_string( $type ) ? $type : '';
		}

		// CPT side: ask the legacy CPT data store directly so we don't
		// route through the woocommerce_product_data_store filter (which
		// would point at HPPS when the feature is on). The CPT store
		// reads from the product_type taxonomy and from wp_posts.post_type
		// for variations.
		$type = ( new WC_Product_Data_Store_CPT() )->get_product_type( $product_id );
		return is_string( $type ) ? $type : '';
	}

	/**
	 * Resolve a fresh CPT data store instance for the given product type.
	 *
	 * @param string $type Product type (`simple`, `variable`, `variation`, `grouped`, `external`).
	 * @return WC_Product_Data_Store_CPT
	 */
	private function resolve_legacy_store( string $type ): WC_Product_Data_Store_CPT {
		switch ( $type ) {
			case 'variable':
				return new WC_Product_Variable_Data_Store_CPT();
			case 'variation':
				return new WC_Product_Variation_Data_Store_CPT();
			case 'grouped':
				return new WC_Product_Grouped_Data_Store_CPT();
			default:
				return new WC_Product_Data_Store_CPT();
		}
	}

	/**
	 * Reduce a property value to a stable shape so structurally equal
	 * values compare equal. Primarily handles arrays whose key order or
	 * nested datetime objects would otherwise produce false positives.
	 *
	 * @param mixed $value Raw property value.
	 * @return mixed
	 */
	private function normalize_for_diff( $value ) {
		if ( $value instanceof \WC_DateTime ) {
			return $value->getTimestamp();
		}
		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $k => $v ) {
				$normalized[ $k ] = $this->normalize_for_diff( $v );
			}
			ksort( $normalized );
			return $normalized;
		}
		if ( is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return $value;
	}

	/**
	 * Render a normalized value as a single line for the table format.
	 *
	 * @param mixed $value Normalized value.
	 * @return string
	 */
	private function stringify_for_diff( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) ( $value ?? 'null' );
		}
		$encoded = wp_json_encode( $value );
		if ( false === $encoded ) {
			return '(unencodable)';
		}
		return strlen( $encoded ) > 80 ? substr( $encoded, 0, 77 ) . '...' : $encoded;
	}
}
