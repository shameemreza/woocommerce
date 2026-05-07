<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableGroupedDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableVariationDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Product_Data_Store_CPT;

/**
 * Tests for the authoritative-source flag and how it affects the
 * data-store routing filter.
 *
 * Two contracts under test:
 *
 * 1. {@see ProductDataSynchronizer::authoritative_source()} returns
 *    `'hpps'` by default and is filterable through
 *    `woocommerce_hpps_authoritative_source`.
 * 2. {@see CustomProductsTableController::filter_product_data_store()}
 *    routes to the HPPS data store when the source is `'hpps'` and to
 *    the legacy CPT data store when the source is `'cpt'`. This is the
 *    seam Phase 2 dual-mode cutover hangs off.
 */
class AuthoritativeSourceTests extends HppsTestCase {
	use HPPSToggleTrait;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();

		// Force the controller to wire its filters.
		wc_get_container()->get( CustomProductsTableController::class );
	}

	public function tearDown(): void {
		remove_all_filters( 'woocommerce_hpps_authoritative_source' );

		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| ProductDataSynchronizer::authoritative_source()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox authoritative_source() returns 'hpps' by default.
	 */
	public function test_default_authoritative_source_is_hpps(): void {
		$synchronizer = wc_get_container()->get( ProductDataSynchronizer::class );

		$this->assertSame( 'hpps', $synchronizer->authoritative_source() );
	}

	/**
	 * @testdox the woocommerce_hpps_authoritative_source filter can flip the value.
	 */
	public function test_filter_can_override_authoritative_source(): void {
		$synchronizer = wc_get_container()->get( ProductDataSynchronizer::class );

		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): string => 'cpt' );

		$this->assertSame( 'cpt', $synchronizer->authoritative_source() );
	}

	/**
	 * @testdox the filter is cast to string, so a truthy non-string returns the original behaviour at the call site.
	 */
	public function test_filter_value_is_cast_to_string(): void {
		$synchronizer = wc_get_container()->get( ProductDataSynchronizer::class );

		// A buggy extension returning bool true should not crash; the
		// router only treats the literal string 'hpps' as opting in to
		// HPPS, so 1 (cast to "1") will fall through to the legacy store.
		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): bool => true );

		$this->assertSame( '1', $synchronizer->authoritative_source() );
	}

	/*
	|--------------------------------------------------------------------------
	| Data-store routing via the filter chain.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox filter_product_data_store routes to the HPPS simple data store when source = 'hpps'.
	 */
	public function test_routing_returns_hpps_simple_store_by_default(): void {
		$result = apply_filters( 'woocommerce_product_data_store', new WC_Product_Data_Store_CPT() );

		$this->assertInstanceOf( ProductsTableDataStore::class, $result );
	}

	/**
	 * @testdox filter_product_data_store routes to the HPPS variable data store on the variable filter.
	 */
	public function test_routing_returns_hpps_variable_store_for_variable_filter(): void {
		$result = apply_filters( 'woocommerce_product-variable_data_store', new WC_Product_Data_Store_CPT() );

		$this->assertInstanceOf( ProductsTableVariableDataStore::class, $result );
	}

	/**
	 * @testdox filter_product_data_store routes to the HPPS variation data store on the variation filter.
	 */
	public function test_routing_returns_hpps_variation_store_for_variation_filter(): void {
		$result = apply_filters( 'woocommerce_product-variation_data_store', new WC_Product_Data_Store_CPT() );

		$this->assertInstanceOf( ProductsTableVariationDataStore::class, $result );
	}

	/**
	 * @testdox filter_product_data_store routes to the HPPS grouped data store on the grouped filter.
	 */
	public function test_routing_returns_hpps_grouped_store_for_grouped_filter(): void {
		$result = apply_filters( 'woocommerce_product-grouped_data_store', new WC_Product_Data_Store_CPT() );

		$this->assertInstanceOf( ProductsTableGroupedDataStore::class, $result );
	}

	/**
	 * @testdox filter_product_data_store falls back to the supplied legacy data store when the authoritative source is 'cpt'.
	 */
	public function test_routing_falls_back_to_legacy_when_source_is_cpt(): void {
		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): string => 'cpt' );

		$legacy = new WC_Product_Data_Store_CPT();
		$result = apply_filters( 'woocommerce_product_data_store', $legacy );

		// The filter callback must hand the original legacy store back
		// untouched — no HPPS instance, no clone, no replacement.
		$this->assertSame( $legacy, $result );
		$this->assertNotInstanceOf( ProductsTableDataStore::class, $result );
	}

	/**
	 * @testdox the variable / variation / grouped routing branches all honour the cpt fallback.
	 */
	public function test_routing_falls_back_to_legacy_for_typed_filters_when_source_is_cpt(): void {
		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): string => 'cpt' );

		$legacy = new WC_Product_Data_Store_CPT();

		foreach (
			array(
				'woocommerce_product-variable_data_store',
				'woocommerce_product-variation_data_store',
				'woocommerce_product-grouped_data_store',
				'woocommerce_product-external_data_store',
			)
			as $filter
		) {
			$result = apply_filters( $filter, $legacy );
			$this->assertSame(
				$legacy,
				$result,
				"Filter {$filter} should have returned the legacy store when authoritative source is 'cpt'."
			);
		}
	}

	/**
	 * @testdox routing flips back to HPPS the moment the filter is removed.
	 */
	public function test_routing_returns_to_hpps_after_filter_removal(): void {
		$legacy = new WC_Product_Data_Store_CPT();

		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): string => 'cpt' );
		$this->assertSame( $legacy, apply_filters( 'woocommerce_product_data_store', $legacy ) );

		remove_all_filters( 'woocommerce_hpps_authoritative_source' );
		$this->assertInstanceOf( ProductsTableDataStore::class, apply_filters( 'woocommerce_product_data_store', $legacy ) );
	}

	/**
	 * @testdox routing always returns the supplied legacy data store when HPPS itself is disabled, regardless of authoritative_source.
	 */
	public function test_routing_returns_legacy_when_hpps_feature_is_off(): void {
		$this->toggle_hpps_feature_and_usage( false );

		// Deliberately set authoritative_source to 'hpps' to confirm that
		// the feature gate runs first — flipping the source has no effect
		// while HPPS is off.
		add_filter( 'woocommerce_hpps_authoritative_source', static fn(): string => 'hpps' );

		$legacy = new WC_Product_Data_Store_CPT();
		$result = apply_filters( 'woocommerce_product_data_store', $legacy );

		$this->assertSame( $legacy, $result );
	}
}
