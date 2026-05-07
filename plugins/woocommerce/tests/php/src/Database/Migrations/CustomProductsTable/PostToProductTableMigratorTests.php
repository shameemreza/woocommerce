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
	 * @testdox H6 — variations migrated before their parent (or out of order in a single batch) still get a non-zero attribute_id linked to the parent's wc_product_attributes row.
	 *
	 * Round-1 fix: when a batch processed a variation before the parent
	 * was migrated, `wc_product_attribute_values.attribute_id` defaulted
	 * to 0 because the parent's `wc_product_attributes` row didn't exist
	 * yet. The migrator now lazily creates the parent attribute row via
	 * `ensure_parent_attribute_id()` so out-of-order migrations remain
	 * correct.
	 *
	 * The audit flagged that the migrator suite had five tests, all on
	 * simple/external products — variations were uncovered. This test
	 * fills the gap by migrating only the variation (parent left in CPT)
	 * and asserting the value row resolves a real parent attribute id.
	 */
	public function test_migrate_variation_before_parent_lazily_creates_parent_attribute_row(): void {
		global $wpdb;

		$this->toggle_hpps_feature_and_usage( false );
		$variable     = WC_Helper_Product::create_variation_product();
		$parent_id    = (int) $variable->get_id();
		$variation_id = (int) $variable->get_children()[0];
		$this->toggle_hpps_feature_and_usage( true );

		// Migrate only the variation. The parent remains CPT-only, which is
		// the orphan-variation scenario the H6 fix protects.
		$result = $this->migrator->migrate_products( array( $variation_id ) );

		$this->assertSame( array( $variation_id ), $result['migrated'], 'Variation should land in HPPS even when its parent hasn\'t migrated yet.' );

		$attributes_table = ProductsTableDataStore::get_attributes_table_name();
		$values_table     = ProductsTableDataStore::get_attribute_values_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$variation_attr_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$values_table} WHERE product_id = %d AND scope = 'variation' LIMIT 1",
				$variation_id
			)
		);

		$parent_attr_id_for_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$attributes_table} WHERE product_id = %d AND name = %s LIMIT 1",
				$parent_id,
				'pa_size'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertGreaterThan(
			0,
			$variation_attr_id,
			'H6: the variation\'s value row must point at a real wc_product_attributes id, not 0. A pre-fix migrator defaulted to 0 when the parent hadn\'t migrated yet.'
		);
		$this->assertGreaterThan(
			0,
			$parent_attr_id_for_size,
			'H6: ensure_parent_attribute_id() should have created a parent attribute row for `pa_size`.'
		);
		$this->assertSame(
			$parent_attr_id_for_size,
			$variation_attr_id,
			'H6: the variation\'s attribute_id must match the lazily-created parent row.'
		);
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
