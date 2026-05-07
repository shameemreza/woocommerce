<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\HPPSToggleTrait;
use HppsTestCase;
use WC_Helper_Product;
use WC_Product_Simple;
use WP_Query;

/**
 * Controller-level tests for {@see CustomProductsTableController} and the
 * cross-cutting data-store integration paths it owns:
 *
 *  - the `pre_get_posts` shim that expands `post_type=product` to also
 *    include `product_placeholder` (closes the round-3 INT-1/3/4/5/6/7
 *    cluster in one place).
 *  - the {@see ProductsTableDataStore::get_related_products()} override
 *    that widens the related-products SQL to both post types (round-3
 *    INT-2).
 *  - HPPS schema-version bookkeeping that drives dbDelta on column drift
 *    (round-3 EDGE-6).
 *  - N3 capability gate on the sync handler (cap check fires before nonce).
 *  - N4 Tools page entries render with backticks, not stray HTML.
 *
 * The intent of every test in here is to fail loudly if the corresponding
 * fix is reverted: each one targets a specific regression vector the
 * round-1 / round-2 / round-3 audits flagged.
 */
class CustomProductsTableControllerTests extends HppsTestCase {
	use HPPSToggleTrait;

	public function setUp(): void {
		parent::setUp();
		$this->setup_hpps();
	}

	public function tearDown(): void {
		$this->clean_up_hpps_setup();
		parent::tearDown();
	}

