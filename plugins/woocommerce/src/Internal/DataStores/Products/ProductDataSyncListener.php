<?php
/**
 * ProductDataSyncListener class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

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
	 * Map of WordPress meta keys to the column name in `wc_products` they
	 * mirror. Keys are deliberately conservative: only the meta paths that
	 * have a 1:1 column counterpart and where third-party plugins are most
	 * likely to write directly. Composite columns (gallery_image_ids,
	 * date_on_sale_*) and meta that needs richer parsing (cogs_*) are not
	 * synced here yet — they go through the data store layer in Phase 2.
	 *
	 * Each entry declares:
	 *   - `column`: target column in `wc_products`.
	 *   - `type`:   how to coerce the postmeta scalar into the column shape.
	 *
	 * @var array<string, array{column: string, type: string}>
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
		'_total_sales'           => array(
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
		// the wc_products columns.
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

		$columns_csv = implode(
			', ',
			array_map(
				static fn( array $mapping ): string => '`' . $mapping['column'] . '`',
				self::META_TO_COLUMN_MAP
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
			foreach ( self::META_TO_COLUMN_MAP as $meta_key => $mapping ) {
				$column        = $mapping['column'];
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
		$this->write_column( $object_id, $mapping['column'], $mapping['type'], $meta_value );
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
