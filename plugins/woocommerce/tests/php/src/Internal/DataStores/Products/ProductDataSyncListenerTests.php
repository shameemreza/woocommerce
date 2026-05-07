<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Internal\CostOfGoodsSold\CogsAwareUnitTestSuiteTrait;
use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSyncListener;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Attribute;

/**
 * Tests for {@see ProductDataSyncListener}: the bidirectional bridge
 * between `wp_postmeta` and `wc_products`. Covers the CPT → HPPS
 * direction (postmeta writes mirror into the column store), the
 * HPPS → CPT direction (WC API saves push columns back into postmeta),
 * the reentrancy guard that keeps the two listeners from echoing each
 * other, and the option / migration gates that no-op the listener.
 */
class ProductDataSyncListenerTests extends HppsTestCase {
	use HPPSToggleTrait;
	use CogsAwareUnitTestSuiteTrait;

	/**
	 * @var ProductDataSyncListener
	 */
	private ProductDataSyncListener $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();

		// Resolving the controller forces DI to run init(), which calls
		// ProductDataSyncListener::register_hooks(). Without this the
		// post-meta hooks won't be bound for the test.
		wc_get_container()->get( CustomProductsTableController::class );

		$this->sut = wc_get_container()->get( ProductDataSyncListener::class );

		// Default state for the suite: data sync on. Individual tests flip
		// it off where they need to assert the gate.
		update_option( ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION, 'yes' );
	}

	public function tearDown(): void {
		delete_option( ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION );
		remove_all_filters( 'woocommerce_hpps_data_sync_enabled' );
		remove_all_filters( 'woocommerce_hpps_authoritative_source' );
		$this->disable_cogs_feature();

		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| CPT → HPPS direction.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox update_post_meta on a mapped key mirrors the value into wc_products on a migrated product.
	 */
	public function test_postmeta_update_mirrors_decimal_into_column(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta( $product_id, '_price', '12.34' );

		$this->assertSame( '12.34', $this->read_column( $product_id, 'price' ) );
	}

	/**
	 * @testdox bool meta keys (yes/no) are coerced into 1/0 in the column store.
	 */
	public function test_postmeta_update_coerces_bool_meta_to_int_column(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta( $product_id, '_manage_stock', 'yes' );
		$this->assertSame( '1', $this->read_column( $product_id, 'manage_stock' ) );

		update_post_meta( $product_id, '_manage_stock', 'no' );
		$this->assertSame( '0', $this->read_column( $product_id, 'manage_stock' ) );
	}

	/**
	 * @testdox int meta keys are stored as integers (or NULL when blank).
	 */
	public function test_postmeta_update_coerces_int_meta_and_blank_to_null(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta( $product_id, '_low_stock_amount', '4' );
		$this->assertSame( '4', $this->read_column( $product_id, 'low_stock_amount' ) );

		update_post_meta( $product_id, '_low_stock_amount', '' );
		$this->assertNull( $this->read_column( $product_id, 'low_stock_amount' ) );
	}

	/**
	 * @testdox date meta keys (Unix timestamps) coerce to Y-m-d H:i:s in the column store.
	 */
	public function test_postmeta_update_coerces_date_meta_from_unix_timestamp(): void {
		$product_id = $this->create_migrated_simple_product();

		// 2026-01-15 10:00:00 UTC.
		$timestamp = 1768514400;
		update_post_meta( $product_id, '_sale_price_dates_from', (string) $timestamp );

		$column = $this->read_column( $product_id, 'date_on_sale_from' );
		$this->assertNotNull( $column );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), $column );
	}

	/**
	 * @testdox delete_post_meta resets the column to its empty form.
	 */
	public function test_postmeta_delete_resets_column(): void {
		$product_id = $this->create_migrated_simple_product();
		update_post_meta( $product_id, '_sku', 'TEST-DEL-001' );
		$this->assertSame( 'TEST-DEL-001', $this->read_column( $product_id, 'sku' ) );

		delete_post_meta( $product_id, '_sku' );
		$this->assertSame( '', $this->read_column( $product_id, 'sku' ) );
	}

	/**
	 * @testdox unmapped meta keys do not touch wc_products at all.
	 */
	public function test_postmeta_update_for_unmapped_key_is_a_noop(): void {
		$product_id = $this->create_migrated_simple_product();
		$before     = $this->read_column( $product_id, 'price' );

		update_post_meta( $product_id, '_some_random_third_party_meta', 'xyz' );

		$this->assertSame( $before, $this->read_column( $product_id, 'price' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Composite-shape columns (gallery, COGS).
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox _product_image_gallery postmeta mirrors into the gallery_image_ids column verbatim.
	 */
	public function test_postmeta_update_mirrors_gallery_image_ids(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta( $product_id, '_product_image_gallery', '11,22,33' );

		$this->assertSame( '11,22,33', $this->read_column( $product_id, 'gallery_image_ids' ) );
	}

	/**
	 * @testdox _cogs_total_value postmeta mirrors into the cogs_value column when the COGS feature is enabled.
	 */
	public function test_postmeta_update_mirrors_cogs_value_when_feature_on(): void {
		$this->enable_cogs_feature();

		$product_id = $this->create_migrated_simple_product();

		update_post_meta( $product_id, '_cogs_total_value', '7.50' );

		$column = $this->read_column( $product_id, 'cogs_value' );
		$this->assertNotNull( $column );
		// MySQL persists decimal(19,4) as e.g. "7.5000"; cast collapses
		// the precision tail so we can assert exact equality.
		$this->assertSame( 7.5, (float) $column );
	}

	/**
	 * @testdox _cogs_total_value postmeta is ignored when the COGS feature is disabled (column stays NULL).
	 */
	public function test_postmeta_update_skips_cogs_value_when_feature_off(): void {
		// COGS off (default; suite tearDown also disables it).
		$product_id = $this->create_migrated_simple_product();
		$this->assertNull( $this->read_column( $product_id, 'cogs_value' ), 'precondition: cogs_value starts NULL.' );

		update_post_meta( $product_id, '_cogs_total_value', '99.99' );

		$this->assertNull( $this->read_column( $product_id, 'cogs_value' ), 'COGS gate must keep the column NULL when the feature is off.' );
	}

	/**
	 * @testdox saving through WC_Product::save() pushes gallery_image_ids back into _product_image_gallery.
	 */
	public function test_save_through_wc_api_mirrors_gallery_back_into_postmeta(): void {
		$product_id = $this->create_migrated_simple_product();

		$product = wc_get_product( $product_id );
		$product->set_gallery_image_ids( array( 101, 202, 303 ) );
		$product->save();

		$this->assertSame( '101,202,303', get_post_meta( $product_id, '_product_image_gallery', true ) );
	}

	/**
	 * @testdox COGS save mirrors cogs_value back into _cogs_total_value when the feature is enabled.
	 */
	public function test_save_through_wc_api_mirrors_cogs_back_into_postmeta_when_feature_on(): void {
		$this->enable_cogs_feature();

		$product_id = $this->create_migrated_simple_product();

		$product = wc_get_product( $product_id );
		$product->set_cogs_value( 4.25 );
		$product->save();

		$this->assertSame( 4.25, (float) get_post_meta( $product_id, '_cogs_total_value', true ) );
	}

	/**
	 * @testdox writeback skips _cogs_total_value when the COGS feature is disabled, so non-COGS stores stay clean.
	 */
	public function test_save_through_wc_api_skips_cogs_writeback_when_feature_off(): void {
		// Postmeta starts unset.
		$product_id = $this->create_migrated_simple_product();
		delete_post_meta( $product_id, '_cogs_total_value' );

		// Force a non-NULL column value so we can prove the writeback
		// would have a value to mirror back if the gate were open.
		global $wpdb;
		$table = ProductsTableDataStore::get_products_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'cogs_value' => '12.0000' ), array( 'id' => $product_id ), array( '%s' ), array( '%d' ) );

		// Trigger the writeback path with COGS still off.
		$product = wc_get_product( $product_id );
		$product->set_regular_price( 50.00 );
		$product->set_price( 50.00 );
		$product->save();

		// Sanity: writeback ran (non-gated keys still mirror).
		$this->assertSame( 50.0, (float) get_post_meta( $product_id, '_price', true ) );

		// COGS-gated key did NOT land.
		$this->assertSame( '', get_post_meta( $product_id, '_cogs_total_value', true ), 'COGS gate must skip writeback when the feature is off.' );
	}

	/*
	|--------------------------------------------------------------------------
	| Attributes: side-table sync.
	|--------------------------------------------------------------------------
	|
	| Two cooperating directions:
	|   - update_post_meta on _product_attributes / _default_attributes
	|     projects into wc_product_attributes / wc_product_attribute_values.
	|   - WC_Product::save() with attribute changes regenerates both
	|     postmeta blobs from the side tables.
	*/

	/**
	 * @testdox _product_attributes postmeta with a custom attribute populates the side tables.
	 */
	public function test_postmeta_attributes_custom_mirror_to_side_tables(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta(
			$product_id,
			'_product_attributes',
			array(
				'material' => array(
					'name'         => 'Material',
					'value'        => 'cotton | silk | wool',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				),
			)
		);

		$attribute_rows = $this->read_attribute_rows( $product_id );
		$this->assertCount( 1, $attribute_rows, 'One row in wc_product_attributes per declared attribute.' );
		$this->assertSame( 'Material', $attribute_rows[0]['name'] );
		$this->assertSame( '', $attribute_rows[0]['taxonomy'], 'Custom attribute must store an empty taxonomy.' );
		$this->assertSame( 1, (int) $attribute_rows[0]['is_visible'] );

		$value_rows = $this->read_attribute_value_rows( $product_id, (int) $attribute_rows[0]['id'] );
		$values     = array_map( static fn( array $row ): string => $row['value'], $value_rows );
		$this->assertSame( array( 'cotton', 'silk', 'wool' ), $values, 'wc_get_text_attributes() must split the | delimited value list verbatim.' );
		foreach ( $value_rows as $row ) {
			$this->assertSame( 0, (int) $row['is_default'] );
		}
	}

	/**
	 * @testdox _default_attributes postmeta lands as is_default=1 rows in the values table.
	 */
	public function test_postmeta_default_attributes_mirror_to_is_default_rows(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta(
			$product_id,
			'_product_attributes',
			array(
				'material' => array(
					'name'         => 'Material',
					'value'        => 'cotton | silk',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 1,
					'is_taxonomy'  => 0,
				),
			)
		);

		update_post_meta(
			$product_id,
			'_default_attributes',
			array( 'material' => 'silk' )
		);

		$attribute_rows = $this->read_attribute_rows( $product_id );
		$this->assertCount( 1, $attribute_rows );

		$value_rows = $this->read_attribute_value_rows( $product_id, (int) $attribute_rows[0]['id'] );
		$defaults   = array_filter( $value_rows, static fn( array $row ): bool => 1 === (int) $row['is_default'] );
		$this->assertCount( 1, $defaults, 'Exactly one is_default row for material=silk.' );
		$default = array_values( $defaults )[0];
		$this->assertSame( 'silk', $default['value'] );
	}

	/**
	 * @testdox swapping the postmeta to a different shape rebuilds the side tables (no orphans).
	 */
	public function test_postmeta_attributes_replacement_drops_old_rows(): void {
		$product_id = $this->create_migrated_simple_product();

		update_post_meta(
			$product_id,
			'_product_attributes',
			array(
				'material' => array(
					'name'         => 'Material',
					'value'        => 'cotton',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				),
			)
		);
		$first_rows = $this->read_attribute_rows( $product_id );
		$this->assertCount( 1, $first_rows );

		update_post_meta(
			$product_id,
			'_product_attributes',
			array(
				'fabric' => array(
					'name'         => 'Fabric',
					'value'        => 'denim | linen',
					'position'     => 0,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				),
			)
		);

		$second_rows = $this->read_attribute_rows( $product_id );
		$this->assertCount( 1, $second_rows, 'Old "Material" row must be deleted, only "Fabric" remains.' );
		$this->assertSame( 'Fabric', $second_rows[0]['name'] );

		$value_rows = $this->read_attribute_value_rows( $product_id, (int) $second_rows[0]['id'] );
		$values     = array_map( static fn( array $row ): string => $row['value'], $value_rows );
		$this->assertSame( array( 'denim', 'linen' ), $values );
	}

	/**
	 * @testdox C4 — postmeta-driven attribute rebuild rebinds variation rows to the new parent attribute_id (no orphans, no zeros).
	 *
	 * Round-1 audit gap: `test_postmeta_attributes_replacement_drops_old_rows`
	 * only covered a simple product, so a regression that left variation
	 * rows pointing at the deleted parent id (or at 0) would slip through.
	 * This test creates a real variable + variation fixture, captures the
	 * parent's pre-rebuild attribute_id and the variation's reference to
	 * it, forces a postmeta-driven rebuild, and asserts that the new
	 * parent id is non-zero, differs from the pre-rebuild id, and matches
	 * the variation's `attribute_id` after the rebuild.
	 */
	public function test_attributes_rebuild_remaps_variation_attribute_ids_to_new_parent_row(): void {
		global $wpdb;

		$variable     = WC_Helper_Product::create_variation_product();
		$variable_id  = (int) $variable->get_id();
		$variation_id = (int) $variable->get_children()[0];
		$this->do_hpps_sync();

		$attributes_table       = ProductsTableDataStore::get_attributes_table_name();
		$attribute_values_table = ProductsTableDataStore::get_attribute_values_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old_parent_size_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$attributes_table} WHERE product_id = %d AND name = %s LIMIT 1",
				$variable_id,
				'pa_size'
			)
		);
		$old_variation_size_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$attribute_values_table} WHERE product_id = %d AND scope = %s AND value = %s LIMIT 1",
				$variation_id,
				'variation',
				'small'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertGreaterThan( 0, $old_parent_size_id, 'Parent should have a pa_size attribute row.' );
		$this->assertGreaterThan( 0, $old_variation_size_id, 'Variation should reference a parent attribute_id.' );
		$this->assertSame( $old_parent_size_id, $old_variation_size_id, 'Variation must point at the parent attribute row before the rebuild.' );

		// Force a rebuild by re-saving `_product_attributes`. We bump every
		// position so the listener detects a change and runs the
		// delete-and-reinsert path — which is exactly the C4 hot zone.
		$existing = (array) get_post_meta( $variable_id, '_product_attributes', true );
		$this->assertNotEmpty( $existing );
		foreach ( $existing as &$entry ) {
			$entry['position'] = (int) ( $entry['position'] ?? 0 ) + 10;
		}
		unset( $entry );
		update_post_meta( $variable_id, '_product_attributes', $existing );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_parent_size_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$attributes_table} WHERE product_id = %d AND name = %s LIMIT 1",
				$variable_id,
				'pa_size'
			)
		);
		$new_variation_size_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$attribute_values_table} WHERE product_id = %d AND scope = %s AND value = %s LIMIT 1",
				$variation_id,
				'variation',
				'small'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertGreaterThan( 0, $new_parent_size_id, 'Parent should still have a pa_size row after the rebuild.' );
		$this->assertNotSame( $old_parent_size_id, $new_parent_size_id, 'Parent attribute row must be re-created (new id) so the test catches a regression where the rebind path is skipped.' );
		$this->assertGreaterThan( 0, $new_variation_size_id, 'Variation must keep a non-zero attribute_id (no orphan).' );
		$this->assertSame(
			$new_parent_size_id,
			$new_variation_size_id,
			'C4: variation attribute_id must be rebound to the new parent row id, not left pointing at the deleted id or reset to 0.'
		);
	}

	/**
	 * @testdox saving a custom attribute through WC_Product::save() writes _product_attributes postmeta with the legacy shape.
	 */
	public function test_save_with_custom_attribute_writes_postmeta(): void {
		$product_id = $this->create_migrated_simple_product();

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'cotton', 'silk' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$product = wc_get_product( $product_id );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$postmeta = get_post_meta( $product_id, '_product_attributes', true );
		$this->assertIsArray( $postmeta );
		$this->assertArrayHasKey( 'material', $postmeta, 'sanitize_title( "Material" ) keys the entry.' );

		$entry = $postmeta['material'];
		$this->assertSame( 'Material', $entry['name'] );
		$this->assertSame( 'cotton | silk', $entry['value'], 'Custom attribute values must use the WC_DELIMITER form (with surrounding spaces).' );
		$this->assertSame( 0, (int) $entry['is_taxonomy'] );
		$this->assertSame( 1, (int) $entry['is_visible'] );
		$this->assertSame( 0, (int) $entry['is_variation'] );
	}

	/**
	 * @testdox H5 — saving a custom (non-taxonomy) attribute persists every value row with term_id IS NULL, not 0.
	 *
	 * Round-1 audit gap: `test_persist_attributes_stores_term_id_for_taxonomy`
	 * only asserts the taxonomy direction (term_id resolves to a real
	 * term). The H5 fix specifically protects the *custom* direction —
	 * `$wpdb->insert()` with `term_id => null` and a `%d` format silently
	 * coerces NULL into 0, breaking every `term_id IS NOT NULL` predicate
	 * downstream. We need a regression test that fails if the helper goes
	 * back to the all-columns INSERT path.
	 */
	public function test_persist_attributes_stores_null_term_id_for_custom_attribute(): void {
		global $wpdb;

		$product_id = $this->create_migrated_simple_product();

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'cotton', 'silk' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$product = wc_get_product( $product_id );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$values_table = ProductsTableDataStore::get_attribute_values_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT value, term_id FROM {$values_table} WHERE product_id = %d AND scope = 'product' ORDER BY position ASC, id ASC",
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertCount( 2, $rows, 'Both option values should land in wc_product_attribute_values.' );
		foreach ( $rows as $row ) {
			$this->assertNull(
				$row->term_id,
				sprintf(
					'Custom attribute value "%s" must store term_id as NULL, not 0. A 0 here means insert_attribute_value_row() regressed back to the unconditional INSERT and `term_id IS NOT NULL` queries will now misclassify the row as a taxonomy reference.',
					(string) $row->value
				)
			);
		}

		// Belt-and-braces SQL assertion in case PHP's loose typing makes a
		// 0-valued term_id look like NULL on the way out of $wpdb.
		$count_zero = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$values_table} WHERE product_id = %d AND scope = 'product' AND term_id = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id
			)
		);
		$this->assertSame( 0, $count_zero, 'No custom-attribute rows should be stored with term_id = 0.' );
	}

	/**
	 * @testdox saving a taxonomy attribute writes is_taxonomy=1 with an empty value field.
	 */
	public function test_save_with_taxonomy_attribute_writes_postmeta(): void {
		$product_id = $this->create_migrated_simple_product();

		// Sets up a `pa_*` taxonomy + terms and returns a configured attribute.
		$attribute = WC_Helper_Product::create_product_attribute_object( 'hpps_listener_color', array( 'red', 'blue' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$product = wc_get_product( $product_id );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$postmeta = get_post_meta( $product_id, '_product_attributes', true );
		$this->assertIsArray( $postmeta );

		$tax_name = $attribute->get_name();
		$this->assertArrayHasKey( $tax_name, $postmeta, 'Taxonomy attributes key on the taxonomy slug, not sanitize_title().' );

		$entry = $postmeta[ $tax_name ];
		$this->assertSame( 1, (int) $entry['is_taxonomy'] );
		$this->assertSame( '', $entry['value'], 'Taxonomy attributes leave value empty; the option list lives in wp_term_relationships.' );
	}

	/**
	 * @testdox saving default attributes writes _default_attributes postmeta from the is_default rows.
	 */
	public function test_save_with_default_attributes_writes_postmeta(): void {
		$product_id = $this->create_migrated_simple_product();

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'M', 'L' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = wc_get_product( $product_id );
		$product->set_attributes( array( $attribute ) );
		$product->set_default_attributes( array( 'size' => 'M' ) );
		$product->save();

		$defaults = get_post_meta( $product_id, '_default_attributes', true );
		$this->assertIsArray( $defaults );
		$this->assertSame( array( 'size' => 'M' ), $defaults );
	}

	/**
	 * @testdox an HPPS save with attribute changes does not recurse through the postmeta listener.
	 */
	public function test_attribute_writeback_does_not_recurse(): void {
		$product_id = $this->create_migrated_simple_product();

		$call_count = 0;
		$counter    = function () use ( &$call_count ): void {
			++$call_count;
		};
		add_action( 'updated_post_meta', $counter, 100 );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Recurse' );
		$attribute->set_options( array( 'a', 'b' ) );
		$attribute->set_visible( true );

		$product = wc_get_product( $product_id );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		remove_action( 'updated_post_meta', $counter, 100 );

		// Bound looser than the price-only writeback test because attribute
		// saves touch more meta keys; the regression to guard against is
		// runaway recursion (thousands of calls), not a tight upper bound.
		$this->assertLessThan( 500, $call_count, 'updated_post_meta should not have fired in a runaway loop — attribute reentrancy guard probably regressed.' );
		$this->assertNotEmpty( get_post_meta( $product_id, '_product_attributes', true ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Gating: option, filter, migration state.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox listener no-ops when the data-sync option is off.
	 */
	public function test_listener_no_ops_when_data_sync_option_is_off(): void {
		$product_id = $this->create_migrated_simple_product();
		$before     = $this->read_column( $product_id, 'price' );

		update_option( ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION, 'no' );

		update_post_meta( $product_id, '_price', '99.99' );

		$this->assertSame( $before, $this->read_column( $product_id, 'price' ), 'wc_products row should not have changed when sync is off.' );
	}

	/**
	 * @testdox the woocommerce_hpps_data_sync_enabled filter overrides the option.
	 */
	public function test_listener_filter_can_force_sync_on_with_option_off(): void {
		$product_id = $this->create_migrated_simple_product();

		update_option( ProductDataSynchronizer::DATA_SYNC_ENABLED_OPTION, 'no' );
		add_filter( 'woocommerce_hpps_data_sync_enabled', '__return_true' );

		update_post_meta( $product_id, '_price', '7.77' );

		$this->assertSame( '7.77', $this->read_column( $product_id, 'price' ) );
	}

	/**
	 * @testdox listener bails for products that haven't been migrated to HPPS yet.
	 */
	public function test_listener_skips_unmigrated_products(): void {
		// Disable HPPS while we create the fixture so the row only lands
		// on the CPT side. This is the realistic pre-migration shape.
		$this->toggle_hpps_feature_and_usage( false );
		$cpt_product = WC_Helper_Product::create_simple_product();
		$id          = $cpt_product->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		// HPPS row is still absent.
		$this->assert_product_record_existence( $id, true, false );

		// The listener should not panic, error, or insert a stray row.
		update_post_meta( $id, '_price', '50.00' );

		$this->assert_product_record_existence( $id, true, false );
	}

	/*
	|--------------------------------------------------------------------------
	| HPPS → CPT direction (writeback after a WC API save).
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox saving through WC_Product::save() pushes mapped column values back into postmeta.
	 */
	public function test_save_through_wc_api_mirrors_columns_back_into_postmeta(): void {
		$product_id = $this->create_migrated_simple_product();

		$product = wc_get_product( $product_id );
		$product->set_regular_price( 24.50 );
		$product->set_price( 24.50 );
		$product->set_sku( 'WRITEBACK-001' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 11 );
		$product->save();

		$this->assertSame( '24.5', get_post_meta( $product_id, '_price', true ) );
		$this->assertSame( '24.5', get_post_meta( $product_id, '_regular_price', true ) );
		$this->assertSame( 'WRITEBACK-001', get_post_meta( $product_id, '_sku', true ) );
		$this->assertSame( 'yes', get_post_meta( $product_id, '_manage_stock', true ) );
		$this->assertSame( '11', get_post_meta( $product_id, '_stock', true ) );
	}

	/**
	 * @testdox the writeback exits cleanly without recursing through the postmeta listener.
	 */
	public function test_writeback_does_not_recurse_through_postmeta_listener(): void {
		$product_id = $this->create_migrated_simple_product();

		// If the reentrancy guard regressed, this save would either time
		// out (infinite recursion) or balloon the meta hook count. Track
		// the count and fail loudly on either signal.
		$call_count = 0;
		$counter    = function () use ( &$call_count ): void {
			++$call_count;
		};
		add_action( 'updated_post_meta', $counter, 100 );

		$product = wc_get_product( $product_id );
		$product->set_regular_price( 17.25 );
		$product->set_price( 17.25 );
		$product->save();

		remove_action( 'updated_post_meta', $counter, 100 );

		$this->assertSame( '17.25', get_post_meta( $product_id, '_price', true ) );

		// The exact count is implementation-detail-dependent (the WC core
		// CPT data store also writes some meta), so we just guard against
		// the obvious "recursion blew up" shape.
		$this->assertLessThan( 200, $call_count, 'updated_post_meta should not have fired hundreds of times — the reentrancy guard probably regressed.' );
	}

	/**
	 * @testdox writeback skips products that aren't in HPPS so the legacy CPT data store doesn't get double-written.
	 */
	public function test_writeback_skips_products_not_in_hpps(): void {
		// Pre-migration: product lives in CPT only.
		$this->toggle_hpps_feature_and_usage( false );
		$cpt_product = WC_Helper_Product::create_simple_product();
		$id          = $cpt_product->get_id();
		$this->toggle_hpps_feature_and_usage( true );

		// Confirm the precondition: not in wc_products yet.
		$this->assert_product_record_existence( $id, true, false );

		// Saving routes back through the legacy CPT data store. The HPPS
		// writeback should bail instead of querying the missing row.
		$product = wc_get_product( $id );
		$product->set_regular_price( 33.33 );
		$product->set_price( 33.33 );
		$product->save();

		// CPT side updated normally.
		$this->assertSame( '33.33', get_post_meta( $id, '_regular_price', true ) );
		// HPPS side still empty (no stray insert).
		$this->assert_product_record_existence( $id, true, false );
	}

	/*
	|--------------------------------------------------------------------------
	| Reentrancy guard: public start/end_internal_write API.
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox start_internal_write / end_internal_write bracket correctly so the listener no-ops mid-region.
	 */
	public function test_internal_write_bracket_suppresses_listener(): void {
		$product_id = $this->create_migrated_simple_product();
		$before     = $this->read_column( $product_id, 'price' );

		ProductDataSyncListener::start_internal_write();
		try {
			update_post_meta( $product_id, '_price', '88.88' );
		} finally {
			ProductDataSyncListener::end_internal_write();
		}

		// Postmeta wrote, but the listener was suppressed so the column
		// is unchanged.
		$this->assertSame( '88.88', get_post_meta( $product_id, '_price', true ) );
		$this->assertSame( $before, $this->read_column( $product_id, 'price' ) );

		// And the depth counter cleared, so a subsequent write outside the
		// region is mirrored normally.
		update_post_meta( $product_id, '_price', '12.34' );
		$this->assertSame( '12.34', $this->read_column( $product_id, 'price' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Test helpers.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Spin up a simple product, drain the migration queue, and return the
	 * resulting product ID. The product is guaranteed to exist in both
	 * `wp_posts` and `wc_products` after this call.
	 *
	 * @return int
	 */
	private function create_migrated_simple_product(): int {
		$product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Listener Fixture',
				'regular_price' => 10,
				'price'         => 10,
				'sku'           => 'HPPS-LISTENER-' . wp_generate_password( 6, false ),
				'stock_status'  => ProductStockStatus::IN_STOCK,
			)
		);

		$this->do_hpps_sync();
		$this->assert_product_record_existence( $product->get_id(), true, true );

		return $product->get_id();
	}

	/**
	 * Read a single raw column value from `wc_products`, bypassing the
	 * data store to make sure we're asserting against persisted state
	 * rather than an in-memory product cache.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $column     Column name.
	 * @return string|null
	 */
	private function read_column( int $product_id, string $column ): ?string {
		global $wpdb;
		$table = ProductsTableDataStore::get_products_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `{$column}` FROM {$table} WHERE id = %d",
				$product_id
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Read every wc_product_attributes row for a product, ordered by
	 * position, as plain associative arrays.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, array<string, scalar|null>>
	 */
	private function read_attribute_rows( int $product_id ): array {
		global $wpdb;
		$table = ProductsTableDataStore::get_attributes_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, taxonomy, position, is_visible, is_for_variation FROM {$table} WHERE product_id = %d ORDER BY position ASC, id ASC",
				$product_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Read wc_product_attribute_values rows for a given attribute row,
	 * ordered by position.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $attribute_id `wc_product_attributes.id`.
	 * @return array<int, array<string, scalar|null>>
	 */
	private function read_attribute_value_rows( int $product_id, int $attribute_id ): array {
		global $wpdb;
		$table = ProductsTableDataStore::get_attribute_values_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT value, term_id, is_default, position FROM {$table} WHERE product_id = %d AND attribute_id = %d AND scope = 'product' ORDER BY position ASC, id ASC",
				$product_id,
				$attribute_id
			),
			ARRAY_A
		) ?: array();
	}
}