	/**
	 * @testdox INT cluster — WP_Query( post_type=product ) returns HPPS-native (placeholder) products too.
	 *
	 * Exercises the round-3 fix that expanded the `post_type` query var
	 * via `pre_get_posts`. Without the shim, an HPPS-native product
	 * (whose `wp_posts.post_type` is `product_placeholder`) is invisible
	 * to every WP_Query-based surface — REST v3, Store API,
	 * ProductCollection block, `[products]` shortcode family, classic
	 * widgets. The expectation is that one query returns both kinds.
	 */
	public function test_wp_query_returns_both_product_and_placeholder_post_types(): void {
		$cpt_product = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name' => 'INT Migrated Product',
				'sku'  => 'HPPS-INT-MIGRATED',
			)
		);
		$this->do_hpps_sync();

		$hpps_only = new WC_Product_Simple();
		$hpps_only->set_props(
			array(
				'name'         => 'INT HPPS-Only Product',
				'sku'          => 'HPPS-INT-HPPSONLY',
				'stock_status' => ProductStockStatus::IN_STOCK,
			)
		);
		$hpps_only->save();
		$this->assertSame(
			CustomProductsTableController::PLACEHOLDER_POST_TYPE,
			get_post_type( $hpps_only->get_id() ),
			'HPPS-native products must land in wp_posts with post_type=product_placeholder.'
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		$this->assertContains(
			$cpt_product->get_id(),
			$ids,
			'Migrated product (post_type=product) must remain visible.'
		);
		$this->assertContains(
			$hpps_only->get_id(),
			$ids,
			'HPPS-native product (post_type=product_placeholder) must appear too — pre_get_posts shim regression.'
		);
	}

	/**
	 * @testdox INT shim — opt-out filter `woocommerce_hpps_include_placeholder_in_product_queries` keeps the legacy single-post-type behaviour.
	 *
	 * Round-3 introduced the shim with a filter so an extension can pin a
	 * specific query to `post_type=product` only. Asserting the filter
	 * is honoured stops a later refactor from quietly making it
	 * unconditional.
	 */
	public function test_shim_can_be_opted_out_via_filter(): void {
		$hpps_only = new WC_Product_Simple();
		$hpps_only->set_props(
			array(
				'name' => 'INT Opt-Out Product',
				'sku'  => 'HPPS-INT-OPTOUT',
			)
		);
		$hpps_only->save();

		add_filter( 'woocommerce_hpps_include_placeholder_in_product_queries', '__return_false' );
		try {
			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$ids   = array_map( 'intval', (array) $query->posts );
			$this->assertNotContains(
				$hpps_only->get_id(),
				$ids,
				'The opt-out filter must keep HPPS-native products out of the result set.'
			);
		} finally {
			remove_filter( 'woocommerce_hpps_include_placeholder_in_product_queries', '__return_false' );
		}
	}

	/**
	 * @testdox INT-2 — get_related_products() returns HPPS-native products that share a category.
	 *
	 * The legacy CPT data store hardcoded `AND p.post_type = 'product'`
	 * in `get_related_products_query()`, so HPPS-native products were
	 * silently absent from the related rail. The HPPS override widens
	 * that filter to `IN ( 'product', 'product_placeholder' )`. Without
	 * a regression test, dropping the override would still leave the
	 * data-store CRUD tests green.
	 */
	public function test_related_products_includes_hpps_native_product_in_same_category(): void {
		$category = wp_insert_term( 'INT-Related', 'product_cat' );
		$this->assertIsArray( $category );
		$category_id = (int) $category['term_id'];

		// Migrated product, in the category.
		$source = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name' => 'INT Related Source',
				'sku'  => 'HPPS-REL-SRC',
			)
		);
		wp_set_object_terms( $source->get_id(), array( $category_id ), 'product_cat' );
		$this->do_hpps_sync();

		// HPPS-native product, in the same category.
		$placeholder_product = new WC_Product_Simple();
		$placeholder_product->set_props(
			array(
				'name'         => 'INT Related Placeholder',
				'sku'          => 'HPPS-REL-PLC',
				'stock_status' => ProductStockStatus::IN_STOCK,
			)
		);
		$placeholder_product->save();
		wp_set_object_terms( $placeholder_product->get_id(), array( $category_id ), 'product_cat' );

		// And one extra migrated sibling so we know the rail isn't trivially empty.
		$sibling = WC_Helper_Product::create_simple_product(
			true,
			array(
				'name' => 'INT Related Sibling',
				'sku'  => 'HPPS-REL-SIB',
			)
		);
		wp_set_object_terms( $sibling->get_id(), array( $category_id ), 'product_cat' );
		$this->do_hpps_sync();

		/** @var ProductsTableDataStore $store */
		$store   = wc_get_container()->get( ProductsTableDataStore::class );
		$related = $store->get_related_products(
			array( $category_id ),
			array(),
			array( $source->get_id() ),
			10,
			$source->get_id()
		);

		$related_ids = array_map( 'intval', (array) $related );

		$this->assertContains(
			$placeholder_product->get_id(),
			$related_ids,
			'INT-2 regression: HPPS-native products must surface as related when they share a category.'
		);
		$this->assertContains(
			$sibling->get_id(),
			$related_ids,
			'Migrated sibling must remain in the related rail.'
		);
	}

	/**
	 * @testdox EDGE-6 — schema-version option drives dbDelta on column drift.
	 *
	 * Round-3 added a {@see ProductDataSynchronizer::SCHEMA_VERSION}
	 * constant and a paired option so a future column add will trigger
	 * dbDelta even when the tables already exist. We check both the
	 * happy path (option present and matching) and the drift path
	 * (option missing → must be treated as out-of-date).
	 */
	public function test_schema_version_check_reports_current_after_create(): void {
		$sync = wc_get_container()->get( ProductDataSynchronizer::class );

		// `setup_hpps()` already ran `create_database_tables()`, so the
		// option must exist at the current version.
		$this->assertSame(
			(string) ProductDataSynchronizer::SCHEMA_VERSION,
			(string) get_option( ProductDataSynchronizer::PRODUCTS_TABLE_SCHEMA_VERSION_OPTION ),
			'create_database_tables() must persist the current SCHEMA_VERSION.'
		);
		$this->assertTrue(
			$sync->check_schema_is_current(),
			'check_schema_is_current() must return true when the option matches the constant.'
		);

		// Simulate column drift: option below constant.
		delete_option( ProductDataSynchronizer::PRODUCTS_TABLE_SCHEMA_VERSION_OPTION );
		$this->assertFalse(
			$sync->check_schema_is_current(),
			'check_schema_is_current() must return false when the option is missing — that is the trigger for re-running dbDelta on existing installs.'
		);
	}

	/**
	 * @testdox EDGE-1/2 — create() rolls back the placeholder post when the wc_products INSERT fails.
	 *
	 * We force `wpdb->insert()` to fail by hooking `query` and
	 * short-circuiting the wc_products INSERT. The placeholder row is
	 * already inserted by `wp_insert_post()` at that point, so without
	 * the new transaction wrap + cleanup we'd leak an orphan. The test
	 * proves the rollback path:
	 *
	 *   - the create() throws,
	 *   - the placeholder post is gone,
	 *   - the product object's id is reset to 0.
	 */
	public function test_create_rolls_back_placeholder_post_when_wc_products_insert_fails(): void {
		global $wpdb;

		$products_table = ProductsTableDataStore::get_products_table_name();

		$failing_query_filter = function ( $query ) use ( $products_table ) {
			if ( false !== stripos( $query, "INSERT INTO `{$products_table}`" ) ) {
				// Redirect the wc_products INSERT to a non-existent table
				// so MySQL errors out → `wpdb->query()` returns `false` →
				// `wpdb->insert()` returns `false` → `create()` throws and
				// hits the rollback branch we're testing.
				return 'INSERT INTO _hpps_nonexistent_table_for_test_ (id) VALUES (1)';
			}
			return $query;
		};

		// Suppress the loud `WPDB error` warnings so the deliberate failure
		// doesn't pollute the test output.
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();

		add_filter( 'query', $failing_query_filter );

		$threw   = false;
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name' => 'EDGE-1 Rollback Product',
				'sku'  => 'HPPS-EDGE1-ROLLBACK',
			)
		);

		try {
			$store = wc_get_container()->get( ProductsTableDataStore::class );
			$store->create( $product );
		} catch ( \Throwable $e ) {
			$threw = true;
		} finally {
			remove_filter( 'query', $failing_query_filter );
			$wpdb->suppress_errors( false );
			$wpdb->show_errors();
		}

		$this->assertTrue(
			$threw,
			'create() must throw when the wc_products INSERT fails.'
		);
		$this->assertSame(
			0,
			(int) $product->get_id(),
			'create() must reset the product id after rolling back so a retry starts clean.'
		);

		// The placeholder must be gone — that is the heart of the
		// EDGE-2 fix.
		$placeholder_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s",
				'EDGE-1 Rollback Product',
				CustomProductsTableController::PLACEHOLDER_POST_TYPE
			)
		);
		$this->assertSame(
			0,
			$placeholder_count,
			'EDGE-2 regression: a failed create() must not leave an orphan product_placeholder post in wp_posts.'
		);
	}

	/**
	 * @testdox N3 — handle_sync_now_action rejects callers without manage_woocommerce.
	 *
	 * The handler hangs off `woocommerce_sections_advanced`, which fires
	 * for every Settings tab. Without the explicit cap check, a user with
	 * a leaked or shared nonce could start or stop the sync. The fix
	 * runs `current_user_can( 'manage_woocommerce' )` before the nonce
	 * verification. This test stands a non-manager up, fires the handler,
	 * and asserts it adds the cap-rejection error.
	 */
	public function test_sync_now_handler_rejects_user_without_manage_woocommerce(): void {
		// Subscriber: definitely no manage_woocommerce.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- we are simulating a request shape.
		$_GET['section']           = 'features';
		$_GET['wc_hpps_sync_now']  = '1';
		$_GET['_wpnonce']          = wp_create_nonce( 'hpps-sync-now' );
		// phpcs:enable

		// Capture the WC_Admin_Settings error stack so we can assert on it.
		$controller = wc_get_container()->get( CustomProductsTableController::class );
		$controller->handle_sync_now_action();

		$errors        = \WC_Admin_Settings::get_errors();
		$last_error    = (string) ( $errors[ array_key_last( $errors ) ] ?? '' );
		$this->assertStringContainsString(
			'permission to manage HPPS sync',
			$last_error,
			'N3 regression: the sync handler must reject non-managers with an error before checking the nonce.'
		);

		// The migration option must NOT have been touched.
		$this->assertNotSame(
			'pending',
			get_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION ),
			'A rejected sync attempt must not flip the migration status to pending.'
		);

		unset( $_GET['section'], $_GET['wc_hpps_sync_now'], $_GET['_wpnonce'] );
	}

	/**
	 * @testdox N4 — Tools page entries use backticks, not raw <code> markup.
	 *
	 * `WC_Admin_Status` echoes Tools responses through `esc_html()`. If
	 * the descriptions contain `<code>` markup, the operator sees
	 * `&lt;code&gt;` literals on screen. The fix swapped to backticks.
	 * Asserting against the rendered descriptions catches a regression
	 * if anyone reverts to HTML markup.
	 */
	public function test_tools_page_descriptions_do_not_contain_raw_code_tags(): void {
		$controller = wc_get_container()->get( CustomProductsTableController::class );
		$tools      = $controller->add_hpps_tools( array() );

		foreach ( $tools as $key => $tool ) {
			$desc = (string) ( $tool['desc'] ?? '' );
			$this->assertStringNotContainsString(
				'<code>',
				$desc,
				"N4 regression: tool '{$key}' must not embed <code> markup — WC_Admin_Status will escape it to literal entities."
			);
		}
	}
}
