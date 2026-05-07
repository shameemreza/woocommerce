<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Database\Migrations\CustomProductsTable\PostsToProductsMigrationController;
use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;

/**
 * Tests for {@see ProductDataSynchronizer}: the table-lifecycle and
 * background-migration glue between the HPPS migrator and the rest of WC.
 */
class ProductDataSynchronizerTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->sut = wc_get_container()->get( ProductDataSynchronizer::class );
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox check_products_table_exists() returns true after create_database_tables() and false after delete_database_tables().
	 */
	public function test_check_products_table_exists_tracks_lifecycle(): void {
		$this->sut->delete_database_tables();
		$this->assertFalse( $this->sut->check_products_table_exists() );

		$this->assertTrue( $this->sut->create_database_tables() );
		$this->assertTrue( $this->sut->check_products_table_exists() );

		$this->sut->delete_database_tables();
		$this->assertFalse( $this->sut->check_products_table_exists() );
	}

	/**
	 * @testdox get_table_exists() prefers the cached option and only does a SHOW TABLES on cache miss.
	 */
	public function test_get_table_exists_uses_cached_option(): void {
		$this->sut->create_database_tables();
		update_option( ProductDataSynchronizer::PRODUCTS_TABLE_CREATED_OPTION, 'no' );

		$this->assertFalse( $this->sut->get_table_exists(), 'Cached option should win when set.' );

		delete_option( ProductDataSynchronizer::PRODUCTS_TABLE_CREATED_OPTION );
		$this->assertTrue( $this->sut->get_table_exists(), 'Empty cache should fall back to a real check.' );
	}

	/**
	 * @testdox create_database_tables() is idempotent and leaves all expected tables in place.
	 */
	public function test_create_database_tables_is_idempotent(): void {
		global $wpdb;

		$this->sut->create_database_tables();
		$this->sut->create_database_tables();
		$this->sut->create_database_tables();

		$products_table = ProductsTableDataStore::get_products_table_name();
		$this->assertSame( $products_table, $wpdb->get_var( "SHOW TABLES LIKE '{$products_table}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @testdox has_products_pending_sync() / get_pending_count() track CPT-only products correctly.
	 */
	public function test_pending_sync_tracks_cpt_only_products(): void {
		// Disable HPPS while we create the fixture so the product lands in
		// CPT only — that's the realistic "needs migration" state.
		$this->toggle_hpps_feature_and_usage( false );
		$product_id = WC_Helper_Product::create_simple_product()->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		$this->assertGreaterThanOrEqual( 1, $this->sut->get_pending_count() );
		$this->assertTrue( $this->sut->has_products_pending_sync() );

		$this->sut->run_synchronously( 50, 5 );

		$this->assertSame( 0, $this->sut->get_pending_count() );
		$this->assertFalse( $this->sut->has_products_pending_sync() );
		$this->assertTrue( $this->sut->migration_is_complete() );

		$this->assert_product_record_existence( $product_id, true, true );
	}

	/**
	 * @testdox enqueue_background_migration() / dequeue_background_migration() flip the BatchProcessingController state.
	 */
	public function test_enqueue_dequeue_flips_batch_processor_state(): void {
		$this->assertFalse( $this->sut->is_background_migration_enqueued() );

		$this->sut->enqueue_background_migration();
		$this->assertTrue( $this->sut->is_background_migration_enqueued() );
		$this->assertSame( 'pending', get_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION ) );

		$this->sut->dequeue_background_migration();
		$this->assertFalse( $this->sut->is_background_migration_enqueued() );
	}

	/**
	 * @testdox enqueue_background_migration() registers the right processor class with BatchProcessingController.
	 */
	public function test_enqueue_registers_correct_processor(): void {
		$this->sut->enqueue_background_migration();

		$batch = wc_get_container()->get( BatchProcessingController::class );
		$this->assertTrue( $batch->is_enqueued( PostsToProductsMigrationController::class ) );

		$this->sut->dequeue_background_migration();
	}

	/**
	 * @testdox run_synchronously() returns a summary keyed by migrated / skipped / errors / iterations.
	 */
	public function test_run_synchronously_returns_typed_summary(): void {
		$summary = $this->sut->run_synchronously( 10, 1 );

		$this->assertArrayHasKey( 'migrated', $summary );
		$this->assertArrayHasKey( 'skipped', $summary );
		$this->assertArrayHasKey( 'errors', $summary );
		$this->assertArrayHasKey( 'iterations', $summary );
		$this->assertGreaterThanOrEqual( 1, $summary['iterations'] );
	}
}
