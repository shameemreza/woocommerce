<?php

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;

/**
 * Base class for High-Performance Product Storage (HPPS) test suites.
 *
 * Mirrors the role HposTestCase plays for the Orders side: it bundles the
 * common DB-existence asserts and the per-test sync helper so each test
 * file can stay focused on behaviour rather than table plumbing.
 */
class HppsTestCase extends WC_Unit_Test_Case {

	/**
	 * Assert that a row with the given ID exists (or doesn't) in either the
	 * HPPS `wc_products` table or `wp_posts`.
	 *
	 * @param int  $product_id The product ID to look up.
	 * @param bool $in_hpps    True to look at wc_products, false for wp_posts.
	 * @param bool $must_exist True to assert presence, false to assert absence.
	 * @return void
	 */
	protected function assert_product_record_existence( int $product_id, bool $in_hpps, bool $must_exist ): void {
		global $wpdb;

		if ( $in_hpps ) {
			$table = ProductsTableDataStore::get_products_table_name();
			$sql   = $wpdb->prepare(
				"SELECT EXISTS ( SELECT id FROM {$table} WHERE id = %d )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant getter.
				$product_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT EXISTS ( SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type IN ( 'product', 'product_variation' ) )",
				$product_id
			);
		}

		$exists = (bool) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $must_exist ) {
			$this->assertTrue( $exists, "Expected product {$product_id} to exist in " . ( $in_hpps ? 'wc_products' : 'wp_posts' ) . '.' );
		} else {
			$this->assertFalse( $exists, "Expected product {$product_id} NOT to exist in " . ( $in_hpps ? 'wc_products' : 'wp_posts' ) . '.' );
		}
	}

	/**
	 * Run the HPPS migration synchronously to drain any pending products
	 * after a fixture is created. Useful when a test needs a freshly
	 * created CPT product to also exist in HPPS before the assert.
	 *
	 * @return array{migrated:int,skipped:int,errors:int,iterations:int}
	 */
	protected function do_hpps_sync(): array {
		return wc_get_container()
			->get( ProductDataSynchronizer::class )
			->run_synchronously( 100, 50 );
	}

	/**
	 * Convenience: get the resolved synchronizer instance.
	 *
	 * @return ProductDataSynchronizer
	 */
	protected function get_hpps_synchronizer(): ProductDataSynchronizer {
		return wc_get_container()->get( ProductDataSynchronizer::class );
	}

	/**
	 * Convenience: get the HPPS controller.
	 *
	 * @return CustomProductsTableController
	 */
	protected function get_hpps_controller(): CustomProductsTableController {
		return wc_get_container()->get( CustomProductsTableController::class );
	}
}
