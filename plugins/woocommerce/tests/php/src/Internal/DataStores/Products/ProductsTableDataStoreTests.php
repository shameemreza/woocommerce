<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Simple;

/**
 * Tests for {@see ProductsTableDataStore}: end-to-end CRUD against the
 * HPPS tables, plus the legacy CPT fallback path used while a product
 * hasn't been migrated yet.
 */
class ProductsTableDataStoreTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var ProductsTableDataStore
	 */
	private ProductsTableDataStore $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->sut = wc_get_container()->get( ProductsTableDataStore::class );
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox create() persists a fresh simple product to wc_products and assigns a wp_posts ID.
	 */
	public function test_create_persists_simple_product(): void {
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'          => 'HPPS Simple',
				'regular_price' => 9.99,
				'price'         => 9.99,
				'sku'           => 'HPPS-CREATE-001',
				'stock_status'  => ProductStockStatus::IN_STOCK,
			)
		);

		$this->sut->create( $product );
		$id = $product->get_id();

		$this->assertGreaterThan( 0, $id, 'create() should assign an ID.' );
		$this->assert_product_record_existence( $id, true, true );
		$this->assert_product_record_existence( $id, false, true ); // Placeholder post type, but still in wp_posts.
	}

	/**
	 * @testdox read() round-trips the same property values that create() persisted.
	 */
	public function test_read_round_trips_simple_product_properties(): void {
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'          => 'HPPS Round Trip',
				'regular_price' => 19.50,
				'price'         => 19.50,
				'sku'           => 'HPPS-RT-001',
				'stock_status'  => ProductStockStatus::IN_STOCK,
				'manage_stock'  => true,
				'stock_quantity' => 7,
			)
		);
		$this->sut->create( $product );

		$reloaded = new WC_Product_Simple();
		$reloaded->set_id( $product->get_id() );
		$this->sut->read( $reloaded );

		$this->assertSame( 'HPPS Round Trip', $reloaded->get_name() );
		$this->assertEquals( 19.50, (float) $reloaded->get_regular_price() );
		$this->assertSame( 'HPPS-RT-001', $reloaded->get_sku() );
		$this->assertSame( ProductStockStatus::IN_STOCK, $reloaded->get_stock_status() );
		$this->assertTrue( $reloaded->get_manage_stock() );
		$this->assertSame( 7, $reloaded->get_stock_quantity() );
	}

	/**
	 * @testdox update() persists property changes against an existing wc_products row.
	 */
	public function test_update_persists_property_changes(): void {
		$product = WC_Helper_Product::create_simple_product();

		// The helper saves through whatever data store is active. With HPPS
		// on, that's already us — but make sure the row is in HPPS regardless.
		$this->do_hpps_sync();
		$this->assert_product_record_existence( $product->get_id(), true, true );

		$reloaded = wc_get_product( $product->get_id() );
		$reloaded->set_regular_price( 42 );
		$reloaded->set_price( 42 );
		$reloaded->set_name( 'Renamed via HPPS' );
		$reloaded->save();

		$check = wc_get_product( $product->get_id() );
		$this->assertEquals( 42, (float) $check->get_regular_price() );
		$this->assertSame( 'Renamed via HPPS', $check->get_name() );
	}

	/**
	 * @testdox delete() with force_delete removes the row from every HPPS table.
	 */
	public function test_force_delete_clears_all_hpps_rows(): void {
		global $wpdb;

		$product = WC_Helper_Product::create_simple_product();
		$this->do_hpps_sync();
		$id = $product->get_id();

		$this->sut->delete( $product, array( 'force_delete' => true ) );

		$products_table = ProductsTableDataStore::get_products_table_name();
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$products_table} WHERE id = %d", $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'wc_products row should be gone after force_delete.'
		);
	}

	/**
	 * @testdox read() falls back to the legacy CPT data store for a product that hasn't been migrated yet.
	 */
	public function test_read_falls_back_to_legacy_for_unmigrated_product(): void {
		// Disable HPPS while we create the fixture so the row only exists
		// in CPT — the realistic "pre-migration" state.
		$this->toggle_hpps_feature_and_usage( false );
		$cpt_product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name' => 'CPT-only Fallback',
				'sku'  => 'HPPS-FALLBACK-001',
			)
		);
		$this->toggle_hpps_feature_and_usage( true );

		// The new $this->sut may still hold the previous container's
		// reference — re-resolve so it sees the freshly-toggled state.
		$this->sut = wc_get_container()->get( ProductsTableDataStore::class );

		// The product is NOT in wc_products yet.
		$this->assert_product_record_existence( $cpt_product->get_id(), true, false );

		$reloaded = new WC_Product_Simple();
		$reloaded->set_id( $cpt_product->get_id() );
		$this->sut->read( $reloaded );

		$this->assertSame( 'CPT-only Fallback', $reloaded->get_name() );
		$this->assertSame( 'HPPS-FALLBACK-001', $reloaded->get_sku() );
	}

	/**
	 * @testdox update() routes back to legacy when a product is still on the CPT side, so writes don't end up half-stored.
	 */
	public function test_update_routes_to_legacy_for_unmigrated_product(): void {
		$this->toggle_hpps_feature_and_usage( false );
		$cpt_product = WC_Helper_Product::create_simple_product();
		$id          = $cpt_product->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		$sut = wc_get_container()->get( ProductsTableDataStore::class );

		// HPPS row absent → update should be handled by the legacy CPT store.
		$cpt_product->set_regular_price( 77 );
		$sut->update( $cpt_product );

		// Still absent from wc_products (we routed back to CPT).
		$this->assert_product_record_existence( $id, true, false );

		// And the change landed on the CPT side.
		$reloaded = ( new WC_Product_Data_Store_CPT() );
		$check    = new WC_Product_Simple();
		$check->set_id( $id );
		$reloaded->read( $check );
		$this->assertEquals( 77, (float) $check->get_regular_price() );
	}
}
