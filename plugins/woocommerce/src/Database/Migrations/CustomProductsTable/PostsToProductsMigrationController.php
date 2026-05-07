<?php
/**
 * PostsToProductsMigrationController class file.
 *
 * @package WooCommerce\Database\Migrations\CustomProductsTable
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Database\Migrations\CustomProductsTable;

use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessorInterface;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Batch processor that moves legacy `product` and `product_variation` posts
 * into the HPPS tables. Implements `BatchProcessorInterface` so it can be
 * driven by `BatchProcessingController` (Action Scheduler-backed) or by the
 * WP-CLI runner.
 *
 * Phase 1: one-way migration. The legacy postmeta rows are *not* removed —
 * a site can flip HPPS off and revert to the CPT data store with no data
 * loss. Migration is idempotent: re-running it skips products already in
 * `wc_products`.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class PostsToProductsMigrationController implements BatchProcessorInterface {

	/**
	 * Default batch size — small enough to fit comfortably under typical
	 * PHP memory limits even for very meta-heavy products.
	 */
	private const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Per-product migrator dependency.
	 *
	 * @var PostToProductTableMigrator
	 */
	private PostToProductTableMigrator $migrator;

	/**
	 * HPPS data store dependency (used by the per-product migrator).
	 *
	 * @var ProductsTableDataStore
	 */
	private ProductsTableDataStore $data_store;

	/**
	 * Constructor injects dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param ProductsTableDataStore $data_store HPPS data store.
	 */
	final public function init( ProductsTableDataStore $data_store ): void {
		$this->data_store = $data_store;
		$this->migrator   = new PostToProductTableMigrator( $data_store );
	}

	/**
	 * Lazy-create the per-product migrator if `init()` hasn't been called
	 * (e.g. when the class is instantiated outside the DI container).
	 *
	 * @return PostToProductTableMigrator
	 */
	public function get_migrator(): PostToProductTableMigrator {
		if ( ! isset( $this->migrator ) ) {
			$this->data_store = wc_get_container()->get( ProductsTableDataStore::class );
			$this->migrator   = new PostToProductTableMigrator( $this->data_store );
		}
		return $this->migrator;
	}

	/**
	 * Human-readable name used by the batch processing controller UI.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'posts_to_products_migration';
	}

	/**
	 * Description used by the batch processing controller UI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Migrate products from the legacy WordPress posts storage to the High-Performance Product Storage tables.', 'woocommerce' );
	}

	/**
	 * Default batch size for `BatchProcessingController`.
	 *
	 * @return int
	 */
	public function get_default_batch_size(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	/**
	 * Total products that are still in legacy storage and have no row in
	 * `wc_products` yet.
	 *
	 * @return int
	 */
	public function get_total_pending_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN " . ProductsTableDataStore::get_products_table_name() . ' wp ON wp.id = p.ID
				WHERE p.post_type IN ( %s, %s )
				AND p.post_status NOT IN ( %s, %s )
				AND wp.id IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'product',
				'product_variation',
				'auto-draft',
				'inherit'
			)
		);
	}

	/**
	 * Return the next batch of post IDs that still need migration.
	 *
	 * @param int $size Batch size.
	 * @return int[]
	 */
	public function get_next_batch_to_process( int $size ): array {
		global $wpdb;

		$size = max( 1, $size );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN " . ProductsTableDataStore::get_products_table_name() . ' wp ON wp.id = p.ID
				WHERE p.post_type IN ( %s, %s )
				AND p.post_status NOT IN ( %s, %s )
				AND wp.id IS NULL
				ORDER BY p.ID ASC
				LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'product',
				'product_variation',
				'auto-draft',
				'inherit',
				$size
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Process a batch of product IDs.
	 *
	 * @param int[] $batch List of product / variation post IDs.
	 * @return void
	 *
	 * @throws \Exception When the underlying migrator reports any errors.
	 */
	public function process_batch( array $batch ): void {
		if ( empty( $batch ) ) {
			return;
		}

		$result = $this->get_migrator()->migrate_products( $batch );

		if ( ! empty( $result['errors'] ) ) {
			// Surface a single combined message so the BatchProcessingController
			// can log it and back-off the retry loop.
			$summary = array();
			foreach ( $result['errors'] as $product_id => $message ) {
				$summary[] = sprintf( '%d: %s', $product_id, $message );
			}
			throw new \Exception( esc_html( implode( ' | ', $summary ) ) );
		}
	}
}
