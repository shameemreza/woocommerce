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
		add_action( 'updated_post_meta', array( $this, 'on_post_meta_updated' ), 20, 4 );
		add_action( 'added_post_meta', array( $this, 'on_post_meta_added' ), 20, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_post_meta_deleted' ), 20, 4 );
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
