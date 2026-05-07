<?php

namespace Automattic\WooCommerce\RestApi\UnitTests;

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;

/**
 * Trait HPPSToggleTrait.
 *
 * Mirrors HPOSToggleTrait but for the High-Performance Product Storage
 * feature. Provides the lifecycle helpers a test class needs to:
 *
 *   - drop the WP_UnitTestCase temporary-table filters so HPPS tables
 *     survive the per-test rollback,
 *   - create / delete the HPPS schema,
 *   - flip the feature option on or off,
 *   - reset the DI container so the data store filter chain re-resolves.
 */
trait HPPSToggleTrait {

	/**
	 * Call from setUp() to spin HPPS up before a test.
	 *
	 * @return void
	 */
	public function setup_hpps(): void {
		// WP_UnitTestCase wraps every test in a transaction by routing
		// CREATE TABLE through a "temporary table" filter. dbDelta
		// doesn't play with that, so detach during HPPS table setup
		// (re-attached in clean_up_hpps_setup()).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->delete_hpps_tables();
		$this->create_hpps_tables_if_not_exist();

		$this->toggle_hpps_feature_and_usage( true );
	}

	/**
	 * Call from tearDown() to undo setup_hpps().
	 *
	 * @return void
	 */
	public function clean_up_hpps_setup(): void {
		$this->toggle_hpps_feature_and_usage( false );
		$this->delete_hpps_tables();

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Flip the HPPS feature option and reset the resolved container so
	 * the data-store filter chain picks up the new value.
	 *
	 * @param bool $enabled True to enable, false to disable.
	 * @return void
	 */
	protected function toggle_hpps_feature_and_usage( bool $enabled ): void {
		update_option(
			CustomProductsTableController::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION,
			$enabled ? 'yes' : 'no'
		);
		wp_cache_flush();
		wc_get_container()->reset_all_resolved();
	}

	/**
	 * Idempotent table creation.
	 *
	 * @return void
	 */
	protected function create_hpps_tables_if_not_exist(): void {
		$sync = wc_get_container()->get( ProductDataSynchronizer::class );
		if ( ! $sync->check_products_table_exists() ) {
			$sync->create_database_tables();
		}
	}

	/**
	 * Idempotent table teardown.
	 *
	 * @return void
	 */
	protected function delete_hpps_tables(): void {
		$sync = wc_get_container()->get( ProductDataSynchronizer::class );
		if ( $sync->check_products_table_exists() ) {
			$sync->delete_database_tables();
		}
	}
}
