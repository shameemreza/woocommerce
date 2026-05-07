<?php
/**
 * A class of utilities for dealing with products.
 *
 * @package WooCommerce\Utilities
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;

/**
 * Public helper facade for extensions to interact with the High-Performance
 * Product Storage (HPPS) feature.
 *
 * This is the equivalent of OrderUtil for orders. Extensions should depend on
 * this class instead of poking the internal CustomProductsTableController so
 * the public surface stays stable.
 *
 * @since 10.9.0
 */
final class ProductUtil {

	/**
	 * Whether HPPS is enabled.
	 *
	 * @return bool
	 */
	public static function custom_product_tables_usage_is_enabled(): bool {
		return wc_get_container()->get( CustomProductsTableController::class )->custom_product_tables_usage_is_enabled();
	}

	/**
	 * Convenience alias for the question extensions are most likely to ask.
	 *
	 * @return bool
	 */
	public static function is_hpps_enabled(): bool {
		return self::custom_product_tables_usage_is_enabled();
	}

	/**
	 * Returns the prefixed name of the wc_products table.
	 *
	 * @return string
	 */
	public static function get_products_table_name(): string {
		return ProductsTableDataStore::get_products_table_name();
	}

	/**
	 * Returns the prefixed name of the wc_product_attributes table.
	 *
	 * @return string
	 */
	public static function get_attributes_table_name(): string {
		return ProductsTableDataStore::get_attributes_table_name();
	}

	/**
	 * Returns the prefixed name of the wc_product_attribute_values table.
	 *
	 * @return string
	 */
	public static function get_attribute_values_table_name(): string {
		return ProductsTableDataStore::get_attribute_values_table_name();
	}

	/**
	 * Returns the prefixed name of the wc_product_downloads table.
	 *
	 * @return string
	 */
	public static function get_downloads_table_name(): string {
		return ProductsTableDataStore::get_downloads_table_name();
	}

	/**
	 * Returns the prefixed name of the wc_products_meta table.
	 *
	 * @return string
	 */
	public static function get_meta_table_name(): string {
		return ProductsTableDataStore::get_meta_table_name();
	}
}
