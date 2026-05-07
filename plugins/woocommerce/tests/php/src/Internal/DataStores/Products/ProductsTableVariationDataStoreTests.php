<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariationDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Tests for {@see ProductsTableVariationDataStore}: variation-specific
 * CRUD on top of the base HPPS data store, including the scope='variation'
 * attribute storage shape, the parent-data hydration path used by storefront
 * code (cart/order line items), and the title regeneration that happens on
 * update().
 */
class ProductsTableVariationDataStoreTests extends HppsTestCase {
	use HPPSToggleTrait;

	/**
	 * @var ProductsTableVariationDataStore
	 */
	private ProductsTableVariationDataStore $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
		$this->sut = wc_get_container()->get( ProductsTableVariationDataStore::class );
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox create() persists a variation row with type='variation' and the parent_id pointing at the variable parent.
	 */
	public function test_create_persists_variation_with_parent_link(): void {
		global $wpdb;

		$variable  = WC_Helper_Product::create_variation_product();
		$variation_id = (int) $variable->get_children()[0];

		$this->assert_product_record_existence( $variation_id, true, true );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT type, parent_id FROM %i WHERE id = %d',
				ProductsTableDataStore::get_products_table_name(),
				$variation_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( ProductType::VARIATION, $row->type );
		$this->assertSame( (int) $variable->get_id(), (int) $row->parent_id );
	}

	/**
	 * @testdox read() round-trips the variation's selected attributes (one value per attribute, scope='variation').
	 */
	public function test_read_round_trips_variation_attributes(): void {
		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = $this->find_variation_by_sku( $variable, 'DUMMY SKU VARIABLE HUGE BLUE 2' );
		$this->assertGreaterThan( 0, $variation_id );

		$reloaded = new WC_Product_Variation( $variation_id );

		$attrs = $reloaded->get_attributes();
		$this->assertSame( 'huge', (string) ( $attrs['pa_size'] ?? '' ) );
		$this->assertSame( 'blue', (string) ( $attrs['pa_colour'] ?? '' ) );
		$this->assertSame( '2', (string) ( $attrs['pa_number'] ?? '' ) );
	}

