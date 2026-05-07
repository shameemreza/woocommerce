<?php
/**
 * ProductMigrationVerifier class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Throwable;
use WC_DateTime;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Factory;
use WC_Product_Grouped_Data_Store_CPT;
use WC_Product_Variable_Data_Store_CPT;
use WC_Product_Variation_Data_Store_CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the same product read through the legacy CPT data store and through
 * HPPS, returning a structured per-field diff.
 *
 * Behaviour is identical regardless of which data store is the active one,
 * because each side instantiates its own concrete store rather than going
 * through the `woocommerce_product*_data_store` filter chain. The CPT side
 * is treated as the source of truth; HPPS is the candidate.
 *
 * Used by:
 *
 *  - `wp wc hpps verify` (CLI) — full report formatting, exit code on mismatch.
 *  - `WooCommerce → Status → Tools → Verify HPPS integrity` — short summary
 *    suitable for an admin notice.
 *
 * Centralising this logic in one class keeps the two consumers honest: any
 * change to what counts as "matching" automatically applies to both surfaces.
 *
 * @since 10.9.0
 *
 * @internal Not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductMigrationVerifier {

	/**
	 * Synchronizer (used for the row-count summary that callers can display
	 * alongside the diff).
	 *
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $synchronizer;

	/**
	 * HPPS data stores keyed by product type, used to read products through
	 * the HPPS path without going through the data-store filter chain.
	 *
	 * @var array<string, ProductsTableDataStore>
	 */
	private array $hpps_stores = array();

	/**
	 * Inject dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param ProductDataSynchronizer         $synchronizer    Synchronizer.
	 * @param ProductsTableDataStore          $simple_store    HPPS simple/external store.
	 * @param ProductsTableVariableDataStore  $variable_store  HPPS variable store.
	 * @param ProductsTableVariationDataStore $variation_store HPPS variation store.
	 * @param ProductsTableGroupedDataStore   $grouped_store   HPPS grouped store.
	 */
	final public function init(
		ProductDataSynchronizer $synchronizer,
		ProductsTableDataStore $simple_store,
		ProductsTableVariableDataStore $variable_store,
		ProductsTableVariationDataStore $variation_store,
		ProductsTableGroupedDataStore $grouped_store
	): void {
		$this->synchronizer = $synchronizer;
		$this->hpps_stores  = array(
			'simple'    => $simple_store,
			'external'  => $simple_store,
			'variable'  => $variable_store,
			'variation' => $variation_store,
			'grouped'   => $grouped_store,
		);
	}

	/**
	 * Run the verification.
	 *
	 * @param array{id?:int, limit?:int, ignore?:string[]} $options Verification scope.
	 * @return array{
	 *     checked:int,
	 *     matches:int,
	 *     mismatches:array<int, array{id:int, fields?:array<string, array{cpt:mixed, hpps:mixed}>, error?:string}>,
	 *     row_counts: array{posts:int, hpps:int, pending:int}
	 * }
	 */
	public function verify( array $options = array() ): array {
		$single_id = isset( $options['id'] ) ? (int) $options['id'] : 0;
		$limit     = isset( $options['limit'] ) ? max( 0, (int) $options['limit'] ) : 100;
		$ignore    = isset( $options['ignore'] ) ? (array) $options['ignore'] : array( 'date_modified', 'date_modified_gmt' );

		$ids = $single_id > 0
			? array( $single_id )
			: $this->fetch_hpps_product_ids( $limit );

		$mismatches = array();
		foreach ( $ids as $product_id ) {
			$diff = $this->diff_product( (int) $product_id, $ignore );
			if ( null === $diff ) {
				continue;
			}
			if ( ! empty( $diff['fields'] ) || ! empty( $diff['error'] ) ) {
				$mismatches[] = $diff;
			}
		}

		return array(
			'checked'    => count( $ids ),
			'matches'    => count( $ids ) - count( $mismatches ),
			'mismatches' => $mismatches,
			'row_counts' => $this->get_row_counts(),
		);
	}

	/**
	 * Stringify a single normalised value for the table format CLI output.
	 *
	 * Public so the CLI runner can re-use it without duplicating the format
	 * rules (truncation, JSON encoding for arrays/objects, "null" rendering).
	 *
	 * @param mixed $value Normalised value (output of `normalize_for_diff`).
	 * @return string
	 */
	public function stringify_for_diff( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) ( $value ?? 'null' );
		}
		$encoded = wp_json_encode( $value );
		if ( false === $encoded ) {
			return '(unencodable)';
		}
		return strlen( $encoded ) > 80 ? substr( $encoded, 0, 77 ) . '...' : $encoded;
	}

	/**
	 * Fetch a deterministic page of HPPS product IDs.
	 *
	 * @param int $limit 0 = every row, otherwise the first `$limit` IDs by ascending id.
	 * @return int[]
	 */
	private function fetch_hpps_product_ids( int $limit ): array {
		global $wpdb;

		$products_table = ProductsTableDataStore::get_products_table_name();

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC LIMIT %d', $products_table, $limit )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC', $products_table )
			);
		}

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Pull the wp_posts vs wc_products row counts plus the synchronizer's
	 * pending count so callers can render a single summary line.
	 *
	 * @return array{posts:int, hpps:int, pending:int}
	 */
	private function get_row_counts(): array {
		global $wpdb;

		$products_table = ProductsTableDataStore::get_products_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					( SELECT COUNT(*) FROM %i WHERE post_type IN ( %s, %s ) AND post_status NOT IN ( %s, %s ) ) AS posts,
					( SELECT COUNT(*) FROM %i ) AS hpps',
				$wpdb->posts,
				'product',
				'product_variation',
				'auto-draft',
				'inherit',
				$products_table
			)
		);

		return array(
			'posts'   => (int) ( $counts->posts ?? 0 ),
			'hpps'    => (int) ( $counts->hpps ?? 0 ),
			'pending' => $this->synchronizer->get_pending_count(),
		);
	}

	/**
	 * Diff one product between legacy CPT and HPPS data.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $ignore     Property names to skip.
	 * @return array{id:int, fields?:array<string, array{cpt:mixed, hpps:mixed}>, error?:string}|null
	 *         null when the product matches and is fully present on both sides;
	 *         otherwise either an `error` or a populated `fields` map.
	 */
	private function diff_product( int $product_id, array $ignore ): ?array {
		try {
			$legacy = $this->load_product_via_legacy( $product_id );
			$hpps   = $this->load_product_via_hpps( $product_id );
		} catch ( Throwable $e ) {
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
	 * Build a fresh WC_Product (correct subclass) and read it via the legacy
	 * CPT data store, regardless of whether HPPS is the active data store.
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
		if ( ! is_string( $class ) || ! class_exists( $class ) ) {
			return null;
		}

		$product = new $class( 0 );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$product->set_id( $product_id );

		$store = $this->resolve_legacy_store( $type );
		$store->read( $product );

		return $product;
	}

	/**
	 * Same as {@see load_product_via_legacy()} but reads through HPPS.
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
		if ( ! is_string( $class ) || ! class_exists( $class ) ) {
			return null;
		}

		$product = new $class( 0 );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$product->set_id( $product_id );

		$store = $this->hpps_stores[ $type ] ?? $this->hpps_stores['simple'];
		$store->read( $product );

		return $product;
	}

	/**
	 * Look up the product type from the requested side. We can't ask the data
	 * store layer because either side may be the active filter target;
	 * instead we read directly from the underlying tables.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $side       'cpt' or 'hpps'.
	 * @return string Empty string when the product is not present on that side.
	 */
	private function resolve_product_type( int $product_id, string $side ): string {
		global $wpdb;

		if ( 'hpps' === $side ) {
			$products_table = ProductsTableDataStore::get_products_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$type = $wpdb->get_var(
				$wpdb->prepare( 'SELECT type FROM %i WHERE id = %d LIMIT 1', $products_table, $product_id )
			);
			return is_string( $type ) ? $type : '';
		}

		// CPT side: ask the legacy CPT data store directly so we don't route
		// through the woocommerce_product_data_store filter (which would point
		// at HPPS when the feature is on).
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
	 * Reduce a property value to a stable shape so structurally equal values
	 * compare equal. Primarily handles arrays whose key order or nested
	 * datetime objects would otherwise produce false positives.
	 *
	 * Also smooths out two harmless mismatches that the legacy CPT path and
	 * HPPS path occasionally surface differently:
	 *
	 * - `null` vs `''`: `WC_Data` setters coerce missing meta to an empty
	 *   string, but a few HPPS read paths return `null` for the same
	 *   condition. Both should be considered "unset".
	 * - Numeric formatting: `'10'`, `'10.0'`, `'10.00'`, and `10` all mean
	 *   the same price/weight/dimension. Cast numeric scalars through
	 *   `(float)` and back to a string so the comparison ignores trailing
	 *   zeros and integer-vs-float differences.
	 *
	 * @param mixed $value Raw property value.
	 * @return mixed
	 */
	private function normalize_for_diff( $value ) {
		if ( $value instanceof WC_DateTime ) {
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
		if ( null === $value ) {
			return '';
		}
		// Booleans must stay distinct from numeric `0`/`1`; only reshape
		// genuine numeric scalars (price, weight, dimensions, counts).
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (string) (float) $value;
		}
		return $value;
	}
}
