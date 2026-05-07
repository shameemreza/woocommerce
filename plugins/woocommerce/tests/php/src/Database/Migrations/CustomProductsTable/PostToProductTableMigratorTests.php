<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Database\Migrations\CustomProductsTable;

use Automattic\WooCommerce\Database\Migrations\CustomProductsTable\PostsToProductsMigrationController;
use Automattic\WooCommerce\Database\Migrations\CustomProductsTable\PostToProductTableMigrator;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;

/**
 * Tests for {@see PostToProductTableMigrator}: covers the per-type
 * migration paths plus the idempotency contract the synchronizer leans
 * on for re-runs and self-heal.
 */
class PostToProductTableMigratorTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var PostToProductTableMigrator
	 */
	private PostToProductTableMigrator $migrator;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->migrator = wc_get_container()
			->get( PostsToProductsMigrationController::class )
			->get_migrator();
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox migrate_products() copies a CPT-only simple product into wc_products and reports it under "migrated".
	 */
	public function test_migrate_simple_product_lands_in_hpps(): void {
		$id = $this->create_cpt_only_simple_product();

		$result = $this->migrator->migrate_products( array( $id ) );

		$this->assertSame( array( $id ), $result['migrated'] );
		$this->assertEmpty( $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
		$this->assert_product_record_existence( $id, true, true );
	}

	/**
	 * @testdox Migration is idempotent: running migrate_products() twice marks the second pass as "skipped".
	 */
	public function test_migration_is_idempotent(): void {
		$id = $this->create_cpt_only_simple_product();

		$this->migrator->migrate_products( array( $id ) );
		$second = $this->migrator->migrate_products( array( $id ) );

		$this->assertSame( array( $id ), $second['skipped'] );
		$this->assertEmpty( $second['migrated'] );
	}

	/**
	 * @testdox is_already_migrated() returns true after migrate_products() and false beforehand.
	 */
	public function test_is_already_migrated_tracks_state(): void {
		$id = $this->create_cpt_only_simple_product();

		$this->assertFalse( $this->migrator->is_already_migrated( $id ) );

		$this->migrator->migrate_products( array( $id ) );

		$this->assertTrue( $this->migrator->is_already_migrated( $id ) );
	}

	/**
	 * @testdox Mixing migrated and unmigrated IDs in one call returns an accurate per-product breakdown.
	 */
	public function test_mixed_batch_reports_per_product_breakdown(): void {
		$migrated_id   = $this->create_cpt_only_simple_product();
		$unmigrated_id = $this->create_cpt_only_simple_product();

		$this->migrator->migrate_products( array( $migrated_id ) );

		$result = $this->migrator->migrate_products(
			array( $migrated_id, $unmigrated_id, 0, -1 )
		);

		$this->assertSame( array( $unmigrated_id ), $result['migrated'] );
		$this->assertSame( array( $migrated_id ), $result['skipped'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * @testdox Migrating an external product preserves its product_url and button_text.
	 */
	public function test_migrate_external_product_preserves_external_fields(): void {
		global $wpdb;

		$this->toggle_hpps_feature_and_usage( false );
		$external = WC_Helper_Product::create_external_product();
		$id       = $external->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		$this->migrator->migrate_products( array( $id ) );

		$products_table = ProductsTableDataStore::get_products_table_name();
		$row            = $wpdb->get_row(
			$wpdb->prepare( "SELECT product_url, button_text FROM {$products_table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertNotNull( $row, 'External product should now have a wc_products row.' );
		$this->assertSame( 'https://woocommerce.com', $row->product_url );
		$this->assertSame( 'Buy external product', $row->button_text );
	}

	/**
	 * Helper: create a simple product in CPT only, by toggling HPPS off
	 * for the duration of the helper call. Mirrors the real "site enabled
	 * HPPS after a year of trading" scenario.
	 *
	 * @return int Product ID.
	 */
	private function create_cpt_only_simple_product(): int {
		$this->toggle_hpps_feature_and_usage( false );
		$product = WC_Helper_Product::create_simple_product();
		$id      = $product->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		return $id;
	}
}