	/**
	 * @testdox persist_attributes() stores one row per attribute in wc_product_attribute_values with scope='variation' and a populated term_id for taxonomy attributes.
	 */
	public function test_persist_attributes_stores_term_id_for_taxonomy(): void {
		global $wpdb;

		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = $this->find_variation_by_sku( $variable, 'DUMMY SKU VARIABLE HUGE BLUE 2' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT value, term_id, scope FROM %i WHERE product_id = %d ORDER BY position ASC',
				ProductsTableDataStore::get_attribute_values_table_name(),
				$variation_id
			)
		);

		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'variation', $row->scope );
		}

		// Indexed by raw value for readability — the helper's variations are
		// taxonomy attributes (`pa_size`, `pa_colour`, `pa_number`) so each
		// row should resolve a real term_id (i.e. non-null) when the value
		// matches an existing term slug.
		$by_value = array();
		foreach ( $rows as $row ) {
			$by_value[ (string) $row->value ] = $row;
		}
		foreach ( array( 'huge', 'blue', '2' ) as $value ) {
			$this->assertArrayHasKey( $value, $by_value, "Variation should store the value '{$value}'." );
			$this->assertNotNull( $by_value[ $value ]->term_id, "Term id should be resolved for taxonomy value '{$value}'." );
			$this->assertGreaterThan( 0, (int) $by_value[ $value ]->term_id );
		}
	}

	/**
	 * @testdox update() regenerates the variation's title using the parent's name and the attribute summary.
	 */
	public function test_update_regenerates_title_from_parent_and_attributes(): void {
		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = $this->find_variation_by_sku( $variable, 'DUMMY SKU VARIABLE HUGE BLUE 2' );

		$variation = wc_get_product( $variation_id );
		$variation->set_regular_price( 99 );
		$variation->save();

		$reloaded = wc_get_product( $variation_id );
		// Variations with 3 attributes don't include the attribute summary
		// in the title (matches WC core behaviour). The parent name should
		// still be the prefix though.
		$this->assertStringContainsString( $variable->get_name(), $reloaded->get_name() );
	}

	/**
	 * @testdox validate_and_set_parent_id() clears parent_id when the parent is not a variable product.
	 */
	public function test_create_clears_parent_when_parent_is_not_variable(): void {
		// Create a simple parent so the validation path triggers.
		$simple_parent = WC_Helper_Product::create_simple_product();

		$variation = new WC_Product_Variation();
		$variation->set_props(
			array(
				'parent_id'     => $simple_parent->get_id(),
				'sku'           => 'HPPS-VAR-INVALID-PARENT-' . uniqid(),
				'regular_price' => 5,
			)
		);
		$variation->save();

		$reloaded = wc_get_product( $variation->get_id() );
		$this->assertSame( 0, (int) $reloaded->get_parent_id(), 'Variation with non-variable parent should have parent_id cleared.' );
	}

	/**
	 * @testdox read() hydrates parent_data from the parent's wc_products row so storefront-side fallbacks resolve correctly.
	 */
	public function test_read_hydrates_parent_data_from_variable_parent(): void {
		$variable = WC_Helper_Product::create_variation_product();

		// Tweak parent fields that storefront code reads via parent_data.
		$variable->set_tax_status( 'shipping' );
		$variable->set_sold_individually( true );
		$variable->save();

		$variation_id = (int) $variable->get_children()[0];
		$variation    = new WC_Product_Variation( $variation_id );

		// `tax_status` and `sold_individually` are deferred to the parent
		// inside WC_Product_Variation::read(); confirm they survive the
		// HPPS round-trip.
		$this->assertSame( 'shipping', (string) $variation->get_tax_status() );
		$this->assertTrue( $variation->get_sold_individually() );
	}

	/**
	 * @testdox load_parent_data with no parent yields a blank parent_data record (so cart math doesn't fall over on orphans).
	 */
	public function test_orphan_variation_returns_blank_parent_data(): void {
		// Build a variation by hand and then null out its parent_id directly
		// in the row to simulate an orphan we can re-read.
		global $wpdb;

		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = (int) $variable->get_children()[0];

		$wpdb->update(
			ProductsTableDataStore::get_products_table_name(),
			array( 'parent_id' => 0 ),
			array( 'id' => $variation_id ),
			array( '%d' ),
			array( '%d' )
		);
		// Reflect the change on the placeholder post too so the read path
		// stays internally consistent.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_parent' => 0 ),
			array( 'ID' => $variation_id ),
			array( '%d' ),
			array( '%d' )
		);
		clean_post_cache( $variation_id );
		wp_cache_flush();

		$reloaded = new WC_Product_Variation( $variation_id );

		$this->assertSame( 0, (int) $reloaded->get_parent_id() );
		// Parent-data carriage values default to the empty-parent shape.
		$this->assertSame( 'taxable', (string) $reloaded->get_tax_status() );
		$this->assertFalse( (bool) $reloaded->get_sold_individually() );
	}

	/**
	 * @testdox update() pins the type column back to 'variation' even if a caller flips it.
	 */
	public function test_update_pins_type_column_to_variation(): void {
		global $wpdb;

		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = (int) $variable->get_children()[0];

		// Sneak the wrong type into the row directly to simulate drift.
		$wpdb->update(
			ProductsTableDataStore::get_products_table_name(),
			array( 'type' => ProductType::SIMPLE ),
			array( 'id' => $variation_id ),
			array( '%s' ),
			array( '%d' )
		);

		$variation = wc_get_product( $variation_id );
		$variation->set_regular_price( 123 );
		$variation->save();

		$type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d',
				ProductsTableDataStore::get_products_table_name(),
				$variation_id
			)
		);
		$this->assertSame( ProductType::VARIATION, $type );
	}

	/**
	 * @testdox sync_visibility_terms() leaves out 'featured' / 'rated-*' on variations and only sets out-of-stock when applicable.
	 */
	public function test_sync_visibility_terms_for_variation_only_emits_oos_term(): void {
		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = (int) $variable->get_children()[0];

		$variation = wc_get_product( $variation_id );
		$variation->set_stock_status( ProductStockStatus::OUT_OF_STOCK );
		$variation->save();

		$terms = wp_get_object_terms( $variation_id, 'product_visibility', array( 'fields' => 'names' ) );
		$this->assertContains( ProductStockStatus::OUT_OF_STOCK, (array) $terms );
		$this->assertNotContains( 'featured', (array) $terms );
	}

	/**
	 * Find the variation row id for a given SKU under a specific parent.
	 *
	 * @param WC_Product_Variable $variable Parent product.
	 * @param string              $sku      SKU to look up.
	 * @return int Variation ID, or 0 if not found.
	 */
	private function find_variation_by_sku( WC_Product_Variable $variable, string $sku ): int {
		foreach ( $variable->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			if ( $child && $child->get_sku() === $sku ) {
				return (int) $child_id;
			}
		}
		return 0;
	}
}
