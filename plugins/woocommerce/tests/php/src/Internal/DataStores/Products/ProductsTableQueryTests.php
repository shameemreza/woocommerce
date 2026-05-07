<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableQuery;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Simple;

/**
 * Tests for {@see ProductsTableQuery} and the
 * {@see ProductsTableDataStore::query()} entry point.
 *
 * Two contracts under test:
 *
 *   1. The HPPS-native query path produces the same result shape and
 *      filtering behaviour as the legacy CPT data store for the
 *      column-mappable filters listed in COLUMN_MAP, plus the
 *      taxonomy filters we now resolve via EXISTS subqueries on
 *      `wp_term_relationships` (category, tag, shipping_class, plus
 *      the term-id alt forms).
 *   2. Anything that still hits an unsupported feature (meta_query,
 *      tax_query, date queries, full-text search, reviews_allowed)
 *      falls back to the legacy data store, so the caller's results
 *      stay correct even before we migrate every query path to HPPS.
 *
 * The test suite intentionally drives `wc_get_products()` end-to-end
 * (not just `ProductsTableQuery::get_results()` in isolation) — that's
 * the surface real callers depend on.
 */
class ProductsTableQueryTests extends HppsTestCase {
	use HPPSToggleTrait;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| Result shape.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox wc_get_products() returns an array of WC_Product instances on a fresh HPPS store.
	 */
	public function test_returns_array_of_products_by_default(): void {
		$id = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-DEFAULT-1' ) );

		$results = wc_get_products( array( 'sku' => 'HPPS-Q-DEFAULT-1' ) );

		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );
		$this->assertInstanceOf( \WC_Product::class, $results[0] );
		$this->assertSame( $id, $results[0]->get_id() );
	}

	/**
	 * @testdox return = 'ids' returns a list of integer IDs.
	 */
	public function test_return_ids_returns_int_array(): void {
		$id = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-IDS-1' ) );

		$results = wc_get_products(
			array(
				'sku'    => 'HPPS-Q-IDS-1',
				'return' => 'ids',
			)
		);

		$this->assertIsArray( $results );
		$this->assertSame( array( $id ), $results );
	}

	/**
	 * @testdox paginate = true returns an object with products / total / max_num_pages.
	 */
	public function test_paginate_returns_pagination_envelope(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->migrated_simple_product( array( 'sku' => "HPPS-Q-PAGE-{$i}" ) );
		}

		$result = wc_get_products(
			array(
				'sku'      => 'HPPS-Q-PAGE-*',
				'limit'    => 2,
				'paginate' => true,
			)
		);

		$this->assertIsObject( $result );
		$this->assertObjectHasProperty( 'products', $result );
		$this->assertObjectHasProperty( 'total', $result );
		$this->assertObjectHasProperty( 'max_num_pages', $result );
		$this->assertCount( 2, $result->products );
		$this->assertSame( 5, $result->total );
		$this->assertSame( 3, $result->max_num_pages );
	}

	/*
	|--------------------------------------------------------------------------
	| Column-mappable filters.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox sku filter matches the literal SKU on a migrated product.
	 */
	public function test_filter_by_exact_sku(): void {
		$wanted   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-SKU-WANTED' ) );
		$unwanted = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-SKU-OTHER' ) );

		$results = wc_get_products(
			array(
				'sku'    => 'HPPS-Q-SKU-WANTED',
				'return' => 'ids',
			)
		);

		$this->assertContains( $wanted, $results );
		$this->assertNotContains( $unwanted, $results );
	}

	/**
	 * @testdox sku = '*' matches every product with a non-empty SKU (and skips ones without).
	 */
	public function test_filter_by_sku_wildcard(): void {
		$with_sku    = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-WILDCARD-1' ) );
		$without_sku = $this->migrated_simple_product( array( 'sku' => '' ) );

		$results = wc_get_products(
			array(
				'sku'    => '*',
				'return' => 'ids',
				'limit'  => -1,
			)
		);

		$this->assertContains( $with_sku, $results );
		$this->assertNotContains( $without_sku, $results );
	}

	/**
	 * @testdox status filter narrows to a specific post status.
	 */
	public function test_filter_by_status(): void {
		$published = $this->migrated_simple_product(
			array(
				'sku'    => 'HPPS-Q-STATUS-PUB',
				'status' => ProductStatus::PUBLISH,
			)
		);
		$draft     = $this->migrated_simple_product(
			array(
				'sku'    => 'HPPS-Q-STATUS-DRAFT',
				'status' => ProductStatus::DRAFT,
			)
		);

		$results = wc_get_products(
			array(
				'status' => ProductStatus::DRAFT,
				'return' => 'ids',
				'limit'  => -1,
			)
		);

		$this->assertContains( $draft, $results );
		$this->assertNotContains( $published, $results );
	}

	/**
	 * @testdox stock_status filter respects native column values.
	 */
	public function test_filter_by_stock_status(): void {
		$in_stock     = $this->migrated_simple_product(
			array(
				'sku'          => 'HPPS-Q-STOCK-IN',
				'stock_status' => ProductStockStatus::IN_STOCK,
			)
		);
		$out_of_stock = $this->migrated_simple_product(
			array(
				'sku'          => 'HPPS-Q-STOCK-OUT',
				'stock_status' => ProductStockStatus::OUT_OF_STOCK,
			)
		);

		$results = wc_get_products(
			array(
				'stock_status' => ProductStockStatus::OUT_OF_STOCK,
				'return'       => 'ids',
				'limit'        => -1,
			)
		);

		$this->assertContains( $out_of_stock, $results );
		$this->assertNotContains( $in_stock, $results );
	}

	/**
	 * @testdox boolean coercion: virtual = true matches `virtual = 1`.
	 */
	public function test_filter_by_virtual_bool_coercion(): void {
		$virtual_id = $this->migrated_simple_product(
			array(
				'sku'     => 'HPPS-Q-VIRTUAL',
				'virtual' => true,
			)
		);
		$physical_id = $this->migrated_simple_product(
			array(
				'sku' => 'HPPS-Q-PHYSICAL',
			)
		);

		$results = wc_get_products(
			array(
				'virtual' => true,
				'return'  => 'ids',
				'limit'   => -1,
			)
		);

		$this->assertContains( $virtual_id, $results );
		$this->assertNotContains( $physical_id, $results );
	}

	/**
	 * @testdox include / exclude scope a query to (or away from) explicit IDs.
	 */
	public function test_filter_by_include_exclude(): void {
		$ids = array(
			$this->migrated_simple_product( array( 'sku' => 'HPPS-Q-INC-A' ) ),
			$this->migrated_simple_product( array( 'sku' => 'HPPS-Q-INC-B' ) ),
			$this->migrated_simple_product( array( 'sku' => 'HPPS-Q-INC-C' ) ),
		);

		$included = wc_get_products(
			array(
				'include' => array( $ids[0], $ids[2] ),
				'return'  => 'ids',
				'limit'   => -1,
			)
		);
		$this->assertEqualsCanonicalizing( array( $ids[0], $ids[2] ), $included );

		$excluded = wc_get_products(
			array(
				'exclude' => array( $ids[1] ),
				'sku'     => 'HPPS-Q-INC-*',
				'return'  => 'ids',
				'limit'   => -1,
			)
		);
		$this->assertEqualsCanonicalizing( array( $ids[0], $ids[2] ), $excluded );
	}

	/*
	|--------------------------------------------------------------------------
	| Ordering.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox orderby = 'price' sorts by the wc_products.price column ascending.
	 */
	public function test_orderby_price_ascending(): void {
		$cheap     = $this->migrated_simple_product(
			array(
				'sku'           => 'HPPS-Q-ORDER-CHEAP',
				'regular_price' => 5,
			)
		);
		$expensive = $this->migrated_simple_product(
			array(
				'sku'           => 'HPPS-Q-ORDER-EXPENSIVE',
				'regular_price' => 50,
			)
		);

		$results = wc_get_products(
			array(
				'sku'     => 'HPPS-Q-ORDER-*',
				'orderby' => 'price',
				'order'   => 'ASC',
				'return'  => 'ids',
				'limit'   => -1,
			)
		);

		$this->assertSame( array( $cheap, $expensive ), $results );
	}

	/**
	 * @testdox orderby = 'include' preserves the order of the include list.
	 */
	public function test_orderby_include_preserves_input_order(): void {
		$a = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-FIELD-A' ) );
		$b = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-FIELD-B' ) );
		$c = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-FIELD-C' ) );

		$results = wc_get_products(
			array(
				'include' => array( $b, $c, $a ),
				'orderby' => 'include',
				'return'  => 'ids',
				'limit'   => -1,
			)
		);

		$this->assertSame( array( $b, $c, $a ), $results );
	}

	/*
	|--------------------------------------------------------------------------
	| is_supported() / fallback to the legacy CPT data store.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox is_supported() returns true for a vanilla query-vars set.
	 */
	public function test_is_supported_for_vanilla_query(): void {
		$query = new ProductsTableQuery(
			array(
				'status' => ProductStatus::PUBLISH,
				'type'   => ProductType::SIMPLE,
				'limit'  => 10,
			)
		);

		$this->assertTrue( $query->is_supported() );
	}

	/**
	 * @testdox is_supported() returns false when an unsupported key is set (e.g. category, meta_query).
	 *
	 * @dataProvider unsupported_keys_provider
	 *
	 * @param string $key   Unsupported query var.
	 * @param mixed  $value Sample value.
	 */
	public function test_is_supported_returns_false_for_unsupported_keys( string $key, $value ): void {
		$query = new ProductsTableQuery( array( $key => $value ) );

		$this->assertFalse( $query->is_supported(), "{$key} should force a CPT fallback." );
	}

	/**
	 * @return array<string, array{0:string,1:mixed}>
	 */
	public function unsupported_keys_provider(): array {
		return array(
			'meta_query'      => array( 'meta_query', array( array( 'key' => '_custom', 'value' => 'foo' ) ) ),
			'tax_query'       => array( 'tax_query', array( array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'shoes' ) ) ),
			'date_query'      => array( 'date_query', array( array( 'after' => '2024-01-01' ) ) ),
			'date_created'    => array( 'date_created', '2024-01-01..2024-12-31' ),
			'reviews_allowed' => array( 'reviews_allowed', true ),
			's'               => array( 's', 'shirt' ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Taxonomy filters (native EXISTS subqueries, no CPT fallback).
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox category filter (slug form) is handled natively and returns only products in the requested category.
	 */
	public function test_filter_by_category_slug_native(): void {
		$wanted   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-CAT-IN' ) );
		$unwanted = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-CAT-OUT' ) );

		$term_id = wp_create_term( 'hpps-q-cat', 'product_cat' )['term_id'];
		wp_set_object_terms( $wanted, array( $term_id ), 'product_cat' );

		// Sanity-check that this is actually answered by the HPPS path,
		// not by the CPT fallback.
		$query = new ProductsTableQuery( array( 'category' => array( 'hpps-q-cat' ) ) );
		$this->assertTrue( $query->is_supported(), 'category should be a natively supported filter.' );

		$results = wc_get_products(
			array(
				'category' => array( 'hpps-q-cat' ),
				'return'   => 'ids',
				'limit'    => -1,
			)
		);

		$this->assertContains( $wanted, $results );
		$this->assertNotContains( $unwanted, $results );
	}

	/**
	 * @testdox tag filter (slug form) is handled natively and returns only products with the requested tag.
	 */
	public function test_filter_by_tag_slug_native(): void {
		$wanted   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-TAG-IN' ) );
		$unwanted = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-TAG-OUT' ) );

		$term_id = wp_create_term( 'hpps-q-tag', 'product_tag' )['term_id'];
		wp_set_object_terms( $wanted, array( $term_id ), 'product_tag' );

		$results = wc_get_products(
			array(
				'tag'    => array( 'hpps-q-tag' ),
				'return' => 'ids',
				'limit'  => -1,
			)
		);

		$this->assertContains( $wanted, $results );
		$this->assertNotContains( $unwanted, $results );
	}

	/**
	 * @testdox shipping_class filter (slug form) is handled natively.
	 */
	public function test_filter_by_shipping_class_slug_native(): void {
		$wanted   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-SHIP-IN' ) );
		$unwanted = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-SHIP-OUT' ) );

		$term_id = wp_create_term( 'hpps-q-ship', 'product_shipping_class' )['term_id'];
		wp_set_object_terms( $wanted, array( $term_id ), 'product_shipping_class' );

		$results = wc_get_products(
			array(
				'shipping_class' => array( 'hpps-q-ship' ),
				'return'         => 'ids',
				'limit'          => -1,
			)
		);

		$this->assertContains( $wanted, $results );
		$this->assertNotContains( $unwanted, $results );
	}

	/**
	 * @testdox product_category_id (term-id form) is honoured when category (slug form) is empty.
	 */
	public function test_filter_by_product_category_id_native(): void {
		$wanted   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-CATID-IN' ) );
		$unwanted = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-CATID-OUT' ) );

		$term_id = wp_create_term( 'hpps-q-cat-id', 'product_cat' )['term_id'];
		wp_set_object_terms( $wanted, array( $term_id ), 'product_cat' );

		$results = wc_get_products(
			array(
				'product_category_id' => array( $term_id ),
				'return'              => 'ids',
				'limit'               => -1,
			)
		);

		$this->assertContains( $wanted, $results );
		$this->assertNotContains( $unwanted, $results );
	}

	/**
	 * @testdox category + tag together AND, not OR.
	 */
	public function test_combining_category_and_tag_filters_anded(): void {
		$cat_only   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-AND-CAT' ) );
		$tag_only   = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-AND-TAG' ) );
		$cat_and_tag = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-AND-BOTH' ) );

		$cat_term = wp_create_term( 'hpps-q-and-cat', 'product_cat' )['term_id'];
		$tag_term = wp_create_term( 'hpps-q-and-tag', 'product_tag' )['term_id'];

		wp_set_object_terms( $cat_only, array( $cat_term ), 'product_cat' );
		wp_set_object_terms( $tag_only, array( $tag_term ), 'product_tag' );
		wp_set_object_terms( $cat_and_tag, array( $cat_term ), 'product_cat' );
		wp_set_object_terms( $cat_and_tag, array( $tag_term ), 'product_tag' );

		$results = wc_get_products(
			array(
				'category' => array( 'hpps-q-and-cat' ),
				'tag'      => array( 'hpps-q-and-tag' ),
				'return'   => 'ids',
				'limit'    => -1,
			)
		);

		$this->assertSame( array( $cat_and_tag ), $results, 'Both filters must AND together; cat-only / tag-only must drop out.' );
	}

	/**
	 * @testdox A category slug that doesn't exist returns an empty set (no products), not every product.
	 */
	public function test_filter_by_nonexistent_category_returns_empty(): void {
		$id = $this->migrated_simple_product( array( 'sku' => 'HPPS-Q-NOPE' ) );

		$results = wc_get_products(
			array(
				'category' => array( 'hpps-q-this-slug-does-not-exist' ),
				'return'   => 'ids',
				'limit'    => -1,
			)
		);

		$this->assertSame( array(), $results, 'Unmatched taxonomy slugs must short-circuit to no rows.' );
		$this->assertNotContains( $id, $results );
	}

	/*
	|--------------------------------------------------------------------------
	| Test helpers.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a simple product, save it through the data store layer
	 * (which writes directly to wc_products on HPPS-on stores), drain
	 * the migration queue, and return the product ID.
	 *
	 * @param array<string, mixed> $overrides Property overrides (sku, status, stock_status, virtual, etc.).
	 * @return int
	 */
	private function migrated_simple_product( array $overrides = array() ): int {
		$defaults = array(
			'name'          => 'HPPS Query Fixture',
			'regular_price' => 10,
			'price'         => 10,
			'status'        => ProductStatus::PUBLISH,
			'stock_status'  => ProductStockStatus::IN_STOCK,
		);
		$props = array_merge( $defaults, $overrides );

		$product = new WC_Product_Simple();
		$product->set_props( $props );
		$product->save();

		$this->do_hpps_sync();
		$this->assert_product_record_existence( $product->get_id(), true, true );

		return $product->get_id();
	}
}
