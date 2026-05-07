<?php
/**
 * ProductsTableDataStore class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\CatalogVisibility;
use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use Automattic\WooCommerce\Internal\Utilities\DatabaseUtil;
use Automattic\WooCommerce\Utilities\NumberUtil;
use Exception;
use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Data_Store_CPT;
use WC_Product_Download;
use WC_Product_Data_Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Data store for products using custom HPPS tables.
 *
 * Replaces WC_Product_Data_Store_CPT when the custom_product_tables feature is
 * enabled. Reads and writes a normalised set of tables instead of
 * wp_posts + wp_postmeta:
 *   - wc_products: core product columns (single-row read).
 *   - wc_product_attributes / wc_product_attribute_values: attribute storage,
 *     scoped to product or variation.
 *   - wc_product_downloads: download file definitions.
 *   - wc_products_meta: extension data and overflow.
 *
 * A wp_posts row of type `product_placeholder` is created alongside each
 * product to keep IDs aligned with WordPress' allocator (Phase 2 sync needs
 * this) without firing CPT save hooks. See HPPS-review-and-plan.md issue #2.
 *
 * Backwards-compat is preserved by also writing to `wc_product_meta_lookup`
 * after every change so the dozens of legacy callers (sorting, filtering,
 * analytics) keep returning correct data once HPPS is on.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductsTableDataStore extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface, WC_Product_Data_Store_Interface {

	/**
	 * Meta data store used by HPPS.
	 *
	 * @var ProductsTableDataStoreMeta
	 */
	protected $meta_data_store;

	/**
	 * Database utility helper from core.
	 *
	 * @var DatabaseUtil
	 */
	protected $database_util;

	/**
	 * Lazily-built legacy CPT data store used as a fall-back for products that
	 * have not yet been migrated to HPPS.
	 *
	 * Until the one-time CPT → HPPS migration finishes, the wp_posts /
	 * wp_postmeta data is still authoritative for legacy products. Reads
	 * (and writes) for products without a row in `wc_products` are delegated
	 * to this instance so the admin/storefront keeps working seamlessly while
	 * migration runs in the background.
	 *
	 * @var WC_Product_Data_Store_CPT|null
	 */
	private $legacy_data_store = null;

	/**
	 * Column-to-property mapping for the wc_products table.
	 *
	 * Each entry maps a column name to:
	 *  - 'name': WC_Product property name (drives get_NAME/set_NAME method names).
	 *  - 'type': PHP type used by the converters (int, string, decimal, bool, date).
	 *
	 * Adding a new column here is the only place to register a new core field;
	 * the schema generator, hydrator, and update path all read from this map.
	 *
	 * @var array<string, array{name:string,type:string}>
	 */
	protected $product_column_mapping = array(
		'id'                 => array(
			'name' => 'id',
			'type' => 'int',
		),
		'type'               => array(
			'name' => 'type',
			'type' => 'string',
		),
		'status'             => array(
			'name' => 'status',
			'type' => 'string',
		),
		'name'               => array(
			'name' => 'name',
			'type' => 'string',
		),
		'slug'               => array(
			'name' => 'slug',
			'type' => 'string',
		),
		'sku'                => array(
			'name' => 'sku',
			'type' => 'string',
		),
		'global_unique_id'   => array(
			'name' => 'global_unique_id',
			'type' => 'string',
		),
		'description'        => array(
			'name' => 'description',
			'type' => 'string',
		),
		'short_description'  => array(
			'name' => 'short_description',
			'type' => 'string',
		),
		'parent_id'          => array(
			'name' => 'parent_id',
			'type' => 'int',
		),
		'menu_order'         => array(
			'name' => 'menu_order',
			'type' => 'int',
		),
		'date_created_gmt'   => array(
			'name' => 'date_created',
			'type' => 'date',
		),
		'date_modified_gmt'  => array(
			'name' => 'date_modified',
			'type' => 'date',
		),
		'price'              => array(
			'name' => 'price',
			'type' => 'decimal',
		),
		'regular_price'      => array(
			'name' => 'regular_price',
			'type' => 'decimal',
		),
		'sale_price'         => array(
			'name' => 'sale_price',
			'type' => 'decimal',
		),
		'date_on_sale_from'  => array(
			'name' => 'date_on_sale_from',
			'type' => 'date',
		),
		'date_on_sale_to'    => array(
			'name' => 'date_on_sale_to',
			'type' => 'date',
		),
		'tax_status'         => array(
			'name' => 'tax_status',
			'type' => 'string',
		),
		'tax_class'          => array(
			'name' => 'tax_class',
			'type' => 'string',
		),
		'manage_stock'       => array(
			'name' => 'manage_stock',
			'type' => 'bool',
		),
		'stock_quantity'     => array(
			'name' => 'stock_quantity',
			'type' => 'decimal',
		),
		'stock_status'       => array(
			'name' => 'stock_status',
			'type' => 'string',
		),
		'backorders'         => array(
			'name' => 'backorders',
			'type' => 'string',
		),
		'low_stock_amount'   => array(
			'name' => 'low_stock_amount',
			'type' => 'int',
		),
		'sold_individually'  => array(
			'name' => 'sold_individually',
			'type' => 'bool',
		),
		'weight'             => array(
			'name' => 'weight',
			'type' => 'string',
		),
		'length'             => array(
			'name' => 'length',
			'type' => 'string',
		),
		'width'              => array(
			'name' => 'width',
			'type' => 'string',
		),
		'height'             => array(
			'name' => 'height',
			'type' => 'string',
		),
		'virtual'            => array(
			'name' => 'virtual',
			'type' => 'bool',
		),
		'downloadable'       => array(
			'name' => 'downloadable',
			'type' => 'bool',
		),
		'featured'           => array(
			'name' => 'featured',
			'type' => 'bool',
		),
		'catalog_visibility' => array(
			'name' => 'catalog_visibility',
			'type' => 'string',
		),
		'reviews_allowed'    => array(
			'name' => 'reviews_allowed',
			'type' => 'bool',
		),
		'total_sales'        => array(
			'name' => 'total_sales',
			'type' => 'int',
		),
		'average_rating'     => array(
			'name' => 'average_rating',
			'type' => 'decimal',
		),
		'rating_count'       => array(
			'name' => 'rating_count',
			'type' => 'int',
		),
		'review_count'       => array(
			'name' => 'review_count',
			'type' => 'int',
		),
		'download_limit'     => array(
			'name' => 'download_limit',
			'type' => 'int',
		),
		'download_expiry'    => array(
			'name' => 'download_expiry',
			'type' => 'int',
		),
		'purchase_note'      => array(
			'name' => 'purchase_note',
			'type' => 'string',
		),
		'post_password'      => array(
			'name' => 'post_password',
			'type' => 'string',
		),
		'image_id'           => array(
			'name' => 'image_id',
			'type' => 'int',
		),
		'gallery_image_ids'  => array(
			'name' => 'gallery_image_ids',
			'type' => 'string',
		),
		'product_url'        => array(
			'name' => 'product_url',
			'type' => 'string',
		),
		'button_text'        => array(
			'name' => 'button_text',
			'type' => 'string',
		),
		'cogs_value'         => array(
			'name' => 'cogs_value',
			'type' => 'decimal',
		),
	);

	/**
	 * Properties that are hydrated from subtables or taxonomy and therefore
	 * skipped during column-driven hydration / column-driven persistence.
	 *
	 * @var string[]
	 */
	private const SUBTABLE_PROPERTIES = array(
		'category_ids',
		'tag_ids',
		'brand_ids',
		'shipping_class_id',
		'attributes',
		'default_attributes',
		'downloads',
		'children',
		'upsell_ids',
		'cross_sell_ids',
	);

	/**
	 * Initialise class dependencies.
	 *
	 * @internal
	 *
	 * @param ProductsTableDataStoreMeta $meta_data_store Meta data store.
	 * @param DatabaseUtil               $database_util   Database utility helper.
	 */
	final public function init(
		ProductsTableDataStoreMeta $meta_data_store,
		DatabaseUtil $database_util
	): void {
		$this->meta_data_store = $meta_data_store;
		$this->database_util   = $database_util;
	}

	/*
	|--------------------------------------------------------------------------
	| Table name helpers (static for use from meta store and install routine).
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns the name of the main products table.
	 *
	 * @return string
	 */
	public static function get_products_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_products';
	}

	/**
	 * Returns the name of the product attributes table.
	 *
	 * @return string
	 */
	public static function get_attributes_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_attributes';
	}

	/**
	 * Returns the name of the product attribute values table.
	 *
	 * @return string
	 */
	public static function get_attribute_values_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_attribute_values';
	}

	/**
	 * Returns the name of the product downloads table.
	 *
	 * @return string
	 */
	public static function get_downloads_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_product_downloads';
	}

	/**
	 * Returns the name of the products meta table.
	 *
	 * @return string
	 */
	public static function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_products_meta';
	}

	/**
	 * All HPPS table names, in dependency order (parents before children).
	 *
	 * Used by the synchronizer to detect missing tables and to drop them on
	 * uninstall/reset. Mirror of OrdersTableDataStore::get_all_table_names().
	 *
	 * @return string[]
	 */
	public function get_all_table_names(): array {
		return array(
			self::get_products_table_name(),
			self::get_attributes_table_name(),
			self::get_attribute_values_table_name(),
			self::get_downloads_table_name(),
			self::get_meta_table_name(),
		);
	}

	/**
	 * Whether the given product has been migrated to HPPS (a row exists in
	 * `wc_products`).
	 *
	 * Used by the read/update/delete paths to decide between HPPS and the
	 * legacy CPT data store while a site has the feature enabled but its
	 * existing products haven't all been copied yet. Cheap single-row check
	 * — no caching is added here because every consumer reads the full row
	 * immediately afterwards anyway.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True when a HPPS row exists, false otherwise.
	 */
	protected function product_exists_in_hpps( int $product_id ): bool {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		return null !== $found;
	}

	/**
	 * Lazily build (and cache) the legacy CPT data store used as fall-back.
	 *
	 * Subclasses override this to return their type-specific legacy store
	 * (`WC_Product_Variable_Data_Store_CPT`, etc.) so delegation goes to a
	 * data store that understands the right product type.
	 *
	 * @return WC_Product_Data_Store_CPT
	 */
	protected function get_legacy_data_store(): WC_Product_Data_Store_CPT {
		if ( null === $this->legacy_data_store ) {
			$this->legacy_data_store = new WC_Product_Data_Store_CPT();
		}
		return $this->legacy_data_store;
	}

	/**
	 * Get the column-to-property mapping.
	 *
	 * @return array<string, array{name:string,type:string}>
	 */
	public function get_product_column_mapping(): array {
		return $this->product_column_mapping;
	}

	/*
	|--------------------------------------------------------------------------
	| Schema.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns the database schema SQL for all HPPS tables.
	 *
	 * Returned as a single string compatible with dbDelta(). All identifiers
	 * are escaped via $wpdb->prefix and the configured collation; never
	 * interpolate user data here.
	 *
	 * @return string SQL string with one CREATE TABLE per HPPS table.
	 */
	public function get_database_schema(): string {
		global $wpdb;

		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$products_table         = self::get_products_table_name();
		$attributes_table       = self::get_attributes_table_name();
		$attribute_values_table = self::get_attribute_values_table_name();
		$downloads_table        = self::get_downloads_table_name();
		$meta_table             = self::get_meta_table_name();

		$max_index_length = $this->database_util->get_max_index_length();
		// 8 for product_id, 100 for meta_key, then leave at least 20 for meta_value.
		$composite_meta_value_index_length = max( $max_index_length - 8 - 100 - 1, 20 );

		return "
CREATE TABLE {$products_table} (
	id bigint(20) unsigned NOT NULL,
	type varchar(20) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'publish',
	name text NOT NULL,
	slug varchar(200) NOT NULL DEFAULT '',
	sku varchar(100) NULL DEFAULT '',
	global_unique_id varchar(100) NULL DEFAULT '',
	description longtext NULL,
	short_description text NULL,
	parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
	menu_order int(11) NOT NULL DEFAULT 0,
	date_created_gmt datetime NULL DEFAULT NULL,
	date_modified_gmt datetime NULL DEFAULT NULL,
	price decimal(19,4) NULL DEFAULT NULL,
	regular_price decimal(19,4) NULL DEFAULT NULL,
	sale_price decimal(19,4) NULL DEFAULT NULL,
	date_on_sale_from datetime NULL DEFAULT NULL,
	date_on_sale_to datetime NULL DEFAULT NULL,
	tax_status varchar(20) NULL DEFAULT 'taxable',
	tax_class varchar(100) NULL DEFAULT '',
	manage_stock tinyint(1) NOT NULL DEFAULT 0,
	stock_quantity double NULL DEFAULT NULL,
	stock_status varchar(20) NOT NULL DEFAULT 'instock',
	backorders varchar(10) NOT NULL DEFAULT 'no',
	low_stock_amount int(11) NULL DEFAULT NULL,
	sold_individually tinyint(1) NOT NULL DEFAULT 0,
	weight varchar(100) NULL DEFAULT '',
	length varchar(100) NULL DEFAULT '',
	width varchar(100) NULL DEFAULT '',
	height varchar(100) NULL DEFAULT '',
	virtual tinyint(1) NOT NULL DEFAULT 0,
	downloadable tinyint(1) NOT NULL DEFAULT 0,
	featured tinyint(1) NOT NULL DEFAULT 0,
	catalog_visibility varchar(20) NOT NULL DEFAULT 'visible',
	reviews_allowed tinyint(1) NOT NULL DEFAULT 1,
	total_sales bigint(20) NOT NULL DEFAULT 0,
	average_rating decimal(3,2) NOT NULL DEFAULT 0.00,
	rating_count bigint(20) NOT NULL DEFAULT 0,
	review_count bigint(20) NOT NULL DEFAULT 0,
	download_limit int(11) NOT NULL DEFAULT -1,
	download_expiry int(11) NOT NULL DEFAULT -1,
	purchase_note text NULL,
	post_password varchar(255) NOT NULL DEFAULT '',
	image_id bigint(20) unsigned NULL DEFAULT NULL,
	gallery_image_ids text NULL,
	product_url text NULL,
	button_text varchar(200) NOT NULL DEFAULT '',
	cogs_value decimal(19,4) NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY type_status (type, status),
	KEY sku (sku),
	KEY global_unique_id (global_unique_id),
	KEY parent_id (parent_id),
	KEY date_created (date_created_gmt),
	KEY featured (featured),
	KEY stock_status (stock_status),
	KEY price_range (price, regular_price)
) {$collate};
CREATE TABLE {$attributes_table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL,
	name varchar(200) NOT NULL,
	taxonomy varchar(200) NOT NULL DEFAULT '',
	position int(11) NOT NULL DEFAULT 0,
	is_visible tinyint(1) NOT NULL DEFAULT 1,
	is_for_variation tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY product_id (product_id),
	KEY taxonomy (taxonomy)
) {$collate};
CREATE TABLE {$attribute_values_table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL,
	attribute_id bigint(20) unsigned NOT NULL DEFAULT 0,
	scope varchar(20) NOT NULL DEFAULT 'product',
	value varchar(200) NOT NULL DEFAULT '',
	term_id bigint(20) unsigned NULL DEFAULT NULL,
	is_default tinyint(1) NOT NULL DEFAULT 0,
	position int(11) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY product_id (product_id),
	KEY attribute_id (attribute_id),
	KEY scope (scope),
	KEY term_id (term_id),
	KEY product_attribute (product_id, attribute_id, scope)
) {$collate};
CREATE TABLE {$downloads_table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL,
	download_id varchar(36) NOT NULL DEFAULT '',
	name varchar(200) NOT NULL DEFAULT '',
	file longtext NOT NULL,
	sort_order int(11) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY product_id (product_id),
	KEY download_id (download_id)
) {$collate};
CREATE TABLE {$meta_table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL,
	meta_key varchar(255) NULL,
	meta_value longtext NULL,
	PRIMARY KEY  (id),
	KEY meta_key_value (meta_key(100), meta_value({$composite_meta_value_index_length})),
	KEY product_id_meta_key (product_id, meta_key(100))
) {$collate};
";
	}

	/*
	|--------------------------------------------------------------------------
	| Type converters.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Convert a raw DB value to the appropriate PHP type for WC_Product setters.
	 *
	 * @param mixed  $value Raw value from the database.
	 * @param string $type  Type hint: string, int, decimal, bool, date.
	 * @return mixed Converted value.
	 */
	protected function convert_value_from_db( $value, string $type ) {
		if ( null === $value ) {
			return null;
		}

		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'decimal':
				// Keep as string so WC_Product price setters preserve precision.
				return (string) $value;
			case 'bool':
				return wc_string_to_bool( (string) $value );
			case 'date':
				return $value ? wc_string_to_timestamp( (string) $value ) : null;
			case 'string':
			default:
				return (string) $value;
		}
	}

	/**
	 * Convert a WC_Product property value to a DB-safe value.
	 *
	 * @param mixed  $value Property value.
	 * @param string $type  Type hint: string, int, decimal, bool, date.
	 * @return mixed DB-safe value.
	 */
	protected function convert_value_to_db( $value, string $type ) {
		if ( null === $value ) {
			return null;
		}

		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'decimal':
				return '' === $value ? null : (string) $value;
			case 'bool':
				return wc_bool_to_string( $value ) === 'yes' ? 1 : 0;
			case 'date':
				if ( $value instanceof \WC_DateTime ) {
					return gmdate( 'Y-m-d H:i:s', $value->getOffsetTimestamp() );
				}
				if ( is_numeric( $value ) ) {
					return gmdate( 'Y-m-d H:i:s', (int) $value );
				}
				return $value ? (string) $value : null;
			case 'string':
			default:
				return (string) $value;
		}
	}

	/**
	 * Returns the wpdb format string for a given column type.
	 *
	 * @param string $type Type hint: string, int, decimal, bool, date.
	 * @return string A %s/%d wpdb placeholder.
	 */
	protected function get_wpdb_format( string $type ): string {
		switch ( $type ) {
			case 'int':
			case 'bool':
				return '%d';
			case 'decimal':
			case 'date':
			case 'string':
			default:
				return '%s';
		}
	}

	/*
	|--------------------------------------------------------------------------
	| WC_Object_Data_Store_Interface (CRUD).
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a new product in the custom tables.
	 *
	 * Allocation order:
	 *   1. wp_insert_post() with post_type=product_placeholder to obtain an ID
	 *      without firing any CPT hooks (HPPS-review-and-plan.md issue #2).
	 *   2. INSERT a row into wc_products with the assigned ID.
	 *   3. Persist attributes, downloads, taxonomy terms, and meta.
	 *   4. Sync wc_product_meta_lookup (HPPS-review-and-plan.md, lookup gap).
	 *   5. Sync product_visibility taxonomy terms for legacy callers.
	 *   6. Fire woocommerce_new_product so listeners stay informed.
	 *
	 * @param WC_Product $product Product object passed by reference.
	 * @return void
	 *
	 * @throws Exception If the placeholder post cannot be created.
	 */
	public function create( &$product ) {
		global $wpdb;

		if ( ! $product->get_date_created( 'edit' ) ) {
			$product->set_date_created( time() );
		}
		$product->set_date_modified( time() );

		$post_id = wp_insert_post(
			array(
				'post_type'    => CustomProductsTableController::PLACEHOLDER_POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $product->get_name() ? $product->get_name() : __( 'Product', 'woocommerce' ),
				'post_author'  => get_current_user_id(),
				'ping_status'  => 'closed',
				'post_excerpt' => '',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		$product->set_id( (int) $post_id );

		$data    = $this->build_column_data( $product, true );
		$formats = $this->build_format_array( $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::get_products_table_name(), $data, $formats );

		$this->persist_attributes( $product, true );
		$this->persist_downloads( $product, true );
		$this->persist_taxonomy_terms( $product, true );
		$this->sync_visibility_terms( $product, true );

		$product->save_meta_data();
		$product->apply_changes();

		$this->update_lookup_table( $product->get_id() );

		/**
		 * Fires when a new product is created via HPPS.
		 *
		 * Mirrors the `woocommerce_new_product` action that the CPT data store
		 * fires, so listeners attached to the existing hook keep working.
		 *
		 * @since 10.9.0
		 *
		 * @param int        $product_id Product ID.
		 * @param WC_Product $product    Product object.
		 */
		do_action( 'woocommerce_new_product', $product->get_id(), $product );
	}

	/**
	 * Read a product from the custom tables.
	 *
	 * @param WC_Product $product Product object passed by reference.
	 * @return void
	 *
	 * @throws Exception If the product cannot be found.
	 */
	public function read( &$product ) {
		global $wpdb;

		$product->set_defaults();

		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			throw new Exception( esc_html__( 'Invalid product: no ID supplied.', 'woocommerce' ) );
		}

		/**
		 * Fires before reading a product from the HPPS tables.
		 *
		 * @since 10.9.0
		 *
		 * @param int $product_id Product ID.
		 */
		do_action( 'woocommerce_before_product_table_read', $product_id );

		// HPPS may be enabled but the row hasn't been migrated yet (or the
		// HPPS tables aren't installed at all). Delegate to the legacy CPT
		// data store so existing products keep loading instead of bubbling a
		// fatal up through wc_get_product(). Once the migration runs the row
		// will be in place and this branch becomes a no-op.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		if ( ! $row ) {
			$this->get_legacy_data_store()->read( $product );
			return;
		}

		$this->hydrate_from_row( $product, $row );
		$this->read_attributes( $product );
		$this->read_downloads( $product );
		$this->read_taxonomy_terms( $product );
		$this->read_meta_into_product( $product );

		$product->set_object_read( true );

		/**
		 * Fires after reading a product from the HPPS tables.
		 *
		 * @since 10.9.0
		 *
		 * @param int        $product_id Product ID.
		 * @param WC_Product $product    Product object.
		 */
		do_action( 'woocommerce_after_product_table_read', $product_id, $product );

		/**
		 * Mirrors the legacy `woocommerce_product_read` so listeners attached
		 * to the CPT path keep working under HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int        $product_id The product ID.
		 * @param WC_Product $product    Product instance.
		 */
		do_action( 'woocommerce_product_read', $product_id, $product );
	}

	/**
	 * Update a product in the custom tables.
	 *
	 * Only writes columns that are actually dirty (per get_changes()) so we
	 * never zero out fields. Subtables are persisted only if their dirty
	 * markers (`attributes`, `downloads`, `category_ids`, etc.) appear in the
	 * changeset, mirroring the CPT data store behaviour.
	 *
	 * @param WC_Product $product Product object passed by reference.
	 * @return void
	 */
	public function update( &$product ) {
		global $wpdb;

		// If this product hasn't been migrated yet, route the write back to
		// the legacy CPT data store. Doing so avoids silent partial writes
		// (HPPS subtables filled but main row missing) and keeps an
		// unmigrated product fully on the legacy path until the background
		// migration sweeps it across.
		$product_id = (int) $product->get_id();
		if ( $product_id > 0 && ! $this->product_exists_in_hpps( $product_id ) ) {
			$this->get_legacy_data_store()->update( $product );
			return;
		}

		$product->save_meta_data();

		$changes         = $product->get_changes();
		$previous_status = $this->get_db_status_for_product( $product_id );

		$product->set_date_modified( time() );

		$data = $this->build_column_data_from_changes( $product, $changes );
		// We always touch date_modified_gmt so the timestamp is current.
		$data['date_modified_gmt'] = gmdate( 'Y-m-d H:i:s' );

		if ( count( $data ) > 1 ) {
			$formats = $this->build_format_array( $data );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				$data,
				array( 'id' => (int) $product->get_id() ),
				$formats,
				array( '%d' )
			);
		}

		// Keep the placeholder post title roughly aligned with the product name
		// so any incidental wp_posts query (e.g. menu order, parent lookups)
		// still surfaces a sensible title for debugging.
		if ( array_key_exists( 'name', $changes ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array( 'post_title' => (string) $product->get_name() ),
				array( 'ID' => (int) $product->get_id() ),
				array( '%s' ),
				array( '%d' )
			);
			clean_post_cache( $product->get_id() );
		}

		if ( array_key_exists( 'attributes', $changes ) || array_key_exists( 'default_attributes', $changes ) ) {
			$this->persist_attributes( $product );
		}
		if ( array_key_exists( 'downloads', $changes ) ) {
			$this->persist_downloads( $product );
		}
		if ( array_intersect( array( 'category_ids', 'tag_ids', 'brand_ids', 'shipping_class_id' ), array_keys( $changes ) ) ) {
			$this->persist_taxonomy_terms( $product );
		}
		if ( array_intersect( array( 'featured', 'stock_status', 'average_rating', 'catalog_visibility' ), array_keys( $changes ) ) ) {
			$this->sync_visibility_terms( $product );
		}

		$product->apply_changes();

		$this->update_lookup_table( $product->get_id() );

		$new_status = $product->get_status();
		if ( $previous_status && $previous_status !== $new_status ) {
			/**
			 * Fires when a product's status transitions under HPPS.
			 *
			 * Replaces the WordPress `transition_post_status` hook for HPPS
			 * products. Listeners that previously hooked the WP transition
			 * should migrate to this one when targeting HPPS.
			 *
			 * @since 10.9.0
			 *
			 * @param int    $product_id      Product ID.
			 * @param string $new_status      New status.
			 * @param string $previous_status Previous status.
			 */
			do_action( 'woocommerce_product_set_status', (int) $product->get_id(), $new_status, $previous_status );
		}

		/**
		 * Fires when a product is updated via HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int        $product_id Product ID.
		 * @param WC_Product $product    Product object.
		 */
		do_action( 'woocommerce_update_product', $product->get_id(), $product );
	}

	/**
	 * Delete a product from the custom tables.
	 *
	 * Force-delete cascades through all five HPPS tables and removes the
	 * placeholder wp_posts row inside a single transaction. Trash mode
	 * (default) updates the status column and trashes the placeholder post,
	 * leaving subtable data intact for recovery.
	 *
	 * @param WC_Product $product Product object passed by reference.
	 * @param array      $args    Deletion args. Supports 'force_delete'.
	 * @return bool True when the product was deleted (or routed to the legacy
	 *              path), false when the product had no ID.
	 */
	public function delete( &$product, $args = array() ) {
		global $wpdb;

		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			return false;
		}

		// Unmigrated products live in CPT only — delegate so wp_delete_post,
		// the term cleanup, and the surrounding hooks all run as if HPPS
		// weren't enabled.
		if ( ! $this->product_exists_in_hpps( $product_id ) ) {
			$this->get_legacy_data_store()->delete( $product, $args );
			return true;
		}

		$args = wp_parse_args( $args, array( 'force_delete' => false ) );

		if ( ! empty( $args['force_delete'] ) ) {
			/**
			 * Fires before a product is permanently deleted.
			 *
			 * @since 10.9.0
			 *
			 * @param int $product_id Product ID.
			 */
			do_action( 'woocommerce_before_delete_product', $product_id );

			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			try {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( self::get_meta_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
				$wpdb->delete( self::get_attribute_values_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
				$wpdb->delete( self::get_attributes_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
				$wpdb->delete( self::get_downloads_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
				$wpdb->delete( self::get_products_table_name(), array( 'id' => $product_id ), array( '%d' ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				wp_delete_post( $product_id, true );

				$this->delete_from_lookup_table( $product_id, 'wc_product_meta_lookup' );

				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				throw $e;
			}

			$product->set_id( 0 );

			/**
			 * Fires after a product is permanently deleted.
			 *
			 * @since 10.9.0
			 *
			 * @param int $product_id Product ID.
			 */
			do_action( 'woocommerce_delete_product', $product_id );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::get_products_table_name(),
			array(
				'status'            => 'trash',
				'date_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $product_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_trash_post( $product_id );
		$product->set_status( 'trash' );

		// Drop the cached lookup row. Storefront/admin queries already filter
		// by post_status so a stale row wouldn't surface visually, but the
		// row itself drifts away from the canonical wc_products values for
		// the lifetime of the trash entry, breaks tools that read the lookup
		// directly (analytics, custom reports, the variations REST endpoint),
		// and leaks rows when a product is trashed-and-recreated rather than
		// untrashed.
		$this->delete_from_lookup_table( $product_id, 'wc_product_meta_lookup' );

		/**
		 * Fires when a product is trashed under HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int $product_id Product ID.
		 */
		do_action( 'woocommerce_trash_product', $product_id );
		return true;
	}

	/**
	 * Read multiple products in a single round-trip.
	 *
	 * The headline performance win of HPPS: shop pages that previously ran
	 * `1 + n + n*30+` queries per page (one WP_Query, one read per product,
	 * 30+ postmeta hits per product) collapse to:
	 *  - 1 SELECT against wc_products
	 *  - 1 SELECT against wc_products_meta
	 *  - 1 SELECT against wc_product_attributes
	 *  - 1 SELECT against wc_product_attribute_values
	 *
	 * @param WC_Product[] $products Indexed array of WC_Product instances passed by reference.
	 * @return void
	 */
	public function read_multiple( &$products ): void {
		global $wpdb;

		if ( empty( $products ) ) {
			return;
		}

		$by_id = array();
		foreach ( $products as $product ) {
			$id = (int) $product->get_id();
			if ( $id > 0 ) {
				$by_id[ $id ] = $product;
				$product->set_defaults();
			}
		}

		if ( empty( $by_id ) ) {
			return;
		}

		$ids         = array_keys( $by_id );
		$placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_products_table_name() . " WHERE id IN ( {$placeholder} )",
				...$ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			$row_id = (int) $row->id;
			if ( ! isset( $by_id[ $row_id ] ) ) {
				continue;
			}
			$this->hydrate_from_row( $by_id[ $row_id ], $row );
		}

		// Batch-read attributes for all IDs in one query, then fan out per-product.
		$this->read_attributes_for_ids( $by_id );
		$this->read_downloads_for_ids( $by_id );

		// Meta and taxonomy still go per-product since they hit two different
		// stores; tightening that further is a Chunk D / E optimisation.
		foreach ( $by_id as $product ) {
			$this->read_taxonomy_terms( $product );
			$this->read_meta_into_product( $product );
			$product->set_object_read( true );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Hydrate / persist helpers.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hydrate a WC_Product instance from a wc_products row.
	 *
	 * @param WC_Product $product Product instance.
	 * @param object     $row     Row object from $wpdb->get_row().
	 * @return void
	 */
	protected function hydrate_from_row( WC_Product &$product, object $row ): void {
		$cogs_enabled = $this->cogs_feature_is_enabled();

		foreach ( $this->product_column_mapping as $column => $mapping ) {
			if ( 'id' === $column || 'gallery_image_ids' === $column ) {
				continue;
			}

			// WC_Product::set_cogs_value() emits _doing_it_wrong when the COGS
			// feature is off, so skip the setter entirely in that case rather
			// than spam the log on every front-end read.
			if ( 'cogs_value' === $column && ! $cogs_enabled ) {
				continue;
			}

			$setter = 'set_' . $mapping['name'];
			if ( ! is_callable( array( $product, $setter ) ) ) {
				continue;
			}

			$value = $this->convert_value_from_db( $row->{$column} ?? null, $mapping['type'] );
			$product->{$setter}( $value );
		}

		// Gallery image IDs are stored as a comma-separated string but the
		// WC_Product setter expects an array of IDs.
		if ( ! empty( $row->gallery_image_ids ) ) {
			$product->set_gallery_image_ids( array_filter( array_map( 'absint', explode( ',', (string) $row->gallery_image_ids ) ) ) );
		}
	}

	/**
	 * Build the data array for a full insert from a WC_Product instance.
	 *
	 * @param WC_Product $product            Product object.
	 * @param bool       $for_create         Whether this insert is for create() (always sets id).
	 * @return array<string, mixed>
	 */
	protected function build_column_data( WC_Product $product, bool $for_create ): array {
		$data = array();

		foreach ( $this->product_column_mapping as $column => $mapping ) {
			if ( 'id' === $column ) {
				$data['id'] = (int) $product->get_id();
				continue;
			}

			if ( 'gallery_image_ids' === $column ) {
				$gallery       = (array) $product->get_gallery_image_ids( 'edit' );
				$data[ $column ] = $gallery ? implode( ',', array_map( 'absint', $gallery ) ) : '';
				continue;
			}

			if ( ! $this->should_persist_column( $product, $column, $mapping ) ) {
				continue;
			}

			$getter = 'get_' . $mapping['name'];
			if ( ! is_callable( array( $product, $getter ) ) ) {
				continue;
			}

			$value           = $product->{$getter}( 'edit' );
			$data[ $column ] = $this->convert_value_to_db( $value, $mapping['type'] );
		}

		// Always set type and date_modified_gmt on inserts.
		if ( $for_create ) {
			$data['type'] = $product->get_type();
			if ( empty( $data['date_modified_gmt'] ) ) {
				$data['date_modified_gmt'] = gmdate( 'Y-m-d H:i:s' );
			}
		}

		return $data;
	}

	/**
	 * Build the data array for an update, restricted to dirty properties.
	 *
	 * @param WC_Product           $product Product object.
	 * @param array<string, mixed> $changes Output of $product->get_changes().
	 * @return array<string, mixed>
	 */
	protected function build_column_data_from_changes( WC_Product $product, array $changes ): array {
		$data = array();

		// Invert the column mapping: property name => column.
		$prop_to_column = array();
		foreach ( $this->product_column_mapping as $column => $mapping ) {
			$prop_to_column[ $mapping['name'] ] = array(
				'column' => $column,
				'type'   => $mapping['type'],
			);
		}

		foreach ( array_keys( $changes ) as $prop ) {
			if ( ! isset( $prop_to_column[ $prop ] ) ) {
				continue;
			}
			if ( in_array( $prop, self::SUBTABLE_PROPERTIES, true ) ) {
				continue;
			}

			$col_info = $prop_to_column[ $prop ];
			$column   = $col_info['column'];

			if ( 'id' === $column ) {
				continue;
			}

			$getter = 'get_' . $prop;
			if ( ! is_callable( array( $product, $getter ) ) ) {
				continue;
			}

			if ( 'gallery_image_ids' === $column ) {
				$gallery       = (array) $product->{$getter}( 'edit' );
				$data[ $column ] = $gallery ? implode( ',', array_map( 'absint', $gallery ) ) : '';
				continue;
			}

			$data[ $column ] = $this->convert_value_to_db( $product->{$getter}( 'edit' ), $col_info['type'] );
		}

		return $data;
	}

	/**
	 * Build the wpdb format string array for a $data payload.
	 *
	 * @param array<string, mixed> $data Column => value array.
	 * @return string[]
	 */
	protected function build_format_array( array $data ): array {
		$type_by_column = array();
		foreach ( $this->product_column_mapping as $column => $mapping ) {
			$type_by_column[ $column ] = $mapping['type'];
		}

		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$type      = $type_by_column[ $column ] ?? 'string';
			$formats[] = $this->get_wpdb_format( $type );
		}
		return $formats;
	}

	/**
	 * Whether a column should be persisted on create for a given product.
	 *
	 * Excludes: external-only fields when not external, COGS column when the
	 * feature is disabled, and properties owned by subtables.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $column  Column name.
	 * @param array      $mapping Column mapping entry.
	 * @return bool
	 */
	protected function should_persist_column( WC_Product $product, string $column, array $mapping ): bool {
		if ( in_array( $mapping['name'], self::SUBTABLE_PROPERTIES, true ) ) {
			return false;
		}
		if ( in_array( $column, array( 'product_url', 'button_text' ), true ) ) {
			return ProductType::EXTERNAL === $product->get_type();
		}
		if ( 'cogs_value' === $column ) {
			return $this->cogs_feature_is_enabled();
		}
		return true;
	}

	/**
	 * Read the row's status straight from the database before an update so we
	 * can fire the correct status-transition hook.
	 *
	 * @param int $product_id Product ID.
	 * @return string|null Previous status, or null if the row is missing.
	 */
	protected function get_db_status_for_product( int $product_id ): ?string {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return null;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		return null === $status ? null : (string) $status;
	}

	/**
	 * Look up the persisted product `type` for an ID, or null if missing.
	 *
	 * @param int $product_id Product ID.
	 * @return string|null
	 */
	protected function get_db_type_for_product( int $product_id ): ?string {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		return null === $type ? null : (string) $type;
	}

	/**
	 * Compute MIN/MAX prices and on-sale flag across a variable product's
	 * children. Returns null prices when there are no purchasable children.
	 *
	 * @param int $parent_id Variable product ID.
	 * @return array{min:mixed,max:mixed,onsale:int}
	 */
	protected function get_variation_price_range( int $parent_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					MIN( CAST( price AS DECIMAL(26,8) ) ) AS min_price,
					MAX( CAST( price AS DECIMAL(26,8) ) ) AS max_price,
					SUM( CASE WHEN sale_price IS NOT NULL AND sale_price <> "" AND sale_price = price THEN 1 ELSE 0 END ) AS sale_count
				FROM ' . self::get_products_table_name() . " WHERE parent_id = %d AND type = %s AND status = %s AND price IS NOT NULL AND price <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$parent_id,
				ProductType::VARIATION,
				ProductStatus::PUBLISH
			)
		);

		if ( ! $row || null === $row->min_price ) {
			return array(
				'min'    => null,
				'max'    => null,
				'onsale' => 0,
			);
		}

		return array(
			'min'    => $row->min_price,
			'max'    => $row->max_price,
			'onsale' => (int) $row->sale_count > 0 ? 1 : 0,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Subtable persistence: attributes.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist a product's attribute definitions and values.
	 *
	 * Strategy: delete all existing rows for the product and insert fresh ones.
	 * Attribute writes are infrequent (only on save with `attributes` dirty)
	 * and the row counts are small, so a delete+insert is simpler and safer
	 * than diff-and-update.
	 *
	 * @param WC_Product $product Product object.
	 * @param bool       $force   Set to true on create.
	 * @return void
	 */
	protected function persist_attributes( WC_Product $product, bool $force = false ): void {
		global $wpdb;

		unset( $force ); // The caller already gates on `attributes` being dirty.

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// Snapshot the parent's existing (name => attribute_id) map before
		// we drop and re-issue these rows. Variation-scope rows in
		// wc_product_attribute_values reference this attribute_id, and
		// would silently dangle if we re-inserted with new ids without
		// remapping. See {@see self::rebind_variation_attribute_ids()}.
		$old_attribute_ids_by_name = self::snapshot_attribute_ids_by_name( $product_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::get_attribute_values_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
		$wpdb->delete( self::get_attributes_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );

		$attributes         = (array) $product->get_attributes( 'edit' );
		$default_attributes = (array) $product->get_default_attributes( 'edit' );

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$wpdb->insert(
				self::get_attributes_table_name(),
				array(
					'product_id'       => $product_id,
					'name'             => $attribute->get_name(),
					'taxonomy'         => $attribute->is_taxonomy() ? $attribute->get_name() : '',
					'position'         => (int) $attribute->get_position(),
					'is_visible'       => $attribute->get_visible() ? 1 : 0,
					'is_for_variation' => $attribute->get_variation() ? 1 : 0,
				),
				array( '%d', '%s', '%s', '%d', '%d', '%d' )
			);

			$attribute_row_id = (int) $wpdb->insert_id;

			if ( $attribute->is_taxonomy() ) {
				// For taxonomy attributes, also keep the taxonomy term assignment
				// in WordPress' tables so frontend templates and term queries
				// keep working. The values table additionally stores term_id for
				// fast joins.
				wp_set_object_terms(
					$product_id,
					wp_list_pluck( (array) $attribute->get_terms(), 'term_id' ),
					$attribute->get_name(),
					false
				);
				$position = 0;
				foreach ( (array) $attribute->get_terms() as $term ) {
					$wpdb->insert(
						self::get_attribute_values_table_name(),
						array(
							'product_id'   => $product_id,
							'attribute_id' => $attribute_row_id,
							'scope'        => 'product',
							'value'        => (string) $term->slug,
							'term_id'      => (int) $term->term_id,
							'is_default'   => 0,
							'position'     => $position,
						),
						array( '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
					);
					++$position;
				}
			} else {
				$position = 0;
				foreach ( (array) $attribute->get_options() as $option ) {
					$wpdb->insert(
						self::get_attribute_values_table_name(),
						array(
							'product_id'   => $product_id,
							'attribute_id' => $attribute_row_id,
							'scope'        => 'product',
							'value'        => (string) $option,
							'term_id'      => null,
							'is_default'   => 0,
							'position'     => $position,
						),
						array( '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
					);
					++$position;
				}
			}
		}

		foreach ( $default_attributes as $attr_name => $value ) {
			$attribute_row_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE product_id = %d AND name = %s LIMIT 1',
					self::get_attributes_table_name(),
					$product_id,
					(string) $attr_name
				)
			);
			if ( $attribute_row_id <= 0 ) {
				continue;
			}
			$wpdb->insert(
				self::get_attribute_values_table_name(),
				array(
					'product_id'   => $product_id,
					'attribute_id' => $attribute_row_id,
					'scope'        => 'product',
					'value'        => (string) $value,
					'term_id'      => null,
					'is_default'   => 1,
					'position'     => 0,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Re-point any variation-scope rows that referenced the old
		// attribute_ids at the freshly-inserted ones, matched by name.
		// Without this, every parent attribute save invalidated every
		// variation's option mapping until the variation was re-saved.
		$new_attribute_ids_by_name = self::snapshot_attribute_ids_by_name( $product_id );
		self::rebind_variation_attribute_ids( $product_id, $old_attribute_ids_by_name, $new_attribute_ids_by_name );

		/**
		 * Fires after a product's attributes have been persisted to HPPS tables.
		 *
		 * @since 10.9.0
		 *
		 * @param WC_Product $product       Product object.
		 * @param bool       $force_changed Whether the caller forced a recompute.
		 */
		do_action( 'woocommerce_product_attributes_updated', $product, false );
	}

	/**
	 * Snapshot the (attribute_name => attribute_id) map for a parent's
	 * `wc_product_attributes` rows. Used to remap variation-scope value
	 * rows after the parent's attributes are rebuilt.
	 *
	 * Both `name` and `taxonomy` are kept under their respective keys: the
	 * variation persister resolves a variation's selected attribute by
	 * either `name = ?` OR `taxonomy = ?`, so we mirror the same lookup
	 * semantics here.
	 *
	 * @param int $product_id Parent product id.
	 * @return array<string, int> name (or taxonomy) => attribute_id.
	 */
	protected static function snapshot_attribute_ids_by_name( int $product_id ): array {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, name, taxonomy FROM %i WHERE product_id = %d',
				self::get_attributes_table_name(),
				$product_id
			)
		);

		$map = array();
		if ( ! is_array( $rows ) ) {
			return $map;
		}

		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			if ( $id <= 0 ) {
				continue;
			}
			$name = (string) $row->name;
			if ( '' !== $name ) {
				$map[ $name ] = $id;
			}
			$taxonomy = (string) $row->taxonomy;
			if ( '' !== $taxonomy && ! isset( $map[ $taxonomy ] ) ) {
				$map[ $taxonomy ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Rebind `wc_product_attribute_values` rows scoped to variations
	 * that still reference the now-defunct `attribute_id` of a parent
	 * row that was just deleted-and-reinserted.
	 *
	 * Matching is by attribute name. Renamed attributes are intentionally
	 * left dangling so that their variations behave the same as on the
	 * legacy CPT store, where renaming requires re-saving the variations.
	 *
	 * @param int               $parent_id       Variable parent id.
	 * @param array<string,int> $old_ids_by_name name => old attribute_id.
	 * @param array<string,int> $new_ids_by_name name => new attribute_id.
	 * @return void
	 */
	protected static function rebind_variation_attribute_ids( int $parent_id, array $old_ids_by_name, array $new_ids_by_name ): void {
		global $wpdb;

		if ( $parent_id <= 0 || empty( $old_ids_by_name ) || empty( $new_ids_by_name ) ) {
			return;
		}

		$values_table   = self::get_attribute_values_table_name();
		$products_table = self::get_products_table_name();

		foreach ( $old_ids_by_name as $name => $old_id ) {
			if ( ! isset( $new_ids_by_name[ $name ] ) ) {
				continue;
			}
			$old_id = (int) $old_id;
			$new_id = (int) $new_ids_by_name[ $name ];
			if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i v JOIN %i p ON p.id = v.product_id'
					. " SET v.attribute_id = %d"
					. " WHERE v.scope = 'variation' AND v.attribute_id = %d AND p.parent_id = %d",
					$values_table,
					$products_table,
					$new_id,
					$old_id,
					$parent_id
				)
			);
		}
	}

	/**
	 * Read a product's attributes back into the WC_Product instance.
	 *
	 * @param WC_Product $product Product object.
	 * @return void
	 */
	protected function read_attributes( WC_Product &$product ): void {
		global $wpdb;

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attribute_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE product_id = %d ORDER BY position ASC, id ASC',
				self::get_attributes_table_name(),
				$product_id
			)
		);

		if ( empty( $attribute_rows ) ) {
			return;
		}

		$attribute_ids = wp_list_pluck( $attribute_rows, 'id' );
		$values_rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_attribute_values_table_name() . " WHERE product_id = %d AND attribute_id IN ( " . implode( ',', array_map( 'absint', $attribute_ids ) ) . " ) AND scope = 'product' ORDER BY position ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$values_by_attr   = array();
		$defaults_by_name = array();
		foreach ( $values_rows as $value_row ) {
			if ( (int) $value_row->is_default === 1 ) {
				continue;
			}
			$values_by_attr[ (int) $value_row->attribute_id ][] = $value_row;
		}
		foreach ( $values_rows as $value_row ) {
			if ( (int) $value_row->is_default === 1 ) {
				$defaults_by_name[ $this->find_attribute_name_by_id( $attribute_rows, (int) $value_row->attribute_id ) ] = (string) $value_row->value;
			}
		}

		$attributes = array();
		foreach ( $attribute_rows as $attribute_row ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( (string) $attribute_row->name );
			$attribute->set_position( (int) $attribute_row->position );
			$attribute->set_visible( (bool) $attribute_row->is_visible );
			$attribute->set_variation( (bool) $attribute_row->is_for_variation );

			if ( ! empty( $attribute_row->taxonomy ) && taxonomy_exists( (string) $attribute_row->taxonomy ) ) {
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( (string) $attribute_row->taxonomy ) );
				$attribute->set_options( wc_get_object_terms( $product_id, (string) $attribute_row->taxonomy, 'term_id' ) );
			} else {
				$attribute->set_id( 0 );
				$options    = array();
				foreach ( $values_by_attr[ (int) $attribute_row->id ] ?? array() as $value_row ) {
					$options[] = (string) $value_row->value;
				}
				$attribute->set_options( $options );
			}

			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );

		if ( $defaults_by_name ) {
			$product->set_default_attributes( $defaults_by_name );
		}
	}

	/**
	 * Batched read_attributes() for read_multiple().
	 *
	 * @param array<int, WC_Product> $products_by_id Products keyed by ID.
	 * @return void
	 */
	protected function read_attributes_for_ids( array $products_by_id ): void {
		// Phase 1 of read_multiple keeps this delegating per-product. Replacing
		// it with a single grouped query is a Chunk E perf optimisation.
		foreach ( $products_by_id as $product ) {
			$this->read_attributes( $product );
		}
	}

	/**
	 * Helper for read_attributes(): find the attribute name from a row id.
	 *
	 * @param array $attribute_rows Rows from wc_product_attributes.
	 * @param int   $attribute_id   Row ID.
	 * @return string
	 */
	private function find_attribute_name_by_id( array $attribute_rows, int $attribute_id ): string {
		foreach ( $attribute_rows as $row ) {
			if ( (int) $row->id === $attribute_id ) {
				return (string) $row->name;
			}
		}
		return '';
	}

	/*
	|--------------------------------------------------------------------------
	| Subtable persistence: downloads.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist a product's downloads.
	 *
	 * @param WC_Product $product Product object.
	 * @param bool       $force   Whether this is a create-time persist.
	 * @return void
	 */
	protected function persist_downloads( WC_Product $product, bool $force = false ): void {
		global $wpdb;
		unset( $force );

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::get_downloads_table_name(), array( 'product_id' => $product_id ), array( '%d' ) );

		$downloads = (array) $product->get_downloads();
		$position  = 0;
		foreach ( $downloads as $download_id => $download ) {
			if ( ! $download instanceof WC_Product_Download ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::get_downloads_table_name(),
				array(
					'product_id'  => $product_id,
					'download_id' => (string) ( $download->get_id() ?: $download_id ),
					'name'        => (string) $download->get_name(),
					'file'        => (string) $download->get_file(),
					'sort_order'  => $position,
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
			++$position;
		}
	}

	/**
	 * Read downloads from wc_product_downloads back into the product.
	 *
	 * @param WC_Product $product Product object.
	 * @return void
	 */
	protected function read_downloads( WC_Product &$product ): void {
		global $wpdb;

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE product_id = %d ORDER BY sort_order ASC, id ASC',
				self::get_downloads_table_name(),
				$product_id
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$downloads = array();
		foreach ( $rows as $row ) {
			$download = new WC_Product_Download();
			$download->set_id( (string) $row->download_id );
			$download->set_name( (string) $row->name ?: wc_get_filename_from_url( (string) $row->file ) );
			/**
			 * Filter the path of a downloadable file as it is read from HPPS.
			 *
			 * @since 10.9.0
			 *
			 * @param string     $file    The file path.
			 * @param WC_Product $product The product object.
			 * @param string     $key     The download key.
			 */
			$download->set_file( apply_filters( 'woocommerce_file_download_path', (string) $row->file, $product, (string) $row->download_id ) );
			$downloads[] = $download;
		}
		$product->set_downloads( $downloads );
	}

	/**
	 * Batched read_downloads() for read_multiple().
	 *
	 * @param array<int, WC_Product> $products_by_id Products keyed by ID.
	 * @return void
	 */
	protected function read_downloads_for_ids( array $products_by_id ): void {
		foreach ( $products_by_id as $product ) {
			$this->read_downloads( $product );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Subtable persistence: taxonomy terms.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist categories, tags, brands and shipping class.
	 *
	 * Phase 1 keeps these in WordPress taxonomy tables (wp_term_relationships /
	 * wp_term_taxonomy) — see HPPS-review-and-plan.md notes about this being
	 * the right call for now.
	 *
	 * @param WC_Product $product Product object.
	 * @param bool       $force   Set to true on create.
	 * @return void
	 */
	protected function persist_taxonomy_terms( WC_Product $product, bool $force = false ): void {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$changes = $product->get_changes();

		if ( $force || array_key_exists( 'category_ids', $changes ) ) {
			$categories = (array) $product->get_category_ids( 'edit' );
			if ( empty( $categories ) ) {
				$default = (int) get_option( 'default_product_cat', 0 );
				if ( $default > 0 ) {
					$categories = array( $default );
				}
			}
			wp_set_post_terms( $product_id, $categories, 'product_cat', false );
		}

		if ( $force || array_key_exists( 'tag_ids', $changes ) ) {
			wp_set_post_terms( $product_id, (array) $product->get_tag_ids( 'edit' ), 'product_tag', false );
		}

		if ( $force || array_key_exists( 'brand_ids', $changes ) ) {
			wp_set_post_terms( $product_id, (array) $product->get_brand_ids( 'edit' ), 'product_brand', false );
		}

		if ( $force || array_key_exists( 'shipping_class_id', $changes ) ) {
			wp_set_post_terms( $product_id, array( (int) $product->get_shipping_class_id( 'edit' ) ), 'product_shipping_class', false );
		}

		// Always pin product_type to the current product's type.
		wp_set_object_terms( $product_id, $product->get_type(), 'product_type' );
	}

	/**
	 * Read taxonomy terms back into the product.
	 *
	 * @param WC_Product $product Product object.
	 * @return void
	 */
	protected function read_taxonomy_terms( WC_Product &$product ): void {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$product->set_category_ids( wc_get_object_terms( $product_id, 'product_cat', 'term_id' ) );
		$product->set_tag_ids( wc_get_object_terms( $product_id, 'product_tag', 'term_id' ) );
		$product->set_brand_ids( wc_get_object_terms( $product_id, 'product_brand', 'term_id' ) );

		$shipping_class_terms = wc_get_object_terms( $product_id, 'product_shipping_class', 'term_id' );
		$product->set_shipping_class_id( ! empty( $shipping_class_terms ) ? (int) $shipping_class_terms[0] : 0 );
	}

	/**
	 * Sync the legacy `product_visibility` taxonomy with the column-based
	 * featured / catalog_visibility / stock_status / average_rating values so
	 * extensions and templates that still read taxonomy terms keep working.
	 *
	 * Mirrors WC_Product_Data_Store_CPT::update_visibility().
	 *
	 * @param WC_Product $product Product object.
	 * @param bool       $force   Set to true on create.
	 * @return void
	 */
	protected function sync_visibility_terms( WC_Product $product, bool $force = false ): void {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		unset( $force );

		$terms = array();

		if ( $product->get_featured() ) {
			$terms[] = 'featured';
		}

		if ( ProductStockStatus::OUT_OF_STOCK === $product->get_stock_status() ) {
			$terms[] = ProductStockStatus::OUT_OF_STOCK;
		}

		$rating = (int) min( 5, NumberUtil::round( (float) $product->get_average_rating(), 0 ) );
		if ( $rating > 0 ) {
			$terms[] = 'rated-' . $rating;
		}

		switch ( $product->get_catalog_visibility() ) {
			case CatalogVisibility::HIDDEN:
				$terms[] = 'exclude-from-search';
				$terms[] = 'exclude-from-catalog';
				break;
			case CatalogVisibility::CATALOG:
				$terms[] = 'exclude-from-search';
				break;
			case CatalogVisibility::SEARCH:
				$terms[] = 'exclude-from-catalog';
				break;
		}

		if ( ! is_wp_error( wp_set_post_terms( $product_id, $terms, 'product_visibility', false ) ) ) {
			/**
			 * Fires after the visibility taxonomy has been synced.
			 *
			 * @since 10.9.0
			 *
			 * @param int    $product_id         Product ID.
			 * @param string $catalog_visibility Catalog visibility value.
			 */
			do_action( 'woocommerce_product_set_visibility', $product_id, $product->get_catalog_visibility() );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Meta read helper.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hydrate the WC_Product meta_data array from the HPPS meta store.
	 *
	 * @param WC_Product $product Product object.
	 * @return void
	 */
	protected function read_meta_into_product( WC_Product &$product ): void {
		$raw_meta = $this->meta_data_store->read_meta( $product );

		$meta_data = array();
		foreach ( $raw_meta as $row ) {
			$meta_data[] = new \WC_Meta_Data(
				array(
					'id'    => isset( $row->meta_id ) ? (int) $row->meta_id : 0,
					'key'   => isset( $row->meta_key ) ? (string) $row->meta_key : '',
					'value' => isset( $row->meta_value ) ? maybe_unserialize( $row->meta_value ) : null,
				)
			);
		}

		// WC_Data exposes set_meta_data() for setting the entire collection
		// in one shot, which is exactly the shape we have after re-hydrating
		// from the HPPS meta table.
		$product->set_meta_data( $meta_data );
	}

	/*
	|--------------------------------------------------------------------------
	| Lookup table sync.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Refresh the wc_product_meta_lookup row for a given product, sourcing the
	 * data straight from wc_products columns instead of postmeta.
	 *
	 * Keeps the legacy 31+ files that join wc_product_meta_lookup serving
	 * correct data while HPPS is enabled. See HPPS-review-and-plan.md
	 * "wc_product_meta_lookup not updated" note.
	 *
	 * Signature must match {@see WC_Data_Store_WP::update_lookup_table()},
	 * which is `($id, $table)`. We ignore `$table` because the only lookup
	 * table HPPS knows about is `wc_product_meta_lookup`.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $table      Lookup table key (unused; for parent signature compatibility).
	 * @return null Always null, matching the parent's signature in
	 *              {@see WC_Data_Store_WP::update_lookup_table()}.
	 */
	public function update_lookup_table( $product_id, $table = '' ) {
		global $wpdb;

		unset( $table );

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, sku, global_unique_id, virtual, downloadable, price, regular_price, sale_price, stock_quantity, stock_status, rating_count, average_rating, total_sales, tax_status, tax_class, manage_stock, cogs_value FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$min_price = $row->price;
		$max_price = $row->price;
		$onsale    = ( null !== $row->sale_price && '' !== $row->sale_price && (string) $row->price === (string) $row->sale_price ) ? 1 : 0;

		// For variable parents, derive min/max from their children's
		// `wc_products.price` directly. We don't trust whatever value (if any)
		// the parent row carries in the `price` column.
		if ( ProductType::VARIABLE === (string) $this->get_db_type_for_product( $product_id ) ) {
			$variation_prices = $this->get_variation_price_range( $product_id );
			$min_price        = $variation_prices['min'];
			$max_price        = $variation_prices['max'];
			$onsale           = $variation_prices['onsale'];
		}

		$lookup_data = array(
			'product_id'     => $product_id,
			'sku'            => (string) $row->sku,
			'virtual'        => (int) $row->virtual,
			'downloadable'   => (int) $row->downloadable,
			'min_price'      => $min_price,
			'max_price'      => $max_price,
			'onsale'         => $onsale,
			'stock_quantity' => 'yes' === wc_bool_to_string( (bool) (int) $row->manage_stock ) ? $row->stock_quantity : null,
			'stock_status'   => (string) $row->stock_status,
			'rating_count'   => (int) $row->rating_count,
			'average_rating' => (string) $row->average_rating,
			'total_sales'    => (int) $row->total_sales,
			'tax_status'     => (string) $row->tax_status,
			'tax_class'      => (string) $row->tax_class,
		);

		if ( $this->use_cogs_lookup_column() ) {
			$lookup_data['cogs_total_value'] = ( null === $row->cogs_value || '' === $row->cogs_value ) ? null : (float) $row->cogs_value;
		}
		if ( (int) get_option( 'woocommerce_schema_version', 0 ) >= 920 ) {
			$lookup_data['global_unique_id'] = (string) $row->global_unique_id;
		}

		// Use UPDATE if the row exists and INSERT otherwise to avoid the
		// REPLACE INTO bug flagged in HPPS-review-and-plan.md issue #1.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d LIMIT 1",
				$product_id
			)
		);

		if ( $exists ) {
			$wpdb->update(
				$wpdb->wc_product_meta_lookup,
				$lookup_data,
				array( 'product_id' => $product_id )
			);
		} else {
			$wpdb->insert( $wpdb->wc_product_meta_lookup, $lookup_data );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null;
	}

	/**
	 * Whether the COGS feature is enabled.
	 *
	 * @return bool
	 */
	protected function cogs_feature_is_enabled(): bool {
		return wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled();
	}

	/**
	 * Whether the COGS column on wc_product_meta_lookup is available.
	 *
	 * @return bool
	 */
	protected function use_cogs_lookup_column(): bool {
		$cogs = wc_get_container()->get( CostOfGoodsSoldController::class );
		return $cogs->feature_is_enabled() && $cogs->product_meta_lookup_table_cogs_value_columns_exist();
	}

	/*
	|--------------------------------------------------------------------------
	| WC_Product_Data_Store_Interface — implemented methods.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get on-sale products from wc_products directly.
	 *
	 * @return array<int, object> Array of {id, parent_id} stdClass rows.
	 */
	public function get_on_sale_products() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, parent_id FROM %i
				WHERE status = %s
				AND sale_price IS NOT NULL
				AND sale_price <> %s
				AND sale_price > 0
				AND price = sale_price',
				self::get_products_table_name(),
				ProductStatus::PUBLISH,
				''
			)
		);
	}

	/**
	 * Get featured product IDs.
	 *
	 * @return array<int, int> id => parent_id map.
	 */
	public function get_featured_product_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, parent_id FROM %i
				WHERE status = %s
				AND featured = 1
				AND catalog_visibility != %s',
				self::get_products_table_name(),
				ProductStatus::PUBLISH,
				CatalogVisibility::HIDDEN
			)
		);

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row->id ] = (int) $row->parent_id;
		}
		return $result;
	}

	/**
	 * Check if a SKU already exists for any other product.
	 *
	 * @param int    $product_id Product ID being saved.
	 * @param string $sku        SKU to check.
	 * @return bool
	 */
	public function is_existing_sku( $product_id, $sku ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE sku = %s AND status != %s AND id <> %d LIMIT 1',
				self::get_products_table_name(),
				wp_slash( (string) $sku ),
				'trash',
				(int) $product_id
			)
		);
	}

	/**
	 * Check if a global_unique_id already exists for any other product.
	 *
	 * @param int    $product_id       Product ID being saved.
	 * @param string $global_unique_id Unique ID to check.
	 * @return bool
	 */
	public function is_existing_global_unique_id( $product_id, $global_unique_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE global_unique_id = %s AND status != %s AND id <> %d LIMIT 1',
				self::get_products_table_name(),
				wp_slash( (string) $global_unique_id ),
				'trash',
				(int) $product_id
			)
		);
	}

	/**
	 * Return product ID based on SKU.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public function get_product_id_by_sku( $sku ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE sku = %s AND status != %s LIMIT 1',
				self::get_products_table_name(),
				(string) $sku,
				'trash'
			)
		);

		/**
		 * Filter the product ID resolved from a SKU.
		 *
		 * @since 10.9.0
		 *
		 * @param int    $id  Product ID, or 0.
		 * @param string $sku SKU lookup string.
		 */
		return (int) apply_filters( 'woocommerce_get_product_id_by_sku', (int) $id, $sku );
	}

	/**
	 * Return product ID based on global_unique_id.
	 *
	 * @param string $global_unique_id Unique ID.
	 * @return int
	 */
	public function get_product_id_by_global_unique_id( $global_unique_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE global_unique_id = %s AND status != %s LIMIT 1',
				self::get_products_table_name(),
				(string) $global_unique_id,
				'trash'
			)
		);

		/**
		 * Filter the product ID resolved from a global unique ID.
		 *
		 * @since 10.9.0
		 *
		 * @param int    $id               Product ID, or 0.
		 * @param string $global_unique_id Unique ID lookup string.
		 */
		return (int) apply_filters( 'woocommerce_get_product_id_by_global_unique_id', (int) $id, $global_unique_id );
	}

	/**
	 * Return IDs of products whose sale starts before now and hasn't begun.
	 *
	 * @return array<int>
	 */
	public function get_starting_sales() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i
				WHERE date_on_sale_from IS NOT NULL
				AND UNIX_TIMESTAMP( date_on_sale_from ) > 0
				AND UNIX_TIMESTAMP( date_on_sale_from ) < %d
				AND price != regular_price',
				self::get_products_table_name(),
				time()
			)
		);
	}

	/**
	 * Return IDs of products whose sale ends before now and is still active.
	 *
	 * @return array<int>
	 */
	public function get_ending_sales() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i
				WHERE date_on_sale_to IS NOT NULL
				AND UNIX_TIMESTAMP( date_on_sale_to ) > 0
				AND UNIX_TIMESTAMP( date_on_sale_to ) < %d
				AND price != regular_price',
				self::get_products_table_name(),
				time()
			)
		);
	}

	/**
	 * Find a matching variation within a variable product.
	 *
	 * The real implementation lives in {@see ProductsTableVariableDataStore},
	 * which is what `woocommerce_product-variable_data_store` resolves to.
	 * The base store should never be asked this for a non-variable product;
	 * we return 0 so any defensive caller stays well-behaved.
	 *
	 * @param WC_Product $product          Variable product object.
	 * @param array      $match_attributes Attributes to match.
	 * @return int Matching variation ID, or 0.
	 */
	public function find_matching_product_variation( $product, $match_attributes = array() ) {
		unset( $product, $match_attributes );
		return 0;
	}

	/**
	 * Sort all variations under a parent.
	 *
	 * No-op on the base store; see {@see ProductsTableVariableDataStore} for
	 * the real implementation.
	 *
	 * @param int $parent_id Parent ID.
	 * @return void
	 */
	public function sort_all_product_variations( $parent_id ) {
		unset( $parent_id );
	}

	/**
	 * Return a list of related products. Defers to the legacy CPT logic for
	 * Phase 1 — the related-products query is taxonomy-driven and the term
	 * tables are still authoritative under HPPS.
	 *
	 * @param array $cats_array  Category IDs.
	 * @param array $tags_array  Tag IDs.
	 * @param array $exclude_ids Excluded IDs.
	 * @param int   $limit       Limit of results.
	 * @param int   $product_id  Product ID.
	 * @return array
	 */
	public function get_related_products( $cats_array, $tags_array, $exclude_ids, $limit, $product_id ) {
		$store = new \WC_Product_Data_Store_CPT();
		return $store->get_related_products( $cats_array, $tags_array, $exclude_ids, $limit, $product_id );
	}

	/**
	 * Update a product's stock amount directly on the wc_products column.
	 *
	 * Single UPDATE statement: cheaper than the postmeta dance the CPT store
	 * needs because we own the column.
	 *
	 * @param int      $product_id_with_stock Product ID.
	 * @param int|null $stock_quantity        Stock quantity to update to.
	 * @param string   $operation             Either set, increase, or decrease.
	 * @return float|null New stock quantity.
	 */
	public function update_product_stock( $product_id_with_stock, $stock_quantity = null, $operation = 'set' ) {
		global $wpdb;

		$product_id = (int) $product_id_with_stock;
		if ( $product_id <= 0 ) {
			return null;
		}

		$delta = wc_stock_amount( null === $stock_quantity ? 0 : (int) $stock_quantity );

		switch ( $operation ) {
			case 'increase':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET stock_quantity = COALESCE( stock_quantity, 0 ) + %f WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$delta,
						$product_id
					)
				);
				break;
			case 'decrease':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET stock_quantity = COALESCE( stock_quantity, 0 ) - %f WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$delta,
						$product_id
					)
				);
				break;
			default:
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET stock_quantity = %f WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$delta,
						$product_id
					)
				);
				break;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_stock = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT stock_quantity FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		$this->update_lookup_table( $product_id );

		/**
		 * Fires after a direct stock update so legacy listeners keep working.
		 *
		 * @since 10.9.0
		 *
		 * @param int $product_id_with_stock Product ID.
		 */
		do_action( 'woocommerce_updated_product_stock', $product_id );

		return null === $new_stock ? null : (float) $new_stock;
	}

	/**
	 * Update a product's total_sales counter directly on the wc_products column.
	 *
	 * @param int      $product_id Product ID.
	 * @param int|null $quantity   Quantity for the update.
	 * @param string   $operation  Either set, increase, or decrease.
	 * @return void
	 */
	public function update_product_sales( $product_id, $quantity = null, $operation = 'set' ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$qty = (float) $quantity;

		switch ( $operation ) {
			case 'increase':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET total_sales = COALESCE( total_sales, 0 ) + %f WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$qty,
						$product_id
					)
				);
				break;
			case 'decrease':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET total_sales = GREATEST( COALESCE( total_sales, 0 ) - %f, 0 ) WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$qty,
						$product_id
					)
				);
				break;
			default:
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::get_products_table_name() . ' SET total_sales = %f WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$qty,
						$product_id
					)
				);
				break;
		}

		$this->update_lookup_table( $product_id );

		/**
		 * Fires after a direct sales update so legacy listeners keep working.
		 *
		 * @since 10.9.0
		 *
		 * @param int $product_id Product ID.
		 */
		do_action( 'woocommerce_updated_product_sales', $product_id );
	}

	/**
	 * Get shipping class ID by slug. Same as the CPT store — shipping class
	 * stays in WordPress taxonomy tables for Phase 1.
	 *
	 * @param string $slug Shipping class slug.
	 * @return int|false
	 */
	public function get_shipping_class_id_by_slug( $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term ? (int) $term->term_id : false;
	}

	/**
	 * Returns an array of products via WC_Product_Query.
	 *
	 * @param array $args See wc_get_products().
	 * @return array
	 */
	public function get_products( $args = array() ) {
		// Drive `wc_get_products()` through the data-store-level query
		// directly. Going via `WC_Product_Query::get_products()` would
		// loop back through `WC_Data_Store::load('product')->query()`,
		// which is the same code path with extra hops; doing it here
		// cuts the indirection and makes the legacy fallback explicit.
		$results = $this->query( wp_parse_args( $args, ( new \WC_Product_Query() )->get_query_vars() ) );

		if ( is_object( $results ) && isset( $results->products ) ) {
			return $results->products;
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * `WC_Data_Store::load('product')->query()` entry point.
	 *
	 * Routes column-mappable queries through {@see ProductsTableQuery}
	 * for an HPPS-native SELECT. Anything that needs taxonomy joins,
	 * arbitrary `meta_query` / `tax_query`, full-text search, date
	 * queries, or `reviews_allowed` falls back to the legacy CPT data
	 * store via {@see get_legacy_data_store()}.
	 *
	 * The fallback is the safety net for two distinct cases:
	 *
	 *   1. Query features HPPS hasn't implemented natively yet — they
	 *      stay correct (and slow) until they migrate over.
	 *   2. Stores where the migration hasn't completed yet — running
	 *      against `wc_products` would silently miss the unmigrated
	 *      half of the catalogue.
	 *
	 * @since 10.9.0
	 *
	 * @param array $query_vars Query vars from `WC_Product_Query`.
	 * @return array<int, int>|array<int, WC_Product>|object
	 */
	public function query( $query_vars ) {
		$query = new ProductsTableQuery( (array) $query_vars );

		if ( ! $query->is_supported() || ! $this->migration_is_complete() ) {
			return $this->get_legacy_data_store()->query( $query_vars );
		}

		return $query->get_results();
	}

	/**
	 * Whether the HPPS migration has finished for this site. While the
	 * back-fill is still running, `wc_products` only contains a subset
	 * of the catalogue — querying it would silently drop the unmigrated
	 * remainder. Routing through the legacy CPT data store keeps the
	 * result set complete.
	 *
	 * @return bool
	 */
	private function migration_is_complete(): bool {
		try {
			return wc_get_container()
				->get( ProductDataSynchronizer::class )
				->migration_is_complete();
		} catch ( \Throwable $e ) {
			// If the synchronizer can't be resolved (very early hook
			// fire, container reset mid-request), fall back to the
			// legacy path — safer than guessing.
			return false;
		}
	}

	/**
	 * Get the product type for a given product ID.
	 *
	 * @param int $product_id Product ID.
	 * @return bool|string
	 */
	public function get_product_type( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$product_id
			)
		);

		if ( $type ) {
			return (string) $type;
		}

		// Unmigrated product: defer to the legacy resolver so the product
		// factory still returns the right WC_Product_* subclass.
		return $this->get_legacy_data_store()->get_product_type( $product_id );
	}

	/**
	 * Returns query SQL for stock — used by some stock-overlap detection paths.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function get_query_for_stock( $product_id ) {
		global $wpdb;

		return $wpdb->prepare(
			'SELECT COALESCE( stock_quantity, 0 ) FROM ' . self::get_products_table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $product_id
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Methods that the legacy CPT data store exposes to core callers.
	|
	| These are not part of the WC_Product_Data_Store_Interface contract but
	| they ARE called from REST controllers, the admin Products list, and the
	| classic AJAX endpoints. Without native HPPS implementations the calls
	| would dead-end in WC_Data_Store::__call() returning null, silently
	| breaking admin search, "Generate variations", and review counters.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Search products by title, SKU, and global unique ID.
	 *
	 * Mirrors the legacy CPT data store's `search_products()` shape so that
	 * the admin Products list table search box, the product autocomplete
	 * AJAX endpoint, and the downloadable-product picker keep returning real
	 * results when HPPS is on.
	 *
	 * Strategy: query `wc_products` directly (which has indexed columns for
	 * `name` and `sku`). For variations, also probe the parent's row so that
	 * a parent's SKU resolves its variations the way the legacy lookup-table
	 * join does.
	 *
	 * @param string     $term               Search term.
	 * @param string     $type               Optional product type filter (`virtual`, `downloadable`, ...).
	 * @param bool       $include_variations Whether to include variations in the result set.
	 * @param bool       $all_statuses       Search across every status (otherwise publish + private when allowed).
	 * @param int|null   $limit              Optional cap on the result count.
	 * @param array|null $include            Whitelist of IDs to keep.
	 * @param array|null $exclude            Blacklist of IDs to drop.
	 * @return int[] List of product IDs.
	 */
	public function search_products( $term, $type = '', $include_variations = false, $all_statuses = false, $limit = null, $include = null, $exclude = null ) {
		global $wpdb;

		/**
		 * Filter to short-circuit HPPS product search with a custom result.
		 *
		 * Mirrors `woocommerce_product_pre_search_products` from the legacy
		 * data store so existing extensions that hook there keep working
		 * without modification.
		 *
		 * @since 10.9.0
		 *
		 * @param false|array $custom_results Pre-computed result set, or false to fall through.
		 * @param string      $term           Search term.
		 * @param string      $type           Type filter.
		 * @param bool        $include_variations Variations included.
		 * @param bool        $all_statuses   All statuses included.
		 * @param int|null    $limit          Result cap.
		 */
		$custom_results = apply_filters( 'woocommerce_product_pre_search_products', false, $term, $type, $include_variations, $all_statuses, $limit );
		if ( is_array( $custom_results ) ) {
			return $custom_results;
		}

		$products_table = self::get_products_table_name();
		$types          = $include_variations
			? array( 'simple', 'variable', 'grouped', 'external', 'variation' )
			: array( 'simple', 'variable', 'grouped', 'external' );

		/**
		 * Filter the post statuses considered when searching products.
		 *
		 * @since 10.9.0
		 *
		 * @param string[] $statuses Statuses to include.
		 */
		$statuses = apply_filters(
			'woocommerce_search_products_post_statuses',
			current_user_can( 'edit_private_products' ) ? array( 'private', 'publish' ) : array( 'publish' )
		);

		$where  = array();
		$params = array();

		// Type column scope (always applied).
		$type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$where[]           = "p.type IN ({$type_placeholders})";
		$params            = array_merge( $params, $types );

		// Status scope unless caller asked for everything.
		if ( ! $all_statuses ) {
			$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]             = "p.status IN ({$status_placeholders})";
			$params              = array_merge( $params, $statuses );
		}

		// Build the OR-set across name / sku / global_unique_id. Honour
		// space-and-quote tokenisation the way the legacy store does so that
		// merchant muscle memory ("nike air") still works.
		$term_groups = stristr( $term, ' or ' ) ? preg_split( '/\s+or\s+/i', $term ) : array( $term );

		$group_clauses = array();
		foreach ( $term_groups as $term_group ) {
			$tokens = $this->tokenize_search_term( (string) $term_group );
			if ( empty( $tokens ) ) {
				continue;
			}

			$token_clauses = array();
			foreach ( $tokens as $token ) {
				$like            = '%' . $wpdb->esc_like( $token ) . '%';
				$token_clauses[] = $wpdb->prepare(
					'( p.name LIKE %s OR p.short_description LIKE %s OR p.description LIKE %s OR p.sku LIKE %s OR p.global_unique_id LIKE %s )',
					$like,
					$like,
					$like,
					$like,
					$like
				);
			}

			if ( ! empty( $token_clauses ) ) {
				$group_clauses[] = '(' . implode( ' AND ', $token_clauses ) . ')';
			}
		}

		if ( ! empty( $group_clauses ) ) {
			$where[] = '(' . implode( ' OR ', $group_clauses ) . ')';
		}

		// Type-specific narrowing matches the legacy contract.
		if ( 'virtual' === $type ) {
			$where[] = 'p.virtual = 1';
		} elseif ( 'downloadable' === $type ) {
			$where[] = 'p.downloadable = 1';
		}

		if ( ! empty( $include ) && is_array( $include ) ) {
			$where[] = 'p.id IN (' . implode( ',', array_map( 'absint', $include ) ) . ')';
		}
		if ( ! empty( $exclude ) && is_array( $exclude ) ) {
			$where[] = 'p.id NOT IN (' . implode( ',', array_map( 'absint', $exclude ) ) . ')';
		}

		$where_clause = implode( ' AND ', $where );

		$limit_clause = '';
		if ( $limit ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d', (int) $limit );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			empty( $params )
				? "SELECT DISTINCT p.id, p.parent_id FROM {$products_table} p WHERE {$where_clause} ORDER BY p.parent_id ASC, p.name ASC{$limit_clause}"
				: $wpdb->prepare( "SELECT DISTINCT p.id, p.parent_id FROM {$products_table} p WHERE {$where_clause} ORDER BY p.parent_id ASC, p.name ASC{$limit_clause}", $params )
		);
		// phpcs:enable

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$ids[] = (int) $row->id;
			if ( $include_variations && ! empty( $row->parent_id ) ) {
				$ids[] = (int) $row->parent_id;
			}
		}

		// Numeric search term should also resolve directly by ID, mirroring
		// the legacy data store's behaviour (operators paste an order line's
		// product ID into the box and expect it to surface).
		if ( is_numeric( $term ) ) {
			$post_id   = absint( $term );
			$row_type  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$products_table} WHERE id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parent_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$products_table} WHERE id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( 'variation' === $row_type && $include_variations ) {
				$ids[] = $post_id;
			} elseif ( '' !== $row_type && 'variation' !== $row_type ) {
				$ids[] = $post_id;
			}
			if ( $parent_id ) {
				$ids[] = $parent_id;
			}
		}

		return wp_parse_id_list( $ids );
	}

	/**
	 * Tokenise a search term the way the legacy store does (quoted phrases,
	 * stop-word collapse, sentence fallback for very long inputs).
	 *
	 * @param string $term_group A single OR-segment of the user input.
	 * @return string[]
	 */
	protected function tokenize_search_term( string $term_group ): array {
		if ( '' === trim( $term_group ) ) {
			return array();
		}

		if ( preg_match_all( '/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/', $term_group, $matches ) ) {
			$tokens = array_filter( array_map( 'trim', (array) $matches[0] ) );
			$count  = count( $tokens );
			if ( $count > 9 || 0 === $count ) {
				$tokens = array( $term_group );
			}
		} else {
			$tokens = array( $term_group );
		}

		return array_values( array_filter( array_map( 'trim', $tokens ), 'strlen' ) );
	}

	/**
	 * Generate every missing variation for a variable product.
	 *
	 * Wired by `WC_AJAX::link_all_variations` and the REST `variations/generate`
	 * endpoint. The algorithm itself is identical to the legacy CPT store —
	 * the variation objects we produce go through `WC_Product_Variation::save()`,
	 * which in turn lands in `ProductsTableVariationDataStore::create()` because
	 * of the data-store filter swap. So we only need to host the orchestration
	 * here, not duplicate the persistence logic.
	 *
	 * @param \WC_Product $product        Parent variable product.
	 * @param int         $limit          Hard cap on the number of new variations to create. -1 = unlimited.
	 * @param array       $default_values Default property values applied to every new variation.
	 * @param array       $metadata       Additional meta (each item: ['key' => ..., 'value' => ...]).
	 * @return int Count of new variations created.
	 */
	public function create_all_product_variations( $product, $limit = -1, $default_values = array(), $metadata = array() ) {
		$count = 0;

		if ( ! $product instanceof \WC_Product ) {
			return $count;
		}

		$attributes = wc_list_pluck(
			array_filter( $product->get_attributes(), 'wc_attributes_array_filter_variation' ),
			'get_slugs'
		);
		if ( empty( $attributes ) ) {
			return $count;
		}

		// Snapshot existing variation attribute combinations so we don't
		// duplicate them on a re-run.
		$existing_attributes = array();
		$child_ids           = $product->get_children();
		if ( ! empty( $child_ids ) ) {
			_prime_post_caches( $child_ids );
			foreach ( $child_ids as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$existing_attributes[] = $child->get_attributes();
				}
			}
		}

		$possible_attributes = array_reverse( wc_array_cartesian( $attributes ) );
		$product_id          = $product->get_id();

		foreach ( $possible_attributes as $possible_attribute ) {
			// Loose-match (intentional non-strict) because attribute key
			// order isn't guaranteed across stores/extensions.
			if ( in_array( $possible_attribute, $existing_attributes, false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
				continue;
			}

			$variation = wc_get_product_object( \Automattic\WooCommerce\Enums\ProductType::VARIATION );
			$variation->set_props( $default_values );
			foreach ( $metadata as $meta ) {
				if ( isset( $meta['key'] ) ) {
					$variation->add_meta_data( $meta['key'], $meta['value'] ?? '' );
				}
			}
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( $possible_attribute );
			$variation_id = $variation->save();

			/**
			 * Fires once per newly created variation, mirroring the legacy hook.
			 *
			 * @since 10.9.0
			 *
			 * @param int $variation_id Newly inserted variation ID.
			 */
			do_action( 'product_variation_linked', $variation_id );

			++$count;
			if ( $limit > 0 && $count >= $limit ) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Persist a product's average rating.
	 *
	 * Stores the value on the `wc_products.average_rating` column, refreshes
	 * the `wc_product_meta_lookup` row, and keeps the legacy `_wc_average_rating`
	 * postmeta in sync so any extension still reading via `get_post_meta()`
	 * sees the same value.
	 *
	 * @param \WC_Product $product Product object.
	 * @return void
	 */
	public function update_average_rating( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		global $wpdb;

		$product_id = (int) $product->get_id();
		$rating     = $product->get_average_rating( 'edit' );

		$wpdb->update(
			self::get_products_table_name(),
			array( 'average_rating' => $rating ),
			array( 'id' => $product_id ),
			array( '%f' ),
			array( '%d' )
		);

		update_post_meta( $product_id, '_wc_average_rating', $rating );
		$this->update_lookup_table( $product_id );
		$this->sync_visibility_terms( $product, true );
	}

	/**
	 * Persist a product's review count.
	 *
	 * @param \WC_Product $product Product object.
	 * @return void
	 */
	public function update_review_count( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		global $wpdb;

		$product_id = (int) $product->get_id();
		$count      = (int) $product->get_review_count( 'edit' );

		$wpdb->update(
			self::get_products_table_name(),
			array( 'review_count' => $count ),
			array( 'id' => $product_id ),
			array( '%d' ),
			array( '%d' )
		);

		update_post_meta( $product_id, '_wc_review_count', $count );
		$this->update_lookup_table( $product_id );
	}

	/**
	 * Persist a product's per-star rating distribution.
	 *
	 * The aggregate count lives on `wc_products.rating_count`; the per-star
	 * breakdown (1-5) is keyed in `wc_products_meta` under `_wc_rating_count`
	 * so an extension drawing the histogram still resolves the structure via
	 * `$product->get_meta()`.
	 *
	 * @param \WC_Product $product Product object.
	 * @return void
	 */
	public function update_rating_counts( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		global $wpdb;

		$product_id = (int) $product->get_id();
		$counts     = $product->get_rating_counts( 'edit' );
		if ( ! is_array( $counts ) ) {
			$counts = array();
		}
		$total = array_sum( array_map( 'absint', $counts ) );

		$wpdb->update(
			self::get_products_table_name(),
			array( 'rating_count' => $total ),
			array( 'id' => $product_id ),
			array( '%d' ),
			array( '%d' )
		);

		update_post_meta( $product_id, '_wc_rating_count', $counts );
		$this->update_lookup_table( $product_id );
	}

}
