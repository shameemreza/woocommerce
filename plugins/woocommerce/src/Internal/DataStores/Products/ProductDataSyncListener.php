<?php
/**
 * ProductDataSyncListener class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors third-party `update_post_meta()` / `add_post_meta()` writes on
 * product and product_variation posts back into the corresponding columns of
 * the `wc_products` table.
 *
 * Why this exists: the Phase 1 HPPS data store routes every WooCommerce-API
 * write through both stores, but plugins that bypass the API (a payment
 * extension that does `update_post_meta( $product_id, '_price', '9.99' )`,
 * an importer that pokes `_stock` directly) silently drift away from the
 * HPPS-side value. Until Phase 2 ships full bidirectional sync, this
 * listener catches the most common drift sources and keeps `wc_products`
 * coherent so the storefront and admin list tables don't show stale data.
 *
 * The listener is a no-op unless:
 *
 *  - The HPPS feature is enabled, AND
 *  - The data-sync option ({@see ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION})
 *    is `'yes'` (or the `woocommerce_hpps_data_sync_enabled` filter forces it on).
 *
 * That gate makes the listener safe to register unconditionally: stores that
 * haven't enabled HPPS pay zero overhead, and the option lets operators
 * disable the mirroring during a known-bad migration without disabling HPPS
 * altogether.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductDataSyncListener {

	/**
	 * Postmeta keys whose payload is composite — they don't map to a
	 * single `wc_products` column but to rows in `wc_product_attributes`
	 * and `wc_product_attribute_values`. Bypass {@see maybe_sync()} and
	 * route to {@see maybe_sync_attributes()} instead.
	 *
	 * @var string[]
	 */
	private const COMPOSITE_ATTRIBUTE_META_KEYS = array(
		'_product_attributes',
		'_default_attributes',
	);

	/**
	 * Map of WordPress meta keys to the column name in `wc_products` they
	 * mirror. Keys are deliberately conservative: only the meta paths that
	 * have a 1:1 column counterpart and where third-party plugins are most
	 * likely to write directly. Side-table data (`_product_attributes` →
	 * `wc_product_attributes` / `wc_product_attribute_values`,
	 * `_default_attributes`) is handled separately via the dedicated
	 * attribute mirror methods because each write touches two tables and
	 * needs the legacy postmeta shape decoded into structured rows.
	 *
	 * Each entry declares:
	 *   - `column`: target column in `wc_products`.
	 *   - `type`:   how to coerce the postmeta scalar into the column shape.
	 *   - `gate`:   optional precondition. Currently only `'cogs'`, which
	 *               skips the entry whenever the Cost of Goods Sold
	 *               feature is disabled. The HPPS column stays NULL on
	 *               those stores, and the listener avoids writing empty
	 *               postmeta back to legacy when no one is watching.
	 *
	 * @var array<string, array{column: string, type: string, gate?: string}>
	 */
	private const META_TO_COLUMN_MAP = array(
		'_sku'                   => array(
			'column' => 'sku',
			'type'   => 'string',
		),
		'_global_unique_id'      => array(
			'column' => 'global_unique_id',
			'type'   => 'string',
		),
		'_price'                 => array(
			'column' => 'price',
			'type'   => 'decimal',
		),
		'_regular_price'         => array(
			'column' => 'regular_price',
			'type'   => 'decimal',
		),
		'_sale_price'            => array(
			'column' => 'sale_price',
			'type'   => 'decimal',
		),
		'_sale_price_dates_from' => array(
			'column' => 'date_on_sale_from',
			'type'   => 'date',
		),
		'_sale_price_dates_to'   => array(
			'column' => 'date_on_sale_to',
			'type'   => 'date',
		),
		'_stock'                 => array(
			'column' => 'stock_quantity',
			'type'   => 'decimal',
		),
		'_stock_status'          => array(
			'column' => 'stock_status',
			'type'   => 'string',
		),
		'_manage_stock'          => array(
			'column' => 'manage_stock',
			'type'   => 'bool',
		),
		'_backorders'            => array(
			'column' => 'backorders',
			'type'   => 'string',
		),
		'_low_stock_amount'      => array(
			'column' => 'low_stock_amount',
			'type'   => 'int',
		),
		'_sold_individually'     => array(
			'column' => 'sold_individually',
			'type'   => 'bool',
		),
		'_weight'                => array(
			'column' => 'weight',
			'type'   => 'string',
		),
		'_length'                => array(
			'column' => 'length',
			'type'   => 'string',
		),
		'_width'                 => array(
			'column' => 'width',
			'type'   => 'string',
		),
		'_height'                => array(
			'column' => 'height',
			'type'   => 'string',
		),
		'_virtual'               => array(
			'column' => 'virtual',
			'type'   => 'bool',
		),
		'_downloadable'          => array(
			'column' => 'downloadable',
			'type'   => 'bool',
		),
		'_featured'              => array(
			'column' => 'featured',
			'type'   => 'bool',
		),
		'_visibility'            => array(
			'column' => 'catalog_visibility',
			'type'   => 'string',
		),
		'_tax_status'            => array(
			'column' => 'tax_status',
			'type'   => 'string',
		),
		'_tax_class'             => array(
			'column' => 'tax_class',
			'type'   => 'string',
		),
		'_thumbnail_id'          => array(
			'column' => 'image_id',
			'type'   => 'int',
		),
		'_product_image_gallery' => array(
			'column' => 'gallery_image_ids',
			'type'   => 'string',
		),
		'_purchase_note'         => array(
			'column' => 'purchase_note',
			'type'   => 'string',
		),
		'_download_limit'        => array(
			'column' => 'download_limit',
			'type'   => 'int',
		),
		'_download_expiry'       => array(
			'column' => 'download_expiry',
			'type'   => 'int',
		),
		// Note: the legacy CPT data store stores the running counter under the
		// bare `total_sales` key (no leading underscore — see
		// WC_Product_Data_Store_CPT::update_product_sales). Mapping the wrong
		// key here silently dropped every checkout's update on the floor and
		// drifted best-sellers / sort-by-popularity / analytics until the
		// next full save.
		'total_sales'            => array(
			'column' => 'total_sales',
			'type'   => 'int',
		),
		'_wc_average_rating'     => array(
			'column' => 'average_rating',
			'type'   => 'decimal',
		),
		'_wc_rating_count'       => array(
			'column' => 'rating_count',
			'type'   => 'int',
		),
		'_wc_review_count'       => array(
			'column' => 'review_count',
			'type'   => 'int',
		),
		'_cogs_total_value'      => array(
			'column' => 'cogs_value',
			'type'   => 'decimal',
			'gate'   => 'cogs',
		),
	);

	/**
	 * Reentrancy depth counter. Flipped via {@see start_internal_write()}
	 * and {@see end_internal_write()} so that HPPS-internal writes which
	 * need to also update postmeta (when Phase 2 lands) don't echo back
	 * into this listener and double-write.
	 *
	 * @var int
	 */
	private static int $internal_write_depth = 0;

	/**
	 * Synchronizer dependency; consulted on every meta hook to decide
	 * whether to act.
	 *
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $synchronizer;

	/**
	 * Inject dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param ProductDataSynchronizer $synchronizer Synchronizer dependency.
	 */
	final public function init( ProductDataSynchronizer $synchronizer ): void {
		$this->synchronizer = $synchronizer;
	}

	/**
	 * Start a bracketed region in which postmeta writes performed by HPPS
	 * itself will be ignored by this listener. Pair every call with
	 * {@see end_internal_write()} via try/finally.
	 *
	 * @return void
	 */
	public static function start_internal_write(): void {
		++self::$internal_write_depth;
	}

	/**
	 * End the bracketed region opened by {@see start_internal_write()}.
	 *
	 * @return void
	 */
	public static function end_internal_write(): void {
		if ( self::$internal_write_depth > 0 ) {
			--self::$internal_write_depth;
		}
	}

	/**
	 * Wire WordPress hooks. Called once from {@see CustomProductsTableController::register_hooks()}.
	 *
	 * Listening at priority 20 (after WP's default 10) so any plugin that
	 * intercepts and rewrites the meta value gets the last word before we
	 * mirror it.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// CPT → HPPS direction: catch postmeta writes and mirror them into
		// the wc_products columns (and, for `_product_attributes` /
		// `_default_attributes`, into the attribute side tables).
		add_action( 'updated_post_meta', array( $this, 'on_post_meta_updated' ), 20, 4 );
		add_action( 'added_post_meta', array( $this, 'on_post_meta_added' ), 20, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_post_meta_deleted' ), 20, 4 );

		// HPPS → CPT direction: after a save through the WC API completes,
		// push the fresh column values back into postmeta so legacy reads
		// (third-party plugins that bypass the WC API, REST endpoints not
		// migrated to HPPS, custom CPT queries) see the updated values.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_product_saved' ), 20, 1 );

		// HPPS → CPT direction for attributes specifically. The HPPS data
		// store fires this after persist_attributes() has rewritten the
		// side tables, which is exactly the moment the postmeta blobs
		// need to be regenerated.
		add_action( 'woocommerce_product_attributes_updated', array( $this, 'on_attributes_updated' ), 20, 2 );
	}

	/**
	 * `updated_post_meta` handler. Fires after WP has persisted the new value.
	 *
	 * @internal
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_post_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );

		if ( in_array( (string) $meta_key, self::COMPOSITE_ATTRIBUTE_META_KEYS, true ) ) {
			$this->maybe_sync_attributes( (int) $object_id );
			return;
		}

		$this->maybe_sync( (int) $object_id, (string) $meta_key, $meta_value );
	}

	/**
	 * `added_post_meta` handler. Fires the first time a meta key is set.
	 *
	 * @internal
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_post_meta_added( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );

		if ( in_array( (string) $meta_key, self::COMPOSITE_ATTRIBUTE_META_KEYS, true ) ) {
			$this->maybe_sync_attributes( (int) $object_id );
			return;
		}

		$this->maybe_sync( (int) $object_id, (string) $meta_key, $meta_value );
	}

	/**
	 * `deleted_post_meta` handler. We treat a delete as "set to the empty
	 * canonical value" for the column type.
	 *
	 * @internal
	 *
	 * @param int[]|int $meta_ids  Meta row IDs.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @param mixed     $meta_value Old value (unused).
	 * @return void
	 */
	public function on_post_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_ids, $meta_value );

		if ( in_array( (string) $meta_key, self::COMPOSITE_ATTRIBUTE_META_KEYS, true ) ) {
			$this->maybe_sync_attributes( (int) $object_id );
			return;
		}

		$this->maybe_sync( (int) $object_id, (string) $meta_key, '' );
	}

	/**
	 * `woocommerce_(new|update)_product(_variation)?` handler. After the
	 * data store finishes writing the wc_products row, read it back and
	 * push the column values into postmeta so legacy reads stay fresh.
	 *
	 * No-op unless data sync is enabled and the product has actually
	 * landed in HPPS (the legacy fallback path doesn't need writeback;
	 * the CPT data store already wrote postmeta itself).
	 *
	 * @internal
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public function on_product_saved( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		if ( self::$internal_write_depth > 0 ) {
			return;
		}

		if ( ! $this->synchronizer->data_sync_is_enabled() ) {
			return;
		}

		if ( ! $this->product_exists_in_hpps( $product_id ) ) {
			return;
		}

		$this->mirror_columns_to_postmeta( $product_id );
	}

	/**
	 * `woocommerce_product_attributes_updated` handler. The HPPS data
	 * store fires this after `persist_attributes()` has rewritten the
	 * side tables, which is when the legacy postmeta blobs
	 * (`_product_attributes`, `_default_attributes`) need to be
	 * regenerated.
	 *
	 * Bails for products that don't exist in HPPS (saves through the
	 * legacy CPT data store fallback path don't need a writeback —
	 * the CPT store wrote the postmeta itself).
	 *
	 * @internal
	 *
	 * @param mixed $product Product object passed by the action.
	 * @param mixed $force   Whether the caller forced the update (unused).
	 * @return void
	 */
	public function on_attributes_updated( $product, $force = false ): void {
		unset( $force );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		if ( self::$internal_write_depth > 0 ) {
			return;
		}

		if ( ! $this->synchronizer->data_sync_is_enabled() ) {
			return;
		}

		if ( ! $this->product_exists_in_hpps( $product_id ) ) {
			return;
		}

		$this->mirror_attributes_to_postmeta( $product_id );
	}

	/**
	 * Read the canonical wc_products row and replay every mapped column
	 * into postmeta in one go.
	 *
	 * Bracketed by {@see start_internal_write()} / {@see end_internal_write()}
	 * so the inverse listener (postmeta → column) doesn't echo our writes
	 * back into wc_products. The guard is depth-counted, so reentrancy
	 * from extensions that hook `updated_post_meta` and trigger another
	 * product save stays safe.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function mirror_columns_to_postmeta( int $product_id ): void {
		global $wpdb;

		// Gates are evaluated up front so we don't read columns we won't
		// write. A store that never enabled COGS shouldn't pay the cost
		// of pulling `cogs_value` out of the row, and shouldn't get a
		// stray `_cogs_total_value` postmeta entry for every product.
		$active_mappings = array_filter(
			self::META_TO_COLUMN_MAP,
			fn( array $mapping ): bool => $this->mapping_gate_satisfied( $mapping )
		);

		if ( empty( $active_mappings ) ) {
			return;
		}

		$columns_csv = implode(
			', ',
			array_map(
				static fn( array $mapping ): string => '`' . $mapping['column'] . '`',
				$active_mappings
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . $columns_csv . ' FROM ' . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d LIMIT 1',
				$product_id
			)
		);

		if ( ! $row ) {
			return;
		}

		self::start_internal_write();
		try {
			foreach ( $active_mappings as $meta_key => $mapping ) {
				$column         = $mapping['column'];
				$postmeta_value = $this->coerce_for_postmeta( $mapping['type'], $row->{$column} ?? null );
				update_post_meta( $product_id, $meta_key, $postmeta_value );
			}
		} finally {
			self::end_internal_write();
		}
	}

	/**
	 * Decide whether to mirror this write and route to {@see write_column()}.
	 *
	 * Bails for any of:
	 *  - we're inside an HPPS-internal write,
	 *  - HPPS feature is off,
	 *  - data sync is off,
	 *  - the meta key isn't in our map,
	 *  - the post isn't a `product` or `product_variation`.
	 *
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	private function maybe_sync( int $object_id, string $meta_key, $meta_value ): void {
		if ( self::$internal_write_depth > 0 ) {
			return;
		}

		if ( ! isset( self::META_TO_COLUMN_MAP[ $meta_key ] ) ) {
			return;
		}

		if ( $object_id <= 0 ) {
			return;
		}

		if ( ! $this->synchronizer->data_sync_is_enabled() ) {
			return;
		}

		// Ignore non-product posts. Cheap pre-check before the table query.
		$post_type = get_post_type( $object_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type && 'product_placeholder' !== $post_type ) {
			return;
		}

		// Ignore products that haven't been migrated to HPPS yet — there's
		// nothing to update on the HPPS side. The next save through the WC
		// API will materialize the row and pick up the latest postmeta.
		if ( ! $this->product_exists_in_hpps( $object_id ) ) {
			return;
		}

		$mapping = self::META_TO_COLUMN_MAP[ $meta_key ];

		if ( ! $this->mapping_gate_satisfied( $mapping ) ) {
			return;
		}

		$this->write_column( $object_id, $mapping['column'], $mapping['type'], $meta_value );
	}

	/**
	 * Return whether the optional `gate` condition on a mapping is met.
	 *
	 * Gates exist for columns that only carry meaningful values when an
	 * adjacent feature is on. The COGS column is the canonical example:
	 * sites that never enabled Cost of Goods Sold should keep their
	 * `wc_products.cogs_value` rows at NULL and their `_cogs_total_value`
	 * postmeta unset, regardless of what a third-party plugin writes.
	 *
	 * Mappings without a `gate` always sync.
	 *
	 * @param array{column: string, type: string, gate?: string} $mapping Mapping entry.
	 * @return bool
	 */
	private function mapping_gate_satisfied( array $mapping ): bool {
		$gate = $mapping['gate'] ?? '';

		if ( '' === $gate ) {
			return true;
		}

		if ( 'cogs' === $gate ) {
			return wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled();
		}

		// Unknown gate name — fail closed so we don't silently mirror
		// values whose preconditions we couldn't verify.
		return false;
	}

	/**
	 * Push the coerced value into a single column on `wc_products`.
	 *
	 * Intentionally does not go through `ProductsTableDataStore::update()`:
	 * we don't want to reload the WC_Product, fire `woocommerce_update_product`,
	 * or rebuild the lookup table for a single-meta-key write. The lookup
	 * table is kept fresh by the next full save through the WC API, and
	 * read paths only look at the columns we touch here.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $column     Column name in `wc_products`.
	 * @param string $type       Type tag from META_TO_COLUMN_MAP.
	 * @param mixed  $raw_value  Value as it arrived from postmeta.
	 * @return void
	 */
	private function write_column( int $product_id, string $column, string $type, $raw_value ): void {
		global $wpdb;

		$value = $this->coerce_for_column( $type, $raw_value );

		$format = $this->format_specifier( $type );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			ProductsTableDataStore::get_products_table_name(),
			array(
				$column             => $value,
				'date_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $product_id ),
			array( $format, '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Convert a raw postmeta scalar into the type the column expects.
	 *
	 * @param string $type      Type tag.
	 * @param mixed  $raw_value Raw value.
	 * @return mixed Coerced value (scalar or null).
	 */
	private function coerce_for_column( string $type, $raw_value ) {
		switch ( $type ) {
			case 'bool':
				if ( '' === $raw_value || null === $raw_value ) {
					return 0;
				}
				return ( 'yes' === $raw_value || true === $raw_value || '1' === (string) $raw_value ) ? 1 : 0;

			case 'int':
				return ( '' === $raw_value || null === $raw_value ) ? null : (int) $raw_value;

			case 'decimal':
				return ( '' === $raw_value || null === $raw_value ) ? null : (string) wc_format_decimal( $raw_value );

			case 'date':
				return $this->coerce_date( $raw_value );

			case 'string':
			default:
				return null === $raw_value ? '' : (string) $raw_value;
		}
	}

	/**
	 * Inverse of {@see coerce_for_column()} — convert a `wc_products`
	 * column value into the shape postmeta expects.
	 *
	 * Mirrors the legacy CPT data store's serialization conventions:
	 * - `bool` columns become `'yes'` / `'no'` strings.
	 * - `int` and `decimal` columns become stringified numbers, with
	 *   `null` represented as an empty string (CPT convention for
	 *   "unset", e.g. an empty `_stock` meta).
	 * - `date` columns become Unix timestamps (matches how
	 *   `_sale_price_dates_from`/`_sale_price_dates_to` are stored).
	 * - `string` columns pass through, with `null` collapsed to `''`.
	 *
	 * @param string $type      Type tag from the meta map.
	 * @param mixed  $row_value Value as stored in `wc_products`.
	 * @return string Postmeta-shaped value.
	 */
	private function coerce_for_postmeta( string $type, $row_value ): string {
		switch ( $type ) {
			case 'bool':
				return ( 1 === (int) $row_value ) ? 'yes' : 'no';

			case 'int':
				return ( null === $row_value || '' === $row_value ) ? '' : (string) (int) $row_value;

			case 'decimal':
				if ( null === $row_value || '' === $row_value ) {
					return '';
				}
				return (string) wc_format_decimal( $row_value );

			case 'date':
				if ( null === $row_value || '' === $row_value ) {
					return '';
				}
				$timestamp = is_numeric( $row_value ) ? (int) $row_value : strtotime( (string) $row_value );
				return ( false === $timestamp || 0 === $timestamp ) ? '' : (string) $timestamp;

			case 'string':
			default:
				return null === $row_value ? '' : (string) $row_value;
		}
	}

	/**
	 * Format specifier for `$wpdb->update()` matching {@see coerce_for_column()}.
	 *
	 * @param string $type Type tag.
	 * @return string Format specifier (`%s`, `%d`, `%f`).
	 */
	private function format_specifier( string $type ): string {
		switch ( $type ) {
			case 'bool':
			case 'int':
				return '%d';
			case 'decimal':
				return '%f';
			case 'date':
			case 'string':
			default:
				return '%s';
		}
	}

	/**
	 * Convert various postmeta date representations (Unix timestamp string,
	 * Y-m-d H:i:s, empty) to the `Y-m-d H:i:s` shape `wc_products` stores.
	 *
	 * @param mixed $raw_value Raw value.
	 * @return string|null
	 */
	private function coerce_date( $raw_value ): ?string {
		if ( '' === $raw_value || null === $raw_value || 0 === $raw_value || '0' === $raw_value ) {
			return null;
		}

		// Numeric-looking values are treated as Unix timestamps to match
		// how the legacy CPT data store writes `_sale_price_dates_*`.
		if ( is_numeric( $raw_value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $raw_value );
		}

		$timestamp = strtotime( (string) $raw_value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Decide whether to sync attributes for a postmeta event on
	 * `_product_attributes` or `_default_attributes`. Mirrors the
	 * gating in {@see maybe_sync()} but routes to the side-table
	 * projection rather than a single-column write.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	private function maybe_sync_attributes( int $object_id ): void {
		if ( self::$internal_write_depth > 0 ) {
			return;
		}

		if ( $object_id <= 0 ) {
			return;
		}

		if ( ! $this->synchronizer->data_sync_is_enabled() ) {
			return;
		}

		$post_type = get_post_type( $object_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type && 'product_placeholder' !== $post_type ) {
			return;
		}

		if ( ! $this->product_exists_in_hpps( $object_id ) ) {
			return;
		}

		$this->mirror_attributes_to_hpps( $object_id );
	}

	/**
	 * Atomically rebuild `wc_product_attributes` and
	 * `wc_product_attribute_values` from the postmeta blobs
	 * `_product_attributes` and `_default_attributes`.
	 *
	 * Strategy mirrors {@see ProductsTableDataStore::persist_attributes()}:
	 * delete every existing row for the product and insert fresh ones,
	 * inside a transaction so a partial write can never land. The
	 * legacy postmeta shape decoding follows
	 * {@see WC_Product_Data_Store_CPT::read_attributes()} so an importer
	 * or pricing plugin that writes `_product_attributes` directly gets
	 * the same result HPPS would have produced via a full WC API save.
	 *
	 * Wrapped in {@see start_internal_write()} / {@see end_internal_write()}
	 * to keep the inverse listener
	 * ({@see on_attributes_updated()}, plus any `wp_set_object_terms()`
	 * cascades) from echoing back through this same code path.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function mirror_attributes_to_hpps( int $product_id ): void {
		global $wpdb;

		$meta_attributes = get_post_meta( $product_id, '_product_attributes', true );
		if ( ! is_array( $meta_attributes ) ) {
			$meta_attributes = array();
		}

		$default_attributes = get_post_meta( $product_id, '_default_attributes', true );
		if ( ! is_array( $default_attributes ) ) {
			$default_attributes = array();
		}

		$attributes_table = ProductsTableDataStore::get_attributes_table_name();
		$values_table     = ProductsTableDataStore::get_attribute_values_table_name();

		// Snapshot the parent's existing (name => attribute_id) mapping so
		// we can remap variation-scope rows after the rebuild — see
		// {@see ProductsTableDataStore::rebind_variation_attribute_ids()}.
		$old_attribute_ids_by_name = ProductsTableDataStore::snapshot_attribute_ids_by_name( $product_id );

		self::start_internal_write();
		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );

			try {
				$wpdb->delete( $values_table, array( 'product_id' => $product_id ), array( '%d' ) );
				$wpdb->delete( $attributes_table, array( 'product_id' => $product_id ), array( '%d' ) );

				foreach ( $meta_attributes as $meta_attribute ) {
					$attribute_row_id = $this->insert_attribute_row( $product_id, (array) $meta_attribute, $attributes_table );
					if ( null === $attribute_row_id ) {
						continue;
					}
					$this->insert_attribute_values( $product_id, $attribute_row_id, (array) $meta_attribute, $values_table );
				}

				$this->insert_default_attributes( $product_id, $default_attributes, $attributes_table, $values_table );

				$wpdb->query( 'COMMIT' );
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Re-issue any variation-scope row's attribute_id to the new
			// post-rebuild value, matched by name. Done outside the
			// transaction (the snapshot+update are independent of the
			// rebuild's atomicity, and a failure here is recoverable on
			// next variation save).
			$new_attribute_ids_by_name = ProductsTableDataStore::snapshot_attribute_ids_by_name( $product_id );
			ProductsTableDataStore::rebind_variation_attribute_ids( $product_id, $old_attribute_ids_by_name, $new_attribute_ids_by_name );
		} finally {
			self::end_internal_write();
		}
	}

	/**
	 * Insert one row into `wc_product_attributes` from a decoded
	 * postmeta entry. Returns the new row id, or null if the entry
	 * is malformed and should be skipped.
	 *
	 * @param int                  $product_id       Product ID.
	 * @param array<string, mixed> $meta_attribute   Decoded postmeta entry.
	 * @param string               $attributes_table Resolved table name.
	 * @return int|null Row id, or null when the entry is unusable.
	 */
	private function insert_attribute_row( int $product_id, array $meta_attribute, string $attributes_table ): ?int {
		global $wpdb;

		$meta = array_merge(
			array(
				'name'         => '',
				'value'        => '',
				'position'     => 0,
				'is_visible'   => 0,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			),
			$meta_attribute
		);

		$name = (string) $meta['name'];
		if ( '' === $name ) {
			return null;
		}

		$is_taxonomy = ! empty( $meta['is_taxonomy'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$attributes_table,
			array(
				'product_id'       => $product_id,
				'name'             => $name,
				'taxonomy'         => $is_taxonomy ? $name : '',
				'position'         => (int) $meta['position'],
				'is_visible'       => empty( $meta['is_visible'] ) ? 0 : 1,
				'is_for_variation' => empty( $meta['is_variation'] ) ? 0 : 1,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d' )
		);

		$row_id = (int) $wpdb->insert_id;
		return $row_id > 0 ? $row_id : null;
	}

	/**
	 * Insert the value rows for a single attribute. Taxonomy attributes
	 * get one row per assigned term (resolved through
	 * `wc_get_object_terms()` so the result agrees with what the legacy
	 * data store would have read). Custom attributes get one row per
	 * `|`-delimited option, decoded via {@see wc_get_text_attributes()}.
	 *
	 * @param int                  $product_id       Product ID.
	 * @param int                  $attribute_row_id `wc_product_attributes.id` of the parent row.
	 * @param array<string, mixed> $meta_attribute   Decoded postmeta entry.
	 * @param string               $values_table     Resolved table name.
	 * @return void
	 */
	private function insert_attribute_values( int $product_id, int $attribute_row_id, array $meta_attribute, string $values_table ): void {
		$is_taxonomy = ! empty( $meta_attribute['is_taxonomy'] );
		$name        = (string) ( $meta_attribute['name'] ?? '' );

		if ( $is_taxonomy ) {
			if ( ! taxonomy_exists( $name ) ) {
				return;
			}
			$term_ids = wc_get_object_terms( $product_id, $name, 'term_id' );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				return;
			}
			$position = 0;
			foreach ( $term_ids as $term_id ) {
				$term = get_term( (int) $term_id, $name );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				ProductsTableDataStore::insert_attribute_value_row(
					$values_table,
					$product_id,
					$attribute_row_id,
					'product',
					(string) $term->slug,
					(int) $term->term_id,
					0,
					$position
				);
				++$position;
			}
			return;
		}

		$options  = wc_get_text_attributes( (string) ( $meta_attribute['value'] ?? '' ) );
		$position = 0;
		foreach ( $options as $option ) {
			ProductsTableDataStore::insert_attribute_value_row(
				$values_table,
				$product_id,
				$attribute_row_id,
				'product',
				(string) $option,
				null,
				0,
				$position
			);
			++$position;
		}
	}

	/**
	 * Insert default-attribute marker rows (`is_default = 1`) for every
	 * `attribute_name => value` pair in `_default_attributes` postmeta.
	 *
	 * Defaults that reference an attribute the product doesn't actually
	 * carry are silently skipped — that matches the legacy store, which
	 * orphan-prunes them on the next read.
	 *
	 * @param int                   $product_id        Product ID.
	 * @param array<string, mixed>  $default_attributes Decoded postmeta.
	 * @param string                $attributes_table  Resolved table name.
	 * @param string                $values_table      Resolved table name.
	 * @return void
	 */
	private function insert_default_attributes( int $product_id, array $default_attributes, string $attributes_table, string $values_table ): void {
		global $wpdb;

		foreach ( $default_attributes as $attr_name => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attribute_row_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE product_id = %d AND name = %s LIMIT 1',
					$attributes_table,
					$product_id,
					(string) $attr_name
				)
			);
			if ( $attribute_row_id <= 0 ) {
				continue;
			}
			ProductsTableDataStore::insert_attribute_value_row(
				$values_table,
				$product_id,
				$attribute_row_id,
				'product',
				(string) $value,
				null,
				1,
				0
			);
		}
	}

	/**
	 * Inverse of {@see mirror_attributes_to_hpps()}: read the side
	 * tables and reassemble the postmeta blobs `_product_attributes`
	 * and `_default_attributes` so legacy reads (custom CPT queries,
	 * REST endpoints not migrated to HPPS, plugins that read postmeta
	 * directly) keep seeing fresh values.
	 *
	 * Empty result sets clear the postmeta entirely (matching the
	 * legacy `update_or_delete_post_meta()` convention) so a product
	 * whose attributes were just removed doesn't leave stale arrays
	 * behind.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function mirror_attributes_to_postmeta( int $product_id ): void {
		global $wpdb;

		$attributes_table = ProductsTableDataStore::get_attributes_table_name();
		$values_table     = ProductsTableDataStore::get_attribute_values_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attribute_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, name, taxonomy, position, is_visible, is_for_variation FROM %i WHERE product_id = %d ORDER BY position ASC, id ASC',
				$attributes_table,
				$product_id
			)
		);

		if ( empty( $attribute_rows ) ) {
			$this->clear_attribute_postmeta( $product_id );
			return;
		}

		$attribute_ids = array_map( static fn( $row ): int => (int) $row->id, $attribute_rows );
		$placeholders  = implode( ', ', array_fill( 0, count( $attribute_ids ), '%d' ) );

		$prepared_args = array_merge( array( $values_table, $product_id ), $attribute_ids );

		$values_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attribute_id, value, is_default, position FROM %i WHERE product_id = %d AND attribute_id IN ({$placeholders}) AND scope = 'product' ORDER BY position ASC, id ASC",
				$prepared_args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$product_attributes = array();
		$default_attributes = array();

		foreach ( $attribute_rows as $attribute_row ) {
			$name         = (string) $attribute_row->name;
			$is_taxonomy  = ! empty( $attribute_row->taxonomy );
			$attribute_id = (int) $attribute_row->id;
			$key          = $is_taxonomy ? $name : sanitize_title( $name );

			$values = array();
			foreach ( $values_rows as $value_row ) {
				if ( (int) $value_row->attribute_id !== $attribute_id ) {
					continue;
				}
				if ( 1 === (int) $value_row->is_default ) {
					$default_attributes[ $key ] = (string) $value_row->value;
					continue;
				}
				$values[] = (string) $value_row->value;
			}

			$product_attributes[ $key ] = array(
				'name'         => $name,
				'value'        => $is_taxonomy ? '' : wc_implode_text_attributes( $values ),
				'position'     => (int) $attribute_row->position,
				'is_visible'   => (int) $attribute_row->is_visible,
				'is_variation' => (int) $attribute_row->is_for_variation,
				'is_taxonomy'  => $is_taxonomy ? 1 : 0,
			);
		}

		self::start_internal_write();
		try {
			if ( empty( $product_attributes ) ) {
				delete_post_meta( $product_id, '_product_attributes' );
			} else {
				// wp_slash mirrors the legacy CPT data store's pattern at
				// `update_attributes()` so update_post_meta sees the same
				// escape level either path uses.
				update_post_meta( $product_id, '_product_attributes', wp_slash( $product_attributes ) );
			}

			if ( empty( $default_attributes ) ) {
				delete_post_meta( $product_id, '_default_attributes' );
			} else {
				update_post_meta( $product_id, '_default_attributes', wp_slash( $default_attributes ) );
			}
		} finally {
			self::end_internal_write();
		}
	}

	/**
	 * Helper for {@see mirror_attributes_to_postmeta()}: drop both
	 * postmeta blobs when the product has no attributes at all.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function clear_attribute_postmeta( int $product_id ): void {
		self::start_internal_write();
		try {
			delete_post_meta( $product_id, '_product_attributes' );
			delete_post_meta( $product_id, '_default_attributes' );
		} finally {
			self::end_internal_write();
		}
	}

	/**
	 * Whether a product row exists in `wc_products`. Lightweight version
	 * of {@see ProductsTableDataStore::product_exists_in_hpps()} — kept
	 * private here to avoid pulling the full data store into a hook
	 * that may fire many times per request.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function product_exists_in_hpps( int $product_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id
			)
		);

		return null !== $exists;
	}
}
