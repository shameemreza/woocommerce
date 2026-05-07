<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_DateTime;
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
	 * @testdox C2 — wc_get_product() resolves a product whose underlying wp_posts row uses the `product_placeholder` post type.
	 *
	 * Round-1 fix: WC_Product_Factory::get_product_id() rejected
	 * `product_placeholder` posts and returned false from wc_get_product(),
	 * which broke every consumer that takes an ID and re-hydrates. The
	 * fix added the placeholder post type to the factory's accept list.
	 * Without a regression test, that branch could be deleted with all
	 * other tests still green.
	 */
	public function test_wc_get_product_recognizes_placeholder_post_type(): void {
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'         => 'HPPS Placeholder Factory',
				'sku'          => 'HPPS-PLACEHOLDER-001',
				'stock_status' => ProductStockStatus::IN_STOCK,
			)
		);
		$this->sut->create( $product );
		$id = $product->get_id();

		// Sanity-check the precondition: HPPS allocates an ID via a
		// `product_placeholder` post (not a real `product`). If the
		// allocation strategy ever changes, this test stops protecting C2.
		$this->assertSame(
			CustomProductsTableController::PLACEHOLDER_POST_TYPE,
			get_post_type( $id ),
			'HPPS create() should allocate via the product_placeholder post type — C2 only matters while that\'s true.'
		);

		$resolved = wc_get_product( $id );
		$this->assertNotFalse(
			$resolved,
			'C2: wc_get_product() must accept placeholder-typed posts. A regression of WC_Product_Factory::get_product_id() would return false here.'
		);
		$this->assertSame( $id, (int) $resolved->get_id() );
		$this->assertSame( 'HPPS-PLACEHOLDER-001', $resolved->get_sku() );
	}

	/**
	 * @testdox C3 — trashing a product clears its wc_product_meta_lookup row, and untrashing rebuilds the row and restores the canonical wc_products.status.
	 *
	 * Round-1 fix: trash left a stale lookup row pointing at the canonical
	 * (non-trashed) snapshot, and untrash didn't restore the
	 * `wc_products.status` column that REST/admin queries filter on.
	 * Both are exercised here because they share the same lifecycle hook
	 * surface and a partial regression on either side is the bigger risk.
	 */
	public function test_trash_clears_lookup_row_and_untrash_restores_status(): void {
		global $wpdb;

		$product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'   => 'C3 Trash Lifecycle',
				'sku'    => 'HPPS-C3-001',
				'status' => ProductStatus::PUBLISH,
			)
		);
		$this->do_hpps_sync();
		$id = (int) $product->get_id();

		// Lookup row exists post-create.
		$lookup_pre = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$id
			)
		);
		$this->assertSame( 1, $lookup_pre, 'Sanity: a freshly-saved product should have a lookup row before trash.' );

		// Trash it through the canonical entry point that real admins use.
		wp_trash_post( $id );

		$status_after_trash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		$this->assertSame( 'trash', $status_after_trash, 'wc_products.status should be flipped to trash.' );

		$lookup_after_trash = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$id
			)
		);
		$this->assertSame(
			0,
			$lookup_after_trash,
			'C3: trashing must drop the lookup row. A stale row drifts away from the canonical wc_products values for the lifetime of the trash entry.'
		);

		wp_untrash_post( $id );

		$status_after_untrash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		$this->assertSame(
			ProductStatus::PUBLISH,
			$status_after_untrash,
			'C3: untrashing must restore the canonical wc_products.status — REST and admin queries filter on this column.'
		);

		$lookup_after_untrash = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$id
			)
		);
		$this->assertSame( 1, $lookup_after_untrash, 'C3: untrashing should rebuild the lookup row.' );
	}

	/**
	 * @testdox C5 + H1 — update_average_rating() persists to both wc_products and `_wc_average_rating` postmeta without re-entering the bidirectional listener, and clears product transients.
	 *
	 * Round-1 fixes:
	 *  - C5 wraps the postmeta write with `start_internal_write()` so the
	 *    listener doesn't bounce the value back into the column store and
	 *    double-bust caches.
	 *  - H1 clears the product transients so storefront fragments stop
	 *    serving the stale rating.
	 * The combined regression test asserts the column + postmeta
	 * agreement, the transient invalidation, and the absence of a
	 * recursion explosion.
	 */
	public function test_update_average_rating_brackets_writes_and_clears_transients(): void {
		global $wpdb;

		$product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name' => 'C5 H1 Rating',
				'sku'  => 'HPPS-C5-H1-001',
			)
		);
		$this->do_hpps_sync();
		$id = (int) $product->get_id();

		// Seed a transient that `wc_delete_product_transients()` clears,
		// so we can prove H1 fired without standing up a fragment cache.
		set_transient( 'wc_product_children_' . $id, array( 'sentinel' ), HOUR_IN_SECONDS );
		$this->assertNotFalse( get_transient( 'wc_product_children_' . $id ), 'Sanity: transient should be present before the rating update.' );

		$listener_calls = 0;
		$listener       = function () use ( &$listener_calls ): void {
			++$listener_calls;
		};
		add_action( 'updated_post_meta', $listener, 100 );

		$reloaded = wc_get_product( $id );
		$reloaded->set_average_rating( '4.5' );
		$this->sut->update_average_rating( $reloaded );

		remove_action( 'updated_post_meta', $listener, 100 );

		$rating_column = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT average_rating FROM ' . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		$this->assertEquals( 4.5, (float) $rating_column, 'wc_products.average_rating must be persisted.' );

		$rating_meta = (string) get_post_meta( $id, '_wc_average_rating', true );
		$this->assertEquals( 4.5, (float) $rating_meta, 'C5: `_wc_average_rating` postmeta must mirror the column.' );

		$this->assertLessThan(
			500,
			$listener_calls,
			'C5: bracketed write must short-circuit the bidirectional listener — runaway recursion would push this into the thousands.'
		);

		$this->assertFalse(
			get_transient( 'wc_product_children_' . $id ),
			'H1: clear_caches() should have invalidated the product transients on update_average_rating().'
		);
	}

	/**
	 * @testdox H1 — create()/update()/delete() invalidate product transients on every mutation.
	 */
	public function test_clear_caches_runs_on_create_update_and_delete(): void {
		// create() pass.
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'         => 'H1 Cache Lifecycle',
				'sku'          => 'HPPS-H1-001',
				'stock_status' => ProductStockStatus::IN_STOCK,
			)
		);

		$transient_calls = 0;
		$counter         = function () use ( &$transient_calls ): void {
			++$transient_calls;
		};
		add_action( 'woocommerce_delete_product_transients', $counter );

		$this->sut->create( $product );
		$this->assertGreaterThanOrEqual( 1, $transient_calls, 'create() must call wc_delete_product_transients() via clear_caches().' );

		// update() pass.
		$transient_calls = 0;
		$product->set_regular_price( 12 );
		$product->set_price( 12 );
		$this->sut->update( $product );
		$this->assertGreaterThanOrEqual( 1, $transient_calls, 'update() must call wc_delete_product_transients() via clear_caches().' );

		// delete() pass (force_delete to avoid the trash branch's separate path).
		$transient_calls = 0;
		$this->sut->delete( $product, array( 'force_delete' => true ) );
		$this->assertGreaterThanOrEqual( 1, $transient_calls, 'delete() must call wc_delete_product_transients() via clear_caches().' );

		remove_action( 'woocommerce_delete_product_transients', $counter );
	}

	/**
	 * @testdox H4 — wc_product_meta_lookup.onsale honours scheduled sale dates (future-dated → 0, current → 1, past-end → 0).
	 *
	 * Round-1 audit gap: there was no test for the scheduled-sale path.
	 * The pre-fix `onsale` calculation simply set `1` when `sale_price`
	 * was non-empty, even when `date_on_sale_from` was tomorrow or
	 * `date_on_sale_to` was last week. The fix routes through
	 * `row_is_on_sale()` which mirrors `WC_Product::is_on_sale()` exactly.
	 */
	public function test_onsale_lookup_flag_respects_scheduled_sale_window(): void {
		global $wpdb;

		// Active sale (no schedule) → onsale = 1.
		$active = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'H4 Active Sale',
				'sku'           => 'HPPS-H4-ACTIVE',
				'regular_price' => 20,
				'sale_price'    => 10,
				'price'         => 10,
			)
		);
		$this->do_hpps_sync();

		// Future-scheduled sale → onsale = 0 today.
		$future = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'H4 Future Sale',
				'sku'           => 'HPPS-H4-FUTURE',
				'regular_price' => 20,
				'sale_price'    => 10,
				'price'         => 20, // legacy WC_Product::is_on_sale() returns false until the start.
			)
		);
		$future_obj = wc_get_product( $future->get_id() );
		$future_obj->set_date_on_sale_from( ( new WC_DateTime() )->modify( '+7 days' )->getTimestamp() );
		$future_obj->save();

		// Expired-scheduled sale → onsale = 0 today.
		$expired = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'H4 Expired Sale',
				'sku'           => 'HPPS-H4-EXPIRED',
				'regular_price' => 20,
				'sale_price'    => 10,
				'price'         => 20,
			)
		);
		$expired_obj = wc_get_product( $expired->get_id() );
		$expired_obj->set_date_on_sale_to( ( new WC_DateTime() )->modify( '-7 days' )->getTimestamp() );
		$expired_obj->save();

		$this->do_hpps_sync();

		$active_onsale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT onsale FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$active->get_id()
			)
		);
		$future_onsale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT onsale FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$future->get_id()
			)
		);
		$expired_onsale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT onsale FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
				$expired->get_id()
			)
		);

		$this->assertSame( 1, $active_onsale, 'H4: an active sale must be flagged onsale=1.' );
		$this->assertSame(
			0,
			$future_onsale,
			'H4: a future-scheduled sale must be flagged onsale=0 — the pre-fix code set 1 just because sale_price was non-empty.'
		);
		$this->assertSame(
			0,
			$expired_onsale,
			'H4: an expired sale must be flagged onsale=0.'
		);
	}

	/**
	 * @testdox H8 — the placeholder wp_posts row mirrors the product's `wc_products.status` on create and tracks subsequent status changes.
	 *
	 * Round-1 fix: the placeholder post was hard-coded to `publish` on
	 * create, so a draft product had a published placeholder, breaking
	 * `get_post_status()`-driven flows (REST visibility, admin trash UI,
	 * third-party cache plugins). The fix initialises post_status from
	 * the product's actual status on create and accumulates status
	 * changes into the single placeholder UPDATE on save.
	 */
	public function test_placeholder_post_status_mirrors_product_status_on_create_and_update(): void {
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'   => 'H8 Status Sync',
				'sku'    => 'HPPS-H8-001',
				'status' => ProductStatus::DRAFT,
			)
		);

		$this->sut->create( $product );
		$id = (int) $product->get_id();

		$this->assertSame(
			ProductStatus::DRAFT,
			get_post_status( $id ),
			'H8: a draft product must have a draft placeholder post; the previous hard-coded `publish` broke get_post_status() for every non-published product.'
		);

		$reloaded = wc_get_product( $id );
		$reloaded->set_status( ProductStatus::PUBLISH );
		$reloaded->save();

		$this->assertSame(
			ProductStatus::PUBLISH,
			get_post_status( $id ),
			'H8: a status update on the product must also update the placeholder post_status; the two stores must not drift.'
		);
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
