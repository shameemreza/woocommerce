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
	 */
	final public function init(
		FeaturesController $features_controller,
		PluginUtil $plugin_util,
		ProductsTableDataStore $simple_data_store,
		ProductsTableVariableDataStore $variable_data_store,
		ProductsTableVariationDataStore $variation_data_store,
		ProductsTableGroupedDataStore $grouped_data_store,
		ProductDataSynchronizer $data_synchronizer
	): void {
		$this->features_controller  = $features_controller;
		$this->plugin_util          = $plugin_util;
		$this->simple_data_store    = $simple_data_store;
		$this->variable_data_store  = $variable_data_store;
		$this->variation_data_store = $variation_data_store;
		$this->grouped_data_store   = $grouped_data_store;
		$this->data_synchronizer    = $data_synchronizer;
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
			return $this->plugin_util->generate_incompatible_plugin_feature_warning( self::FEATURE_ID, $plugin_compatibility );
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
}
