<?php
/**
 * ProductDataSynchronizer class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Database\Migrations\CustomProductsTable\PostsToProductsMigrationController;
use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController;
use Automattic\WooCommerce\Internal\Utilities\DatabaseUtil;

defined( 'ABSPATH' ) || exit;

/**
 * High-level orchestration helper for HPPS migrations.
 *
 * Sits between the batch processor and the rest of the codebase. Responsible
 * for:
 *
 *  - reporting whether HPPS has any pending work to do (drives admin notice
 *    + WP-CLI status output),
 *  - enqueueing the {@see PostsToProductsMigrationController} into the
 *    background batch processor so the migration runs without operator
 *    intervention once a flag is set,
 *  - exposing simple "run synchronously" helpers used by the WP-CLI runner
 *    and the optional admin button.
 *
 * Phase 1 deliberately ships *one-way* migration (CPT → HPPS). Bidirectional
 * sync, dual-write and "verify" passes are tracked for later phases — the
 * relevant option keys are reserved here to keep the future surface stable.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductDataSynchronizer {

	/**
	 * Marks whether the current site is in the middle of (or has finished)
	 * a one-time CPT → HPPS migration. Possible values: `pending`, `done`.
	 */
	public const PRODUCTS_TABLE_MIGRATION_OPTION = 'woocommerce_products_table_migration_status';

	/**
	 * Cached "do the HPPS tables physically exist" flag. Mirrors HPOS'
	 * `woocommerce_custom_orders_table_created` option so we don't run a
	 * SHOW TABLES query on every request.
	 */
	public const PRODUCTS_TABLE_CREATED_OPTION = 'woocommerce_custom_product_tables_created';

	/**
	 * Source name used for synchronizer log entries.
	 */
	public const LOGS_SOURCE_NAME = 'product-data-synchronizer';

	/**
	 * Migration controller dependency.
	 *
	 * @var PostsToProductsMigrationController
	 */
	private PostsToProductsMigrationController $migration_controller;

	/**
	 * Batch processing controller dependency.
	 *
	 * @var BatchProcessingController
	 */
	private BatchProcessingController $batch_processing_controller;

	/**
	 * HPPS data store dependency, used for schema introspection.
	 *
	 * @var ProductsTableDataStore
	 */
	private ProductsTableDataStore $data_store;

	/**
	 * Database util dependency.
	 *
	 * @var DatabaseUtil
	 */
	private DatabaseUtil $database_util;

	/**
	 * Inject dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param PostsToProductsMigrationController $migration_controller        Migration controller.
	 * @param BatchProcessingController          $batch_processing_controller Batch processor.
	 * @param ProductsTableDataStore             $data_store                  HPPS data store (for schema/table-name introspection).
	 * @param DatabaseUtil                       $database_util               Database util helper.
	 */
	final public function init(
		PostsToProductsMigrationController $migration_controller,
		BatchProcessingController $batch_processing_controller,
		ProductsTableDataStore $data_store,
		DatabaseUtil $database_util
	): void {
		$this->migration_controller        = $migration_controller;
		$this->batch_processing_controller = $batch_processing_controller;
		$this->data_store                  = $data_store;
		$this->database_util               = $database_util;
	}

	/*
	|--------------------------------------------------------------------------
	| Table lifecycle.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether all HPPS tables physically exist in the database.
	 *
	 * Performs a real `SHOW TABLES` check (via DatabaseUtil) and refreshes the
	 * cached option so subsequent calls return without hitting the DB.
	 *
	 * @return bool
	 */
	public function check_products_table_exists(): bool {
		$missing = $this->database_util->get_missing_tables( $this->data_store->get_database_schema() );

		if ( count( $missing ) === 0 ) {
			update_option( self::PRODUCTS_TABLE_CREATED_OPTION, 'yes' );
			return true;
		}

		update_option( self::PRODUCTS_TABLE_CREATED_OPTION, 'no' );
		return false;
	}

	/**
	 * Cached version of {@see check_products_table_exists()}. Reads the
	 * cached option first and only falls back to a real check when the cache
	 * is empty.
	 *
	 * @return bool
	 */
	public function get_table_exists(): bool {
		$cached = get_option( self::PRODUCTS_TABLE_CREATED_OPTION );
		switch ( $cached ) {
			case 'no':
			case 'yes':
				return 'yes' === $cached;
			default:
				return $this->check_products_table_exists();
		}
	}

	/**
	 * Run dbDelta to create any missing HPPS tables. Logs an error if some
	 * tables remain missing afterwards.
	 *
	 * @return bool True when all tables exist after the call, false otherwise.
	 */
	public function create_database_tables(): bool {
		$this->database_util->dbdelta( $this->data_store->get_database_schema() );

		$success = $this->check_products_table_exists();
		if ( ! $success ) {
			$missing = $this->database_util->get_missing_tables( $this->data_store->get_database_schema() );
			wc_get_logger()->error(
				'HPPS tables are missing in the database and could not be created. Missing tables: ' . implode( ', ', $missing ),
				array( 'source' => self::LOGS_SOURCE_NAME )
			);
		}
		return $success;
	}

	/**
	 * Drop every HPPS table. Used by uninstall/reset paths.
	 *
	 * @return void
	 */
	public function delete_database_tables(): void {
		foreach ( $this->data_store->get_all_table_names() as $table ) {
			$this->database_util->drop_database_table( $table );
		}
		delete_option( self::PRODUCTS_TABLE_CREATED_OPTION );
	}

	/**
	 * Whether at least one product is still missing from `wc_products`.
	 *
	 * @return bool
	 */
	public function has_products_pending_sync(): bool {
		return $this->migration_controller->get_total_pending_count() > 0;
	}

	/**
	 * Total products that still need migrating.
	 *
	 * @return int
	 */
	public function get_pending_count(): int {
		return $this->migration_controller->get_total_pending_count();
	}

	/**
	 * Enqueue the migration into the background batch processor.
	 *
	 * @return void
	 */
	public function enqueue_background_migration(): void {
		update_option( self::PRODUCTS_TABLE_MIGRATION_OPTION, 'pending' );
		$this->batch_processing_controller->enqueue_processor( PostsToProductsMigrationController::class );
	}

	/**
	 * Stop the background migration (no-op if not enqueued).
	 *
	 * @return void
	 */
	public function dequeue_background_migration(): void {
		$this->batch_processing_controller->remove_processor( PostsToProductsMigrationController::class );
	}

	/**
	 * Run the migration synchronously, in batches of `$batch_size`, until
	 * every pending product is processed or `$max_iterations` is reached.
	 *
	 * Returns a summary array with totals for migrated / skipped / errored.
	 *
	 * @param int $batch_size     Batch size per iteration.
	 * @param int $max_iterations Hard ceiling on iterations to run.
	 * @return array{migrated:int,skipped:int,errors:int,iterations:int}
	 */
	public function run_synchronously( int $batch_size = 50, int $max_iterations = 1000 ): array {
		$batch_size = max( 1, $batch_size );

		$migrated   = 0;
		$skipped    = 0;
		$errors     = 0;
		$iterations = 0;

		$migrator = $this->migration_controller->get_migrator();

		while ( $iterations < $max_iterations ) {
			++$iterations;
			$batch = $this->migration_controller->get_next_batch_to_process( $batch_size );
			if ( empty( $batch ) ) {
				update_option( self::PRODUCTS_TABLE_MIGRATION_OPTION, 'done' );
				break;
			}

			$result   = $migrator->migrate_products( $batch );
			$migrated += count( $result['migrated'] );
			$skipped  += count( $result['skipped'] );
			$errors   += count( $result['errors'] );

			if ( ! empty( $result['errors'] ) ) {
				// Stop after the first batch with errors so the operator can
				// inspect the log; running on indefinitely is rarely useful.
				break;
			}
		}

		return array(
			'migrated'   => $migrated,
			'skipped'    => $skipped,
			'errors'     => $errors,
			'iterations' => $iterations,
		);
	}

	/**
	 * Has the migration ever finished?
	 *
	 * @return bool
	 */
	public function migration_is_complete(): bool {
		return 'done' === get_option( self::PRODUCTS_TABLE_MIGRATION_OPTION ) && ! $this->has_products_pending_sync();
	}
}
