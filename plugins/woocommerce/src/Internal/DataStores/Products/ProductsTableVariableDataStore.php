<?php
/**
 * ProductsTableVariableDataStore class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\Caches\ProductVersionStringInvalidator;
use WC_Cache_Helper;
use WC_Object_Data_Store_Interface;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Variable_Data_Store_CPT;
use WC_Product_Variable_Data_Store_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * HPPS data store for variable products.
 *
 * Variable products in HPPS keep their own row in `wc_products` (with
 * `type = 'variable'`) and own a set of variation rows joined via
 * `parent_id`. Most behaviour comes from `ProductsTableDataStore`; this class
 * adds the variable-specific extras the legacy CPT store provides:
 *
 *  - children listing & visibility split (drives shop / variations dropdown).
 *  - price / stock-status / weight / dimensions probes against children.
 *  - sync_price() that computes MIN/MAX directly from `wc_products`
 *    (no postmeta intermediate hop).
 *  - delete_variations() / untrash_variations() that traverse rows in
 *    `wc_products` instead of running `get_posts` for `product_variation`.
 *  - find_matching_product_variation() for the storefront resolver.
 *
 * The price-cache transients are kept fully compatible with the CPT store so
 * extensions that rely on `wc_var_prices_<id>` keep working unchanged.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductsTableVariableDataStore extends ProductsTableDataStore implements WC_Object_Data_Store_Interface, WC_Product_Variable_Data_Store_Interface {

	/**
	 * Cached child variation prices, keyed by hash. Mirrors the CPT store
	 * so plugins relying on it keep working.
	 *
	 * @var array
	 */
	protected $prices_array = array();

	/**
	 * Variable products use the variable-aware legacy CPT data store as
	 * their fallback so children/visibility/price aggregation behave
	 * identically while a product is still on the wp_posts side.
	 *
	 * @return WC_Product_Data_Store_CPT
	 */
	protected function get_legacy_data_store(): WC_Product_Data_Store_CPT {
		static $legacy = null;
		if ( null === $legacy ) {
			$legacy = new WC_Product_Variable_Data_Store_CPT();
		}
		return $legacy;
	}

	/**
	 * Read children for a variable product.
	 *
	 * Returns `array( 'all' => int[], 'visible' => int[] )` ordered by
	 * `menu_order` and `id`, matching the legacy contract.
	 *
	 * @param WC_Product $product    Variable product.
	 * @param bool       $force_read When true, bypass the transient cache.
	 * @return array<string, int[]>
	 */
	public function read_children( &$product, $force_read = false ) {
		global $wpdb;

		$transient_key     = 'wc_product_children_' . $product->get_id();
		$transient_version = WC_Cache_Helper::get_transient_version( 'product' );
		$cached            = get_transient( $transient_key );

		if ( ! $force_read && is_array( $cached ) && isset( $cached['all'], $cached['visible'] ) ) {
			return array(
				'all'     => wp_parse_id_list( (array) $cached['all'] ),
				'visible' => wp_parse_id_list( (array) $cached['visible'] ),
			);
		}
		unset( $transient_version );

		$parent_id     = (int) $product->get_id();
		$hide_oos_term = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );

		// `all` covers publish + private (matches the CPT data store).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, menu_order, stock_status FROM ' . self::get_products_table_name() . ' WHERE parent_id = %d AND type = %s AND status IN ( %s, %s ) ORDER BY menu_order ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$parent_id,
				ProductType::VARIATION,
				ProductStatus::PUBLISH,
				ProductStatus::PRIVATE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$all     = array();
		$visible = array();
		foreach ( (array) $all_rows as $row ) {
			$id    = (int) $row->id;
			$all[] = $id;

			if ( $hide_oos_term && ProductStockStatus::OUT_OF_STOCK === $row->stock_status ) {
				continue;
			}
			$visible[] = $id;
		}

		$children = array(
			'all'     => $all,
			'visible' => $visible,
		);

		set_transient( $transient_key, $children, DAY_IN_SECONDS * 30 );

		return $children;
	}

	/**
	 * Whether at least one visible child variation has a non-zero weight.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	public function child_has_weight( $product ) {
		return $this->any_visible_child_matches( $product, 'weight > 0' );
	}

	/**
	 * Whether at least one visible child variation has dimensions set.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	public function child_has_dimensions( $product ) {
		return $this->any_visible_child_matches( $product, '( length > 0 OR width > 0 OR height > 0 )' );
	}

	/**
	 * Whether any child variation is in stock.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	public function child_is_in_stock( $product ) {
		return $this->child_has_stock_status( $product, ProductStockStatus::IN_STOCK );
	}

	/**
	 * Whether any child variation has the given stock status.
	 *
	 * @param WC_Product $product Variable product.
	 * @param string     $status  Stock status.
	 * @return bool
	 */
	public function child_has_stock_status( $product, $status ) {
		global $wpdb;

		$parent_id = (int) $product->get_id();
		if ( $parent_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE parent_id = %d AND type = %s AND stock_status = %s LIMIT 1',
				self::get_products_table_name(),
				$parent_id,
				ProductType::VARIATION,
				(string) $status
			)
		);

		return null !== $found;
	}

	/**
	 * Sync child variation post titles when the variable product is renamed.
	 *
	 * @param WC_Product $product       Variable product.
	 * @param string     $previous_name Old name.
	 * @param string     $new_name      New name.
	 * @return void
	 */
	public function sync_variation_names( &$product, $previous_name = '', $new_name = '' ) {
		if ( $previous_name === $new_name ) {
			return;
		}

		global $wpdb;

		$parent_id = (int) $product->get_id();
		if ( $parent_id <= 0 ) {
			return;
		}

		// Hydrate each variation, recompute its title from scratch via the
		// canonical generator (parent name + attribute summary), and write
		// the new title to both `wc_products.name` and the placeholder
		// `wp_posts.post_title`. The previous SQL `REPLACE()` approach
		// did substring substitution, so any variation whose custom name
		// happened to embed the old parent name (e.g. "Premium Toolkit
		// Limited Run") got its title corrupted on every parent rename,
		// and variations with custom names that didn't include the old
		// parent name didn't get updated at all.
		$variation_data_store = wc_get_container()->get( ProductsTableVariationDataStore::class );

		$post_types  = array(
			'product_variation',
			CustomProductsTableController::PLACEHOLDER_POST_TYPE,
		);
		$invalidator = wc_get_container()->get( ProductVersionStringInvalidator::class );

		foreach ( (array) $product->get_children() as $child_id ) {
			$child_id = (int) $child_id;
			if ( $child_id <= 0 ) {
				continue;
			}

			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
				continue;
			}

			$new_title = $variation_data_store->generate_product_title( $variation );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				array( 'name' => $new_title ),
				array( 'id' => $child_id ),
				array( '%s' ),
				array( '%d' )
			);

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_title = %s WHERE ID = %d AND post_type IN ( %s, %s )",
					$new_title,
					$child_id,
					$post_types[0],
					$post_types[1]
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			clean_post_cache( $child_id );
			$invalidator->invalidate( $child_id );
		}

		$invalidator->invalidate( $parent_id );
	}

	/**
	 * Mirror parent stock_status to children that don't manage their own stock.
	 *
	 * @param WC_Product $product Variable product.
	 * @return void
	 */
	public function sync_managed_variation_stock_status( &$product ) {
		global $wpdb;

		if ( ! $product->get_manage_stock() ) {
			return;
		}

		$parent_id = (int) $product->get_id();
		$status    = (string) $product->get_stock_status();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::get_products_table_name() . ' SET stock_status = %s, date_modified_gmt = %s WHERE parent_id = %d AND type = %s AND manage_stock = 0', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				gmdate( 'Y-m-d H:i:s' ),
				$parent_id,
				ProductType::VARIATION
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $updated ) {
			$invalidator = wc_get_container()->get( ProductVersionStringInvalidator::class );
			$invalidator->invalidate( $parent_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$child_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE parent_id = %d AND type = %s AND manage_stock = 0',
					self::get_products_table_name(),
					$parent_id,
					ProductType::VARIATION
				)
			);
			foreach ( (array) $child_ids as $child_id ) {
				$this->update_lookup_table( (int) $child_id );
				$invalidator->invalidate( (int) $child_id );
			}

			$children = $this->read_children( $product, true );
			if ( $product instanceof \WC_Product_Variable ) {
				$product->set_children( $children['all'] );
				$product->set_visible_children( $children['visible'] );
			}
		}
	}

	/**
	 * Sync the variable product's price-related lookup data with its children.
	 *
	 * Unlike the CPT store, this never touches postmeta. We compute MIN/MAX
	 * directly from `wc_products` and update `wc_product_meta_lookup` via
	 * the base data store's `update_lookup_table()` (which is type-aware
	 * thanks to the override below).
	 *
	 * @param WC_Product|int $product Variable product or product ID.
	 * @return void
	 */
	public function sync_price( &$product ) {
		$product_id = is_object( $product ) ? (int) $product->get_id() : (int) $product;
		if ( $product_id <= 0 ) {
			return;
		}

		$this->update_lookup_table( $product_id );

		/**
		 * Fired after a variable product's prices are recomputed via HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int $product_id Variable product ID.
		 */
		do_action( 'woocommerce_updated_product_price', $product_id );
	}

	/**
	 * Sync the variable product's stock_status from its children.
	 *
	 * @param WC_Product $product Variable product.
	 * @return void
	 */
	public function sync_stock_status( &$product ) {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return;
		}

		if ( $product->child_is_in_stock() ) {
			$product->set_stock_status( ProductStockStatus::IN_STOCK );
		} elseif ( $this->child_has_stock_status( $product, ProductStockStatus::ON_BACKORDER ) ) {
			$product->set_stock_status( ProductStockStatus::ON_BACKORDER );
		} else {
			$product->set_stock_status( ProductStockStatus::OUT_OF_STOCK );
		}
	}

	/**
	 * Permanently delete (or trash) every variation under a parent.
	 *
	 * @param int  $product_id   Parent product ID.
	 * @param bool $force_delete Whether to force-delete (default trash).
	 * @return void
	 */
	public function delete_variations( $product_id, $force_delete = false ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE parent_id = %d AND type = %s',
				self::get_products_table_name(),
				$product_id,
				ProductType::VARIATION
			)
		);

		foreach ( (array) $variation_ids as $variation_id ) {
			$variation_id = (int) $variation_id;
			$variation    = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			if ( $force_delete ) {
				/** This action is documented in includes/data-stores/class-wc-product-variable-data-store-cpt.php */
				do_action( 'woocommerce_before_delete_product_variation', $variation_id );
				$variation->delete( true );
				/** This action is documented in includes/data-stores/class-wc-product-variable-data-store-cpt.php */
				do_action( 'woocommerce_delete_product_variation', $variation_id );
			} else {
				$variation->delete( false );
				/** This action is documented in includes/data-stores/class-wc-product-variable-data-store-cpt.php */
				do_action( 'woocommerce_trash_product_variation', $variation_id );
			}
		}

		delete_transient( 'wc_product_children_' . $product_id );
	}

	/**
	 * Untrash variations (set status back to publish).
	 *
	 * @param int $product_id Parent product ID.
	 * @return void
	 */
	public function untrash_variations( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE parent_id = %d AND type = %s AND status = %s',
				self::get_products_table_name(),
				$product_id,
				ProductType::VARIATION,
				ProductStatus::TRASH
			)
		);

		foreach ( (array) $variation_ids as $variation_id ) {
			wp_untrash_post( (int) $variation_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				array( 'status' => ProductStatus::PUBLISH ),
				array( 'id' => (int) $variation_id ),
				array( '%s' ),
				array( '%d' )
			);
			$this->update_lookup_table( (int) $variation_id );
		}

		delete_transient( 'wc_product_children_' . $product_id );
	}

	/**
	 * Read a hash of attribute => values used for the variations dropdown.
	 *
	 * Reads `wc_product_attribute_values` directly with `scope = 'variation'`
	 * — a single query for the whole product, replacing the n+1 postmeta
	 * scan that the CPT store performs.
	 *
	 * @param WC_Product $product Variable product.
	 * @return array<string, string[]>
	 */
	public function read_variation_attributes( &$product ) {
		global $wpdb;

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return array();
		}

		$cache_key   = WC_Cache_Helper::get_cache_prefix( 'product_' . $product_id ) . 'product_variation_attributes_' . $product_id;
		$cache_group = 'products';

		$cached = wp_cache_get( $cache_key, $cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$attributes = (array) $product->get_attributes();
		if ( empty( $attributes ) ) {
			wp_cache_set( $cache_key, array(), $cache_group );
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.name AS attribute_name, a.taxonomy, av.value
				FROM ' . self::get_attribute_values_table_name() . ' av
				INNER JOIN ' . self::get_attributes_table_name() . ' a ON a.id = av.attribute_id
				WHERE av.scope = %s
				AND a.product_id = %d
				AND av.product_id IN (
					SELECT id FROM ' . self::get_products_table_name() . ' WHERE parent_id = %d AND type = %s
				)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'variation',
				$product_id,
				$product_id,
				ProductType::VARIATION
			)
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row->attribute_name ?: $row->taxonomy );
			if ( '' === $name ) {
				continue;
			}
			$grouped[ $name ][] = (string) $row->value;
		}

		// Apply the standard "any => all options" expansion the CPT store does.
		$variation_attributes = array();
		foreach ( $attributes as $attribute ) {
			if ( empty( $attribute['is_variation'] ) ) {
				continue;
			}
			$values = $grouped[ $attribute['name'] ] ?? array();

			// Empty array → "Any X" variation: pull the parent's full value
			// list. Same when one of the values is the empty string, which
			// is how the legacy CPT data store represents the "Any X" case.
			if ( empty( $values ) || in_array( '', $values, true ) ) {
				$values = $attribute['is_taxonomy']
					? wc_get_object_terms( $product_id, $attribute['name'], 'slug' )
					: wc_get_text_attributes( $attribute['value'] );
			}

			$variation_attributes[ $attribute['name'] ] = array_values( array_unique( (array) $values ) );
		}

		wp_cache_set( $cache_key, $variation_attributes, $cache_group );

		return $variation_attributes;
	}

	/**
	 * Read price data for a variable product (for the price-range UI).
	 *
	 * Re-uses the CPT store's transient cache layout so plugins reading
	 * `wc_var_prices_<id>` keep working unchanged.
	 *
	 * @param WC_Product $product     Variable product.
	 * @param bool       $for_display Whether prices are for display (taxes).
	 * @return array
	 */
	public function read_price_data( &$product, $for_display = false ) {
		// We deliberately reuse the legacy implementation: it's correct,
		// well-tested, and reads `_price` postmeta only as a fallback. Under
		// HPPS we keep the lookup table accurate via `sync_price()`, so the
		// path here goes straight through `wc_get_product()->get_price()`,
		// which itself reads from our `wc_products` columns.
		$cpt = new \WC_Product_Variable_Data_Store_CPT();
		return $cpt->read_price_data( $product, $for_display );
	}

	/**
	 * Find the variation matching a set of attribute values.
	 *
	 * Performs a single SQL query joining `wc_product_attribute_values`
	 * across the parent's children. Returns the matching variation ID
	 * or 0.
	 *
	 * @param WC_Product $product          Variable product.
	 * @param array      $match_attributes Attribute name => slug map.
	 * @return int
	 */
	public function find_matching_product_variation( $product, $match_attributes = array() ) {
		global $wpdb;

		$parent_id = (int) $product->get_id();
		if ( $parent_id <= 0 || empty( $match_attributes ) ) {
			return 0;
		}

		$variations_sql = $wpdb->prepare(
			'SELECT id FROM ' . self::get_products_table_name() . ' WHERE parent_id = %d AND type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parent_id,
			ProductType::VARIATION
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = (array) $wpdb->get_col( $variations_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $variation_ids ) ) {
			return 0;
		}

		// Pre-load variation attribute rows in one query.
		$ids_in = implode( ',', array_map( 'absint', $variation_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT av.product_id, av.value, COALESCE( a.name, a.taxonomy ) AS attribute_name
				FROM ' . self::get_attribute_values_table_name() . " av
				LEFT JOIN " . self::get_attributes_table_name() . " a ON a.id = av.attribute_id
				WHERE av.scope = %s AND av.product_id IN ( $ids_in )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'variation'
			)
		);

		$by_variation = array();
		foreach ( (array) $rows as $row ) {
			$by_variation[ (int) $row->product_id ][ (string) $row->attribute_name ] = (string) $row->value;
		}

		// Normalize the input to drop the "attribute_" prefix the storefront
		// resolver tends to send.
		$normalized = array();
		foreach ( $match_attributes as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
				$key = substr( (string) $key, 10 );
			}
			$normalized[ (string) $key ] = (string) $value;
		}

		// Two-pass match: prefer exact, fall back to "any" (empty stored value).
		$fallback_match = 0;
		foreach ( $variation_ids as $variation_id ) {
			$variation_id = (int) $variation_id;
			$attrs        = $by_variation[ $variation_id ] ?? array();
			$exact        = true;
			$any_match    = true;

			foreach ( $normalized as $name => $expected ) {
				$stored = $attrs[ $name ] ?? '';
				if ( '' === $stored ) {
					$exact = false; // "any" — could match.
					continue;
				}
				if ( (string) $expected !== (string) $stored ) {
					$exact     = false;
					$any_match = false;
					break;
				}
			}

			if ( $exact ) {
				return $variation_id;
			}
			if ( 0 === $fallback_match && $any_match ) {
				$fallback_match = $variation_id;
			}
		}

		return $fallback_match;
	}

	/**
	 * Reset the menu_order on every variation under a parent.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return void
	 */
	public function sort_all_product_variations( $parent_id ) {
		global $wpdb;

		$parent_id = (int) $parent_id;
		if ( $parent_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE parent_id = %d AND type = %s ORDER BY menu_order ASC, id ASC',
				self::get_products_table_name(),
				$parent_id,
				ProductType::VARIATION
			)
		);

		$position = 0;
		foreach ( (array) $variation_ids as $variation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				array( 'menu_order' => $position ),
				array( 'id' => (int) $variation_id ),
				array( '%d' ),
				array( '%d' )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $position ),
				array( 'ID' => (int) $variation_id ),
				array( '%d' ),
				array( '%d' )
			);
			++$position;
		}

		delete_transient( 'wc_product_children_' . $parent_id );
	}

	/**
	 * Helper: return true when at least one *visible* child matches the given
	 * SQL fragment (already safe-quoted; do not interpolate user input).
	 *
	 * @param WC_Product $product       Variable product.
	 * @param string     $sql_predicate Trusted SQL predicate fragment.
	 * @return bool
	 */
	protected function any_visible_child_matches( WC_Product $product, string $sql_predicate ): bool {
		global $wpdb;

		if ( ! $product instanceof \WC_Product_Variable ) {
			return false;
		}

		$visible_children = $product->get_visible_children();
		if ( empty( $visible_children ) ) {
			return false;
		}

		$ids_in = implode( ',', array_map( 'absint', $visible_children ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			'SELECT id FROM ' . self::get_products_table_name() . " WHERE id IN ( $ids_in ) AND $sql_predicate LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		unset( $sql_predicate );

		return null !== $found;
	}
}
