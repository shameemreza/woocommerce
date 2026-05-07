<?php
/**
 * HPPS CLI runner.
 *
 * @package WooCommerce\Database\Migrations\CustomProductsTable
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Database\Migrations\CustomProductsTable;

use Automattic\WooCommerce\Internal\DataStores\Products\CustomProductsTableController;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductDataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductMigrationVerifier;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp wc hpps ...` commands for the High-Performance Product Storage feature.
 *
 * Surfaces:
 *
 *  - `wp wc hpps status`              — print pending count + feature flag.
 *  - `wp wc hpps enable` / `disable`  — flip the feature option.
 *  - `wp wc hpps migrate [--batch-size=<n>]` — run the migration to
 *    completion in the foreground.
 *  - `wp wc hpps verify`              — basic post/wc_products row-count
 *    parity check.
 *
 * Mirrors the UX of `wp wc hpos` so operators don't have to learn a new
 * command surface.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class CLIRunner {

	/**
	 * Synchronizer dependency.
	 *
	 * @var ProductDataSynchronizer
	 */
	private ProductDataSynchronizer $synchronizer;

	/**
	 * HPPS controller dependency.
	 *
	 * @var CustomProductsTableController
	 */
	private CustomProductsTableController $controller;

	/**
	 * Verifier shared with the Tools-page handler. Owns the per-field diff
	 * logic so both surfaces stay aligned.
	 *
	 * @var ProductMigrationVerifier
	 */
	private ProductMigrationVerifier $verifier;

	/**
	 * Inject dependencies via the DI container.
	 *
	 * @internal
	 *
	 * @param ProductDataSynchronizer       $synchronizer Synchronizer.
	 * @param CustomProductsTableController $controller   Controller.
	 * @param ProductMigrationVerifier      $verifier     Shared verifier.
	 */
	final public function init(
		ProductDataSynchronizer $synchronizer,
		CustomProductsTableController $controller,
		ProductMigrationVerifier $verifier
	): void {
		$this->synchronizer = $synchronizer;
		$this->controller   = $controller;
		$this->verifier     = $verifier;
	}

	/**
	 * Register the WP-CLI commands.
	 *
	 * @return void
	 */
	public function register_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'wc hpps status', array( $this, 'status' ) );
		WP_CLI::add_command( 'wc hpps enable', array( $this, 'enable' ) );
		WP_CLI::add_command( 'wc hpps disable', array( $this, 'disable' ) );
		WP_CLI::add_command( 'wc hpps migrate', array( $this, 'migrate' ) );
		WP_CLI::add_command( 'wc hpps verify', array( $this, 'verify' ) );
	}

	/**
	 * `wp wc hpps status` — pending count + feature flag.
	 *
	 * @return void
	 */
	public function status(): void {
		$pending = $this->synchronizer->get_pending_count();
		$enabled = $this->controller->custom_product_tables_usage_is_enabled();

		WP_CLI::log( sprintf( 'HPPS feature enabled: %s', $enabled ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Products pending migration: %d', $pending ) );
		WP_CLI::log( sprintf( 'Migration option: %s', (string) get_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION, 'pending' ) ) );

		if ( $enabled && $pending > 0 ) {
			WP_CLI::warning( 'HPPS is enabled but there are unmigrated products. Run `wp wc hpps migrate`.' );
		} elseif ( ! $enabled && 0 === $pending ) {
			WP_CLI::log( 'All products migrated. You can enable HPPS with `wp wc hpps enable`.' );
		}
	}

	/**
	 * `wp wc hpps enable` — flip the feature option to "yes".
	 *
	 * @return void
	 */
	public function enable(): void {
		update_option( CustomProductsTableController::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, 'yes' );
		WP_CLI::success( 'HPPS feature enabled.' );

		if ( $this->synchronizer->has_products_pending_sync() ) {
			WP_CLI::warning( 'There are still products in the legacy storage. Run `wp wc hpps migrate` to finish moving them.' );
		}
	}

	/**
	 * `wp wc hpps disable` — flip the feature option to "no".
	 *
	 * @return void
	 */
	public function disable(): void {
		update_option( CustomProductsTableController::CUSTOM_PRODUCT_TABLES_USAGE_ENABLED_OPTION, 'no' );
		WP_CLI::success( 'HPPS feature disabled. The legacy CPT data store is back in use.' );
	}

	/**
	 * `wp wc hpps migrate [--batch-size=<n>] [--max-iterations=<n>]`
	 *
	 * @param array<int, string>    $args        Positional args (unused).
	 * @param array<string, string> $assoc_args  Associative args.
	 * @return void
	 */
	public function migrate( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );

		$batch_size     = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 50;
		$max_iterations = isset( $assoc_args['max-iterations'] ) ? max( 1, (int) $assoc_args['max-iterations'] ) : 10000;

		$pending = $this->synchronizer->get_pending_count();
		if ( $pending <= 0 ) {
			WP_CLI::success( 'Nothing to migrate. All products already live in wc_products.' );
			return;
		}

		WP_CLI::log( sprintf( 'Migrating %d products in batches of %d...', $pending, $batch_size ) );
		$progress = WP_CLI\Utils\make_progress_bar( 'Migrating', $pending );

		$migrated_total = 0;
		$skipped_total  = 0;
		$errors_total   = 0;
		$iterations     = 0;

		$migrator = wc_get_container()
			->get( PostsToProductsMigrationController::class )
			->get_migrator();

		while ( $iterations < $max_iterations ) {
			++$iterations;

			$batch = wc_get_container()
				->get( PostsToProductsMigrationController::class )
				->get_next_batch_to_process( $batch_size );

			if ( empty( $batch ) ) {
				break;
			}

			$result          = $migrator->migrate_products( $batch );
			$migrated_total += count( $result['migrated'] );
			$skipped_total  += count( $result['skipped'] );
			$errors_total   += count( $result['errors'] );

			$progress->tick( count( $batch ) );

			if ( ! empty( $result['errors'] ) ) {
				$progress->finish();
				foreach ( $result['errors'] as $product_id => $message ) {
					WP_CLI::warning( sprintf( 'Product %d failed: %s', (int) $product_id, $message ) );
				}
				WP_CLI::error( 'Migration stopped after errors. Inspect the log source `posts-to-products-migration` and re-run.' );
				return;
			}
		}

		$progress->finish();
		update_option( ProductDataSynchronizer::PRODUCTS_TABLE_MIGRATION_OPTION, 'done' );

		WP_CLI::success(
			sprintf(
				'Done. Migrated: %d, skipped (already migrated): %d, errors: %d, batches: %d.',
				$migrated_total,
				$skipped_total,
				$errors_total,
				$iterations
			)
		);
	}

	/**
	 * `wp wc hpps verify [--id=<id>] [--limit=<n>] [--ignore=<csv>] [--format=<table|json>]`
	 *
	 * Cross-checks each migrated product by reading it once through the
	 * legacy CPT data store and once through the HPPS data store, then
	 * diffing the resulting WC_Product property arrays. The CPT side is
	 * the source of truth; HPPS is the candidate.
	 *
	 * Useful as a post-migration audit, and as a regression check before
	 * flipping the feature on for real traffic.
	 *
	 * Default sample is the first 100 wc_products rows. Pass `--id=<id>`
	 * to verify a single product or `--limit=0` to verify everything (slow
	 * on large catalogs).
	 *
	 * @param array<int,string>     $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function verify( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );

		$single_id = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;
		$limit     = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 100;
		$format    = (string) ( $assoc_args['format'] ?? 'table' );
		// Match the upstream `wp` formatter contract — `WP_CLI\Utils\format_items()`
		// supports table, csv, json, yaml, count, and ids. Reject anything
		// else with a clear error so a typo surfaces immediately instead of
		// silently falling through to table output.
		$supported_formats = array( 'table', 'csv', 'json', 'yaml', 'count', 'ids' );
		if ( ! in_array( $format, $supported_formats, true ) ) {
			WP_CLI::error(
				sprintf(
					'Unsupported --format=%s. Supported formats: %s.',
					$format,
					implode( ', ', $supported_formats )
				)
			);
		}
		$ignore = isset( $assoc_args['ignore'] )
			? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['ignore'] ) ) )
			: array( 'date_modified', 'date_modified_gmt' );

		$report = $this->verifier->verify(
			array(
				'id'     => $single_id,
				'limit'  => $limit,
				'ignore' => $ignore,
			)
		);

		// Print the row-count summary for context (matches the prior CLI
		// output ordering).
		$counts = $report['row_counts'];
		WP_CLI::log( sprintf( 'wp_posts (product / product_variation): %d', $counts['posts'] ) );
		WP_CLI::log( sprintf( 'wc_products: %d', $counts['hpps'] ) );
		WP_CLI::log( sprintf( 'Posts not yet in wc_products: %d', $counts['pending'] ) );

		if ( 0 === $report['checked'] ) {
			// Distinguish "everything passed" (exit 0, success) from
			// "we didn't actually check anything" (exit 2, warning).
			// Operators piping through CI/scripts need the difference
			// — a green build with zero verifications gives false
			// confidence on a fresh database.
			WP_CLI::warning( 'No HPPS products to verify yet — nothing was checked.' );
			WP_CLI::halt( 2 );
		}

		if ( empty( $report['mismatches'] ) ) {
			WP_CLI::success( sprintf( 'All %d product(s) match between legacy and HPPS storage.', $report['checked'] ) );
			return;
		}

		WP_CLI::warning(
			sprintf(
				'Found %d mismatched product(s) out of %d checked.',
				count( $report['mismatches'] ),
				$report['checked']
			)
		);

		$rows = array();
		foreach ( $report['mismatches'] as $entry ) {
			if ( ! empty( $entry['error'] ) ) {
				$rows[] = array(
					'id'    => $entry['id'],
					'field' => '(error)',
					'cpt'   => '',
					'hpps'  => $entry['error'],
				);
				continue;
			}
			foreach ( $entry['fields'] as $field => $values ) {
				$rows[] = array(
					'id'    => $entry['id'],
					'field' => $field,
					'cpt'   => $this->verifier->stringify_for_diff( $values['cpt'] ),
					'hpps'  => $this->verifier->stringify_for_diff( $values['hpps'] ),
				);
			}
		}

		// Hand the chosen format through to the upstream formatter so
		// `--format=csv|yaml|json|count|ids` all work.
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'field', 'cpt', 'hpps' ) );
		WP_CLI::halt( 1 );
	}
}
