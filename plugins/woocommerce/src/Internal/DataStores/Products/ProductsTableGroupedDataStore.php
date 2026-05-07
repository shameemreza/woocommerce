<?php
/**
 * ProductsTableGroupedDataStore class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStatus;
use WC_Object_Data_Store_Interface;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Grouped_Data_Store_CPT;

defined( 'ABSPATH' ) || exit;

/**
 * HPPS data store for grouped products.
 *
 * Grouped products store a list of child product IDs that they aggregate. We
 * keep that list in `wc_products_meta` under the legacy `_children` key so
 * extension code doing `get_post_meta( $id, '_children', true )` keeps
 * returning the same array via the back-compat meta read path.
 *
 * `sync_price()` walks the children's `wc_products` rows directly to derive
 * the lookup-table min/max price — no n+1 postmeta hop.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductsTableGroupedDataStore extends ProductsTableDataStore implements WC_Object_Data_Store_Interface {

	/**
	 * Meta key holding the array of children product IDs for grouped products.
	 *
	 * @var string
	 */
	protected const CHILDREN_META_KEY = '_children';

	/**
	 * Grouped products use the grouped-aware legacy CPT data store as their
	 * fallback (it knows how to read the `_children` postmeta correctly).
	 *
	 * @return WC_Product_Data_Store_CPT
	 */
	protected function get_legacy_data_store(): WC_Product_Data_Store_CPT {
		static $legacy = null;
		if ( null === $legacy ) {
			$legacy = new WC_Product_Grouped_Data_Store_CPT();
		}
		return $legacy;
	}

	/**
	 * Persist a grouped product, including the children list.
	 *
	 * @param WC_Product $product Product passed by reference.
	 * @return void
	 */
	public function create( &$product ) {
		parent::create( $product );
		$this->persist_children( $product );
		$this->update_lookup_table( $product->get_id() );
	}

	/**
	 * Read a grouped product, restoring the children list.
	 *
	 * @param WC_Product $product Product passed by reference.
	 * @return void
	 */
	public function read( &$product ) {
		parent::read( $product );

		$children = $this->read_children( $product );
		if ( is_callable( array( $product, 'set_children' ) ) ) {
			$product->set_children( $children );
		}
	}

	/**
	 * Update a grouped product. Re-syncs the children meta and the cache.
	 *
	 * @param WC_Product $product Product passed by reference.
	 * @return void
	 */
	public function update( &$product ) {
		$changes = $product->get_changes();

		parent::update( $product );

		if ( array_key_exists( 'children', $changes ) ) {
			$this->persist_children( $product );
			$this->update_lookup_table( $product->get_id() );
		}
	}

	/**
	 * Sync grouped product prices: store min/max derived from children's
	 * `wc_products.price` directly into the lookup table.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return void
	 */
	public function sync_price( &$product ) {
		$product_id = is_object( $product ) ? (int) $product->get_id() : (int) $product;
		if ( $product_id <= 0 ) {
			return;
		}

		$this->update_lookup_table( $product_id );

		/** This action is documented in includes/data-stores/class-wc-product-grouped-data-store-cpt.php */
		do_action( 'woocommerce_updated_product_price', $product_id );
	}

	/**
	 * Persist the `_children` meta for a grouped product to wc_products_meta.
	 *
	 * @param WC_Product $product Product object. Runtime is always a
	 *                            WC_Product_Grouped — only that subclass
	 *                            accepts the `'edit'` context arg on
	 *                            get_children().
	 * @return void
	 */
	protected function persist_children( WC_Product $product ): void {
		if ( ! $product instanceof \WC_Product_Grouped ) {
			return;
		}

		$children = array_values( array_map( 'intval', (array) $product->get_children( 'edit' ) ) );

		// Use the standard WP meta API on the placeholder so caches stay
		// coherent with the back-compat read path. The HPPS meta data store
		// already mirrors writes into wc_products_meta when active.
		update_post_meta( (int) $product->get_id(), self::CHILDREN_META_KEY, $children );
	}

	/**
	 * Return the persisted children for a grouped product.
	 *
	 * @param WC_Product $product Product object.
	 * @return int[]
	 */
	protected function read_children( WC_Product $product ): array {
		$children = get_post_meta( (int) $product->get_id(), self::CHILDREN_META_KEY, true );
		if ( ! is_array( $children ) ) {
			$children = array();
		}
		return array_values( array_map( 'intval', $children ) );
	}

	/**
	 * Override the lookup-table update to compute MIN/MAX from children's
	 * `wc_products.price`. Falls back to the base behaviour if there are no
	 * children configured.
	 *
	 * Signature kept compatible with {@see WC_Data_Store_WP::update_lookup_table()}.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $table      Lookup table key (unused; for parent signature compatibility).
	 * @return null Always null, matching the parent's contract.
	 */
	public function update_lookup_table( $product_id, $table = '' ) {
		global $wpdb;

		unset( $table );

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return null;
		}

		$children = (array) get_post_meta( $product_id, self::CHILDREN_META_KEY, true );
		$children = array_filter( array_map( 'intval', $children ) );

		if ( empty( $children ) ) {
			parent::update_lookup_table( $product_id );
			return null;
		}

		$ids_in = implode( ',', array_map( 'absint', $children ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT MIN( CAST( price AS DECIMAL(26,8) ) ) AS min_price,
				        MAX( CAST( price AS DECIMAL(26,8) ) ) AS max_price
				FROM ' . self::get_products_table_name() . " WHERE id IN ( $ids_in ) AND status = %s AND price IS NOT NULL AND price <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ProductStatus::PUBLISH
			)
		);

		// Hand off to the base implementation for the non-price columns,
		// then patch the lookup row with the computed min/max.
		parent::update_lookup_table( $product_id );

		if ( ! $row || null === $row->min_price ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->wc_product_meta_lookup,
			array(
				'min_price' => $row->min_price,
				'max_price' => $row->max_price,
			),
			array( 'product_id' => $product_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return null;
	}
}
