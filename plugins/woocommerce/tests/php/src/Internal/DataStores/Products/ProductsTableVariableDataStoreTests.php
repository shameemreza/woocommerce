<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Tests for {@see ProductsTableVariableDataStore}: the variable-product
 * specific behaviour layered on top of the base HPPS data store —
 * children read, visibility split, price/stock probes, attribute lookup,
 * matching variation resolution, and the bulk variation operations.
 */
class ProductsTableVariableDataStoreTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var ProductsTableVariableDataStore
	 */
	private ProductsTableVariableDataStore $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->sut = wc_get_container()->get( ProductsTableVariableDataStore::class );
	}

	public function tearDown(): void {
		// Reset the OOS visibility option each test so cross-test pollution
		// doesn't change the visible-children math.
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox create() persists a variable parent row in wc_products with type=variable.
	 */
	public function test_create_persists_variable_parent_row(): void {
		global $wpdb;

		$variable = $this->build_minimal_variable_product();
		$id       = $variable->get_id();

		$this->assertGreaterThan( 0, $id );
		$this->assert_product_record_existence( $id, true, true );

		$type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d',
				ProductsTableDataStore::get_products_table_name(),
				$id
			)
		);
		$this->assertSame( ProductType::VARIABLE, $type );
	}

	/**
	 * @testdox read_children() returns the all/visible split ordered by menu_order.
	 */
	public function test_read_children_returns_all_and_visible(): void {
		$variable = WC_Helper_Product::create_variation_product();
		// Force a fresh read past any transient cache the helper warmed.
		$children = $this->sut->read_children( $variable, true );

		$this->assertArrayHasKey( 'all', $children );
		$this->assertArrayHasKey( 'visible', $children );
		$this->assertNotEmpty( $children['all'] );

		// Without OOS hidden, visible == all.
		$this->assertSame( $children['all'], $children['visible'] );

		// All entries are real variation IDs under this parent.
		foreach ( $children['all'] as $variation_id ) {
			$row_parent = $this->variation_row_field( (int) $variation_id, 'parent_id' );
			$row_type   = $this->variation_row_field( (int) $variation_id, 'type' );
			$this->assertSame( (int) $variable->get_id(), (int) $row_parent );
			$this->assertSame( ProductType::VARIATION, $row_type );
		}
	}

	/**
	 * @testdox read_children() drops out-of-stock children from the visible list when the OOS-hide option is on.
	 */
	public function test_read_children_excludes_out_of_stock_when_option_enabled(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );

		$variable = WC_Helper_Product::create_variation_product();
		$children = $this->sut->read_children( $variable, true );

		$this->assertNotEmpty( $children['all'] );
		$this->assertSame( $children['all'], $children['visible'] );

		// Mark one variation OOS, force a fresh read, and confirm it falls
		// out of `visible` while staying in `all`.
		$victim_id    = (int) $children['all'][0];
		$victim       = wc_get_product( $victim_id );
		$victim->set_stock_status( ProductStockStatus::OUT_OF_STOCK );
		$victim->save();

		$updated = $this->sut->read_children( $variable, true );
		$this->assertContains( $victim_id, $updated['all'] );
		$this->assertNotContains( $victim_id, $updated['visible'] );
	}

	/**
	 * @testdox child_is_in_stock() returns true while at least one variation is in stock and false when none are.
	 */
	public function test_child_is_in_stock_reflects_children_state(): void {
		$variable = WC_Helper_Product::create_variation_product();

		$this->assertTrue( $this->sut->child_is_in_stock( $variable ) );

		// Knock every child OOS.
		foreach ( $variable->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			$child->set_stock_status( ProductStockStatus::OUT_OF_STOCK );
			$child->save();
		}

		$this->assertFalse( $this->sut->child_is_in_stock( $variable ) );
	}

	/**
	 * @testdox child_has_stock_status() returns true when any variation matches the requested status.
	 */
	public function test_child_has_stock_status_matches_specific_status(): void {
		$variable    = WC_Helper_Product::create_variation_product();
		$first_child = wc_get_product( (int) $variable->get_children()[0] );
		$first_child->set_stock_status( ProductStockStatus::ON_BACKORDER );
		$first_child->save();

		$this->assertTrue( $this->sut->child_has_stock_status( $variable, ProductStockStatus::ON_BACKORDER ) );
		$this->assertTrue( $this->sut->child_has_stock_status( $variable, ProductStockStatus::IN_STOCK ) );
	}

	/**
	 * @testdox sync_price() refreshes wc_product_meta_lookup with the children's MIN/MAX price.
	 */
	public function test_sync_price_updates_lookup_min_max(): void {
		global $wpdb;

		$variable = WC_Helper_Product::create_variation_product();

		$this->sut->sync_price( $variable );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$variable->get_id()
			)
		);

		$this->assertNotNull( $row );
		// Helper variations are priced 10..19 → expect MIN=10, MAX=19.
		$this->assertSame( 10.0, (float) $row->min_price );
		$this->assertSame( 19.0, (float) $row->max_price );
	}

	/**
	 * @testdox find_matching_product_variation() returns the exact variation when every attribute is set, and falls back to "any" when one is empty.
	 */
	public function test_find_matching_product_variation_resolves_exact_and_any(): void {
		$variable = WC_Helper_Product::create_variation_product();

		$exact_id = $this->sut->find_matching_product_variation(
			$variable,
			array(
				'attribute_pa_size'   => 'huge',
				'attribute_pa_colour' => 'blue',
				'attribute_pa_number' => '2',
			)
		);
		$this->assertGreaterThan( 0, $exact_id );
		$this->assertSame( 'DUMMY SKU VARIABLE HUGE BLUE 2', wc_get_product( $exact_id )->get_sku() );

		// "Any number" — number is unspecified, so resolver picks the
		// variation that stores an empty value for `pa_number`.
		$any_id = $this->sut->find_matching_product_variation(
			$variable,
			array(
				'attribute_pa_size'   => 'huge',
				'attribute_pa_colour' => 'blue',
				'attribute_pa_number' => '',
			)
		);
		$this->assertGreaterThan( 0, $any_id );
		$this->assertSame( 'DUMMY SKU VARIABLE HUGE BLUE ANY NUMBER', wc_get_product( $any_id )->get_sku() );
	}

	/**
	 * @testdox find_matching_product_variation() returns 0 when no attributes are passed.
	 */
	public function test_find_matching_product_variation_returns_zero_for_empty_input(): void {
		$variable = WC_Helper_Product::create_variation_product();

		$this->assertSame( 0, $this->sut->find_matching_product_variation( $variable, array() ) );
	}

	/**
	 * @testdox read_variation_attributes() returns one entry per `is_variation` attribute keyed by the parent attribute name.
	 */
	public function test_read_variation_attributes_returns_grouped_options(): void {
		$variable = WC_Helper_Product::create_variation_product();

		$attrs = $this->sut->read_variation_attributes( $variable );

		// The helper sets up size + colour + number, all flagged as variation.
		$this->assertArrayHasKey( 'pa_size', $attrs );
		$this->assertArrayHasKey( 'pa_colour', $attrs );
		$this->assertArrayHasKey( 'pa_number', $attrs );

		$this->assertContains( 'huge', $attrs['pa_size'] );
		$this->assertContains( 'red', $attrs['pa_colour'] );

		// "Any X" expansion: when a variation stores an empty value for an
		// attribute, the parent's full option list takes its place. The
		// helper has one such variation for `pa_number`, so we expect the
		// full set of options to surface.
		$this->assertEqualsCanonicalizing( array( '0', '1', '2' ), $attrs['pa_number'] );
	}

	/**
	 * @testdox sync_variation_names() updates the variation rows in wc_products when the parent is renamed.
	 */
	public function test_sync_variation_names_updates_children_after_rename(): void {
		global $wpdb;

		$variable     = WC_Helper_Product::create_variation_product();
		$old_name     = $variable->get_name();
		$variation_id = (int) $variable->get_children()[0];
		$old_title    = $this->variation_row_field( $variation_id, 'name' );

		// The titles include the parent's name as the prefix; sanity-check
		// this assumption so the assertion below is meaningful.
		$this->assertStringContainsString( $old_name, (string) $old_title );

		$this->sut->sync_variation_names( $variable, $old_name, 'Renamed Variable' );

		$new_title = $this->variation_row_field( $variation_id, 'name' );
		$this->assertStringContainsString( 'Renamed Variable', (string) $new_title );
		$this->assertStringNotContainsString( $old_name, (string) $new_title );
	}

	/**
	 * @testdox sync_managed_variation_stock_status() mirrors the parent's stock status onto every child that doesn't manage its own stock.
	 */
	public function test_sync_managed_variation_stock_status_propagates_to_children(): void {
		$variable = WC_Helper_Product::create_variation_product();

		// Flip the parent into "manage stock" mode and mark it OOS. We do
		// this directly on the row so the test stays focused on the sync
		// behaviour rather than the higher-level setter cascade.
		$variable->set_manage_stock( true );
		$variable->set_stock_status( ProductStockStatus::OUT_OF_STOCK );
		$variable->save();

		$this->sut->sync_managed_variation_stock_status( $variable );

		foreach ( $variable->get_children() as $child_id ) {
			$status = $this->variation_row_field( (int) $child_id, 'stock_status' );
			$this->assertSame( ProductStockStatus::OUT_OF_STOCK, $status );
		}
	}

	/**
	 * @testdox delete_variations() with force_delete clears every variation row out of wc_products.
	 */
	public function test_delete_variations_force_clears_children_rows(): void {
		global $wpdb;

		$variable = WC_Helper_Product::create_variation_product();
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );

		$this->sut->delete_variations( $variable->get_id(), true );

		$remaining = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE parent_id = %d AND type = %s',
				ProductsTableDataStore::get_products_table_name(),
				$variable->get_id(),
				ProductType::VARIATION
			)
		);
		$this->assertSame( '0', (string) $remaining );
	}

	/**
	 * @testdox sort_all_product_variations() resets every child's menu_order to its position in the sorted list.
	 */
	public function test_sort_all_product_variations_resets_menu_order(): void {
		global $wpdb;

		$variable = WC_Helper_Product::create_variation_product();
		$children = $variable->get_children();

		// Scramble the menu_order across children so the resort has work to do.
		$position = 100;
		foreach ( $children as $child_id ) {
			$wpdb->update(
				ProductsTableDataStore::get_products_table_name(),
				array( 'menu_order' => $position ),
				array( 'id' => (int) $child_id ),
				array( '%d' ),
				array( '%d' )
			);
			$position -= 7;
		}

		$this->sut->sort_all_product_variations( $variable->get_id() );

		// Pull them back in the canonical sort order: menu_order ASC, id ASC.
		$ordered = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE parent_id = %d AND type = %s ORDER BY menu_order ASC, id ASC',
				ProductsTableDataStore::get_products_table_name(),
				$variable->get_id(),
				ProductType::VARIATION
			)
		);

		// Each row's menu_order should equal its position in the ordered list.
		foreach ( $ordered as $position => $child_id ) {
			$mo = $this->variation_row_field( (int) $child_id, 'menu_order' );
			$this->assertSame( (int) $position, (int) $mo );
		}
	}

	/**
	 * Build a bare-bones variable product without any variations attached.
	 * Useful for tests that only exercise the parent row.
	 */
	private function build_minimal_variable_product(): WC_Product_Variable {
		$variable = new WC_Product_Variable();
		$variable->set_props(
			array(
				'name' => 'HPPS Variable Parent',
				'sku'  => 'HPPS-VARIABLE-PARENT-' . uniqid(),
			)
		);
		$variable->save();
		return $variable;
	}

	/**
	 * Read a single column from a variation row in `wc_products`.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $column       Column name (already trusted).
	 * @return mixed
	 */
	private function variation_row_field( int $variation_id, string $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$column} FROM " . ProductsTableDataStore::get_products_table_name() . ' WHERE id = %d',
				$variation_id
			)
		);
	}
}
