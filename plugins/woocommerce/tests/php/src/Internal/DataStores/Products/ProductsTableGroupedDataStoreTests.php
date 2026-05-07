<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableGroupedDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Grouped;

/**
 * Tests for {@see ProductsTableGroupedDataStore}: persists the `_children`
 * meta blob on save, exposes it via read(), and computes the lookup-table
 * MIN/MAX from the children's `wc_products.price` (rather than a postmeta
 * scan).
 */
class ProductsTableGroupedDataStoreTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var ProductsTableGroupedDataStore
	 */
	private ProductsTableGroupedDataStore $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->sut = wc_get_container()->get( ProductsTableGroupedDataStore::class );
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox create() persists a grouped parent row (type='grouped') and stores the children list as `_children` postmeta.
	 */
	public function test_create_persists_grouped_parent_with_children_meta(): void {
		global $wpdb;

		$grouped = WC_Helper_Product::create_grouped_product();
		$id      = $grouped->get_id();

		$this->assertGreaterThan( 0, $id );
		$this->assert_product_record_existence( $id, true, true );

		$type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d',
				ProductsTableDataStore::get_products_table_name(),
				$id
			)
		);
		$this->assertSame( ProductType::GROUPED, $type );

		// The grouped store persists children via update_post_meta on the
		// placeholder, so reading via WP's standard meta API is the right
		// observation point regardless of whether wc_products_meta has
		// caught up yet.
		$children = get_post_meta( $id, '_children', true );
		$this->assertIsArray( $children );
		$this->assertCount( 2, $children );
	}

	/**
	 * @testdox read() returns the persisted children list as int[] in input order.
	 */
	public function test_read_returns_children_in_input_order(): void {
		$grouped         = WC_Helper_Product::create_grouped_product();
		$expected_children = $grouped->get_children();

		$reloaded = wc_get_product( $grouped->get_id() );
		$this->assertInstanceOf( WC_Product_Grouped::class, $reloaded );
		$this->assertSame( $expected_children, $reloaded->get_children() );
	}

	/**
	 * @testdox update() persists children-list changes back to the postmeta blob.
	 */
	public function test_update_persists_children_changes(): void {
		$grouped = WC_Helper_Product::create_grouped_product();

		// Add a third child and save.
		$extra = WC_Helper_Product::create_simple_product(
			true,
			array(
				'price'         => 7,
				'regular_price' => 7,
			)
		);
		$updated_children = array_merge( $grouped->get_children(), array( $extra->get_id() ) );

		$grouped->set_children( $updated_children );
		$grouped->save();

		$persisted = get_post_meta( $grouped->get_id(), '_children', true );
		$this->assertSame( array_map( 'intval', $updated_children ), array_map( 'intval', (array) $persisted ) );

		$reloaded = wc_get_product( $grouped->get_id() );
		$this->assertSame( array_map( 'intval', $updated_children ), array_map( 'intval', $reloaded->get_children() ) );
	}

	/**
	 * @testdox sync_price() updates wc_product_meta_lookup with the MIN/MAX prices computed from the children's wc_products rows.
	 */
	public function test_sync_price_computes_min_max_from_children(): void {
		global $wpdb;

		$grouped = WC_Helper_Product::create_grouped_product();
		// Helper creates a child priced at 1 and another priced at 10
		// (the default `create_simple_product()` price).
		$this->sut->sync_price( $grouped );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grouped->get_id()
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 1.0, (float) $row->min_price );
		$this->assertSame( 10.0, (float) $row->max_price );
	}

	/**
	 * @testdox sync_price() reflects updated children prices on subsequent syncs (no stale cache).
	 */
	public function test_sync_price_picks_up_children_price_changes(): void {
		global $wpdb;

		$grouped  = WC_Helper_Product::create_grouped_product();
		$children = $grouped->get_children();
		$this->assertCount( 2, $children );

		// Bump one child's price up so MAX should track.
		$first_child = wc_get_product( (int) $children[0] );
		$first_child->set_regular_price( 25 );
		$first_child->set_price( 25 );
		$first_child->save();

		$this->sut->sync_price( $grouped );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grouped->get_id()
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 25.0, (float) $row->max_price );
	}

	/**
	 * @testdox update_lookup_table() falls back to the base behaviour when a grouped product has no children configured.
	 */
	public function test_update_lookup_table_falls_back_with_no_children(): void {
		global $wpdb;

		$grouped = new WC_Product_Grouped();
		$grouped->set_props(
			array(
				'name' => 'HPPS Grouped Empty',
				'sku'  => 'HPPS-GROUPED-EMPTY-' . uniqid(),
			)
		);
		$grouped->save();

		// No exception, no row update on min/max — just the base behaviour.
		$this->sut->update_lookup_table( $grouped->get_id() );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grouped->get_id()
			)
		);

		// Either no lookup row at all, or the price columns are NULL/0
		// because the grouped parent itself doesn't carry a price.
		if ( null !== $row ) {
			$this->assertTrue( null === $row->min_price || '' === (string) $row->min_price || 0.0 === (float) $row->min_price );
			$this->assertTrue( null === $row->max_price || '' === (string) $row->max_price || 0.0 === (float) $row->max_price );
		}
	}

	/**
	 * @testdox A child being deleted leaves the children list intact (orphan ID stays — list integrity is the caller's responsibility).
	 */
	public function test_orphaned_child_id_does_not_self_remove_from_children_meta(): void {
		$grouped  = WC_Helper_Product::create_grouped_product();
		$children = $grouped->get_children();
		$this->assertCount( 2, $children );

		// Force-delete one child outright.
		$victim = wc_get_product( (int) $children[0] );
		$victim->delete( true );

		// `_children` still holds the original list — pruning is a UI concern,
		// not the data store's. We verify here so a regression that "helpfully"
		// removes deleted IDs from the list (and would silently change cart
		// behaviour) shows up in CI.
		$persisted = get_post_meta( $grouped->get_id(), '_children', true );
		$this->assertSame( array_map( 'intval', $children ), array_map( 'intval', (array) $persisted ) );
	}
}
