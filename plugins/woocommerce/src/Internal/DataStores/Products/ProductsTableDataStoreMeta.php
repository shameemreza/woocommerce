<?php
/**
 * ProductsTableDataStoreMeta class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Internal\DataStores\CustomMetaDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Implements meta data storage for HPPS using the wc_products_meta custom table.
 *
 * Mirrors the role that OrdersTableDataStoreMeta plays for HPOS: replaces
 * WordPress postmeta calls with reads/writes against a dedicated table, with
 * cache invalidation on every mutating operation so reads stay coherent.
 *
 * @since 10.9.0
 */
class ProductsTableDataStoreMeta extends CustomMetaDataStore {

	/**
	 * Returns the name of the table used for storage.
	 *
	 * @return string
	 */
	protected function get_table_name() {
		return ProductsTableDataStore::get_meta_table_name();
	}

	/**
	 * Returns the name of the field/column used for associating meta with objects.
	 *
	 * @return string
	 */
	protected function get_object_id_field() {
		return 'product_id';
	}

	/**
	 * Returns the cache group to store cached data in.
	 *
	 * @return string
	 */
	protected function get_cache_group() {
		return 'products_meta';
	}

	// @phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound

	/**
	 * Deletes meta based on meta ID.
	 *
	 * @param  \WC_Data  $object WC_Data object.
	 * @param  \stdClass $meta   Meta object containing at least ->id.
	 *
	 * @return bool
	 */
	public function delete_meta( &$object, $meta ): bool {
		$successful = parent::delete_meta( $object, $meta );
		if ( $successful ) {
			$this->invalidate_cache_for_object( (int) $object->get_id() );
		}

		return $successful;
	}

	/**
	 * Add new piece of meta.
	 *
	 * @param  \WC_Data  $object WC_Data object.
	 * @param  \stdClass $meta   Meta object containing ->key and ->value.
	 *
	 * @return int|false Meta ID on success, false on failure.
	 */
	public function add_meta( &$object, $meta ) {
		$insert_id = parent::add_meta( $object, $meta );
		if ( false !== $insert_id ) {
			$this->invalidate_cache_for_object( (int) $object->get_id() );
		}

		return $insert_id;
	}

	/**
	 * Update meta.
	 *
	 * @param  \WC_Data  $object WC_Data object.
	 * @param  \stdClass $meta   Meta object containing ->id, ->key and ->value.
	 *
	 * @return bool
	 */
	public function update_meta( &$object, $meta ): bool {
		$is_successful = parent::update_meta( $object, $meta );
		if ( $is_successful ) {
			$this->invalidate_cache_for_object( (int) $object->get_id() );
		}

		return $is_successful;
	}

	// @phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound

	/**
	 * Invalidate the object cache for a given product ID.
	 *
	 * Kept private and explicit so we can wire it to a richer cache engine
	 * later (mirroring HPOS' WPCacheEngine path) without touching callers.
	 *
	 * @param int $product_id Product ID.
	 */
	private function invalidate_cache_for_object( int $product_id ): void {
		wp_cache_delete( (string) $product_id, $this->get_cache_group() );
	}
}
