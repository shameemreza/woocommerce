<?php
/**
 * PostToProductTableMigrator class file.
 *
 * @package WooCommerce\Database\Migrations\CustomProductsTable
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Database\Migrations\CustomProductsTable;

use Automattic\WooCommerce\Enums\CatalogVisibility;
use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\DataStores\Products\ProductsTableDataStore;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates a single product (post + postmeta + terms) into the HPPS tables.
 *
 * Phase 1 strategy: non-destructive copy. The wp_posts row remains as
 * `product` / `product_variation` with all its postmeta intact, so a site
 * that flips HPPS off can keep operating against the legacy CPT data store.
 * The migrator just builds the mirroring rows in:
 *
 *  - wc_products
 *  - wc_product_attributes
 *  - wc_product_attribute_values (with `scope` set per row)
 *  - wc_product_downloads
 *  - wc_products_meta (overflow)
 *  - wc_product_meta_lookup (compatibility for legacy queries)
 *
 * The migrator is idempotent — running it twice on the same product is a
 * no-op (it returns the IDs as `skipped`).
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class PostToProductTableMigrator {

	/**
	 * Source name used for migration log entries.
	 */
	public const LOGS_SOURCE_NAME = 'posts-to-products-migration';

	/**
	 * Map of legacy `_postmeta` keys to `wc_products` column names.
	 *
	 * @var array<string,string>
	 */
	private const META_TO_COLUMN = array(
		'_sku'                   => 'sku',
		'_global_unique_id'      => 'global_unique_id',
		'_regular_price'         => 'regular_price',
		'_sale_price'            => 'sale_price',
		'_price'                 => 'price',
		'_sale_price_dates_from' => 'date_on_sale_from',
		'_sale_price_dates_to'   => 'date_on_sale_to',
		'_tax_status'            => 'tax_status',
		'_tax_class'             => 'tax_class',
		'_manage_stock'          => 'manage_stock',
		'_stock'                 => 'stock_quantity',
		'_stock_status'          => 'stock_status',
		'_backorders'            => 'backorders',
		'_low_stock_amount'      => 'low_stock_amount',
		'_sold_individually'     => 'sold_individually',
		'_weight'                => 'weight',
		'_length'                => 'length',
		'_width'                 => 'width',
		'_height'                => 'height',
		'_virtual'               => 'virtual',
		'_downloadable'          => 'downloadable',
		'_featured'              => 'featured',
		'_visibility'            => 'catalog_visibility',
		'_wc_average_rating'     => 'average_rating',
		'_wc_review_count'       => 'review_count',
		'_download_limit'        => 'download_limit',
		'_download_expiry'       => 'download_expiry',
		'_purchase_note'         => 'purchase_note',
		'_thumbnail_id'          => 'image_id',
		'_product_image_gallery' => 'gallery_image_ids',
		'_product_url'           => 'product_url',
		'_button_text'           => 'button_text',
		'_cogs_total_value'      => 'cogs_value',
		'total_sales'            => 'total_sales',
	);

	/**
	 * Internal meta keys we don't copy into `wc_products_meta` because they
	 * are already represented as columns/subtables.
	 *
	 * @var array<string>
	 */
	private const INTERNAL_META_KEYS = array(
		'_product_attributes',
		'_default_attributes',
		'_downloadable_files',
		'_children',
		'_wc_rating_count',
		'_product_version',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Data store used as a source-of-truth for the column mapping & lookup
	 * sync. We don't call its CRUD methods.
	 *
	 * @var ProductsTableDataStore
	 */
	private ProductsTableDataStore $data_store;

	/**
	 * Class constructor.
	 *
	 * @param ProductsTableDataStore $data_store HPPS data store.
	 */
	public function __construct( ProductsTableDataStore $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Migrate a batch of product posts to the HPPS tables.
	 *
	 * @param int[] $product_ids Product / variation post IDs.
	 * @return array{migrated:int[],skipped:int[],errors:array<int,string>}
	 */
	public function migrate_products( array $product_ids ): array {
		$result = array(
			'migrated' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);

		$logger = wc_get_logger();

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				continue;
			}

			try {
				if ( $this->is_already_migrated( $product_id ) ) {
					$result['skipped'][] = $product_id;
					continue;
				}

				$this->migrate_single_product( $product_id );
				$result['migrated'][] = $product_id;
			} catch ( Throwable $e ) {
				$result['errors'][ $product_id ] = $e->getMessage();
				$logger->error(
					sprintf(
						/* translators: 1: product ID, 2: error message. */
						__( 'HPPS migration failed for product %1$d: %2$s', 'woocommerce' ),
						$product_id,
						$e->getMessage()
					),
					array( 'source' => self::LOGS_SOURCE_NAME )
				);
			}
		}

		return $result;
	}

	/**
	 * Whether the given product already has a row in `wc_products`.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function is_already_migrated( int $product_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d LIMIT 1',
				ProductsTableDataStore::get_products_table_name(),
				$product_id
			)
		);
	}

	/**
	 * Migrate a single product post.
	 *
	 * Wraps the multi-table writes in a transaction so a failure mid-way
	 * leaves the HPPS side untouched; the legacy postmeta is never modified.
	 *
	 * @param int $product_id Product / variation post ID.
	 * @return void
	 *
	 * @throws \Exception When the source post is missing or invalid.
	 */
	public function migrate_single_product( int $product_id ): void {
		global $wpdb;

		$post = get_post( $product_id );
		if ( ! $post ) {
			throw new \Exception( sprintf( 'Product %d: source post not found.', $product_id ) );
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			throw new \Exception(
				sprintf( 'Product %d: unsupported post_type "%s".', $product_id, $post->post_type )
			);
		}

		$postmeta = $this->read_postmeta( $product_id );
		$type     = $this->detect_product_type( $post, $postmeta );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			$this->insert_main_row( $post, $postmeta, $type );
			$this->insert_attributes( $product_id, $type, $postmeta );
			$this->insert_downloads( $product_id, $postmeta );
			$this->insert_overflow_meta( $product_id, $postmeta );

			$this->data_store->update_lookup_table( $product_id );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			throw $e;
		}

		/**
		 * Fires after a single product is migrated to HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int    $product_id Product ID.
		 * @param string $type       Detected product type.
		 */
		do_action( 'woocommerce_product_migrated_to_hpps', $product_id, $type );
	}

	/**
	 * Detect the product type from the product_type taxonomy or, for
	 * variations, from the post_type.
	 *
	 * @param \WP_Post              $post     Product post.
	 * @param array<string, string> $postmeta Postmeta map.
	 * @return string Product type slug (defaults to `simple`).
	 */
	private function detect_product_type( \WP_Post $post, array $postmeta ): string {
		unset( $postmeta );

		if ( 'product_variation' === $post->post_type ) {
			return ProductType::VARIATION;
		}

		$terms = wp_get_object_terms( $post->ID, 'product_type', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return ProductType::SIMPLE;
		}

		return (string) reset( $terms );
	}

	/**
	 * Build and insert the `wc_products` row for the post.
	 *
	 * @param \WP_Post              $post     Product post.
	 * @param array<string, string> $postmeta Postmeta map.
	 * @param string                $type     Product type.
	 * @return void
	 */
	private function insert_main_row( \WP_Post $post, array $postmeta, string $type ): void {
		global $wpdb;

		$mapping  = $this->data_store->get_product_column_mapping();
		$row      = array();
		$formats  = array();

		$row['id']                = (int) $post->ID;
		$row['type']              = $type;
		$row['status']            = (string) $post->post_status;
		$row['name']              = (string) $post->post_title;
		$row['slug']              = (string) $post->post_name;
		$row['description']       = (string) $post->post_content;
		$row['short_description'] = (string) $post->post_excerpt;
		$row['parent_id']         = (int) $post->post_parent;
		$row['menu_order']        = (int) $post->menu_order;
		$row['date_created_gmt']  = $this->safe_datetime_gmt( $post->post_date_gmt, $post->post_date );
		$row['date_modified_gmt'] = $this->safe_datetime_gmt( $post->post_modified_gmt, $post->post_modified );
		$row['post_password']     = (string) $post->post_password;
		$row['reviews_allowed']   = 'open' === $post->comment_status ? 1 : 0;

		foreach ( self::META_TO_COLUMN as $meta_key => $column ) {
			if ( ! array_key_exists( $meta_key, $postmeta ) ) {
				continue;
			}
			$value                  = $postmeta[ $meta_key ];
			$row[ $column ]         = $this->coerce_for_column( $column, $value, $mapping );
		}

		// `featured`, `catalog_visibility` and `average_rating` are stored on
		// the `product_visibility` taxonomy in the legacy schema. Read those
		// terms once and let them override anything the postmeta path filled
		// in (postmeta values for these keys are rarely present).
		$visibility_facts = $this->resolve_visibility_facts( (int) $post->ID );
		if ( null !== $visibility_facts['featured'] ) {
			$row['featured'] = $visibility_facts['featured'];
		}
		if ( null !== $visibility_facts['catalog_visibility'] ) {
			$row['catalog_visibility'] = $visibility_facts['catalog_visibility'];
		}
		if ( null !== $visibility_facts['average_rating'] && ! isset( $row['average_rating'] ) ) {
			$row['average_rating'] = $visibility_facts['average_rating'];
		}

		if ( ! isset( $row['catalog_visibility'] ) || '' === $row['catalog_visibility'] ) {
			$row['catalog_visibility'] = CatalogVisibility::VISIBLE;
		}

		if ( ! isset( $row['stock_status'] ) || '' === $row['stock_status'] ) {
			$row['stock_status'] = ProductStockStatus::IN_STOCK;
		}

		if ( ! isset( $row['status'] ) || '' === $row['status'] ) {
			$row['status'] = ProductStatus::PUBLISH;
		}

		// Build %s/%d format array from the mapping (id is %d).
		foreach ( $row as $column => $value ) {
			$type_hint           = $mapping[ $column ]['type'] ?? 'string';
			$formats[ $column ]  = in_array( $type_hint, array( 'int', 'bool' ), true ) ? '%d' : '%s';
			if ( null === $value ) {
				$formats[ $column ] = null;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			ProductsTableDataStore::get_products_table_name(),
			$row,
			array_values( $formats )
		);

		if ( false === $inserted ) {
			throw new \Exception( sprintf( 'Product %d: failed inserting wc_products row: %s', $post->ID, $wpdb->last_error ) );
		}
	}

	/**
	 * Migrate attributes from `_product_attributes` (parents) and
	 * `attribute_*` postmeta (variations) into the HPPS attribute tables.
	 *
	 * @param int                   $product_id Product / variation ID.
	 * @param string                $type       Product type.
	 * @param array<string, string> $postmeta   Postmeta map.
	 * @return void
	 */
	private function insert_attributes( int $product_id, string $type, array $postmeta ): void {
		global $wpdb;

		if ( ProductType::VARIATION === $type ) {
			$this->insert_variation_attributes( $product_id, $postmeta );
			return;
		}

		$raw = $postmeta['_product_attributes'] ?? '';
		$raw = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return;
		}

		$default_attributes = $postmeta['_default_attributes'] ?? '';
		$default_attributes = is_string( $default_attributes ) ? maybe_unserialize( $default_attributes ) : $default_attributes;
		$default_attributes = is_array( $default_attributes ) ? $default_attributes : array();

		$position = 0;
		foreach ( $raw as $attribute_key => $config ) {
			$config = (array) $config;
			$name   = (string) ( $config['name'] ?? $attribute_key );

			$attribute_data = array(
				'product_id'       => $product_id,
				'name'             => $name,
				'taxonomy'         => ! empty( $config['is_taxonomy'] ) ? $name : '',
				'position'         => isset( $config['position'] ) ? (int) $config['position'] : $position,
				'is_visible'       => ! empty( $config['is_visible'] ) ? 1 : 0,
				'is_for_variation' => ! empty( $config['is_variation'] ) ? 1 : 0,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				ProductsTableDataStore::get_attributes_table_name(),
				$attribute_data,
				array( '%d', '%s', '%s', '%d', '%d', '%d' )
			);

			$attribute_row_id = (int) $wpdb->insert_id;

			$values_position = 0;
			if ( ! empty( $config['is_taxonomy'] ) ) {
				$terms = wp_get_object_terms( $product_id, $name );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( (array) $terms as $term ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						ProductsTableDataStore::insert_attribute_value_row(
							ProductsTableDataStore::get_attribute_values_table_name(),
							$product_id,
							$attribute_row_id,
							'product',
							(string) $term->slug,
							(int) $term->term_id,
							0,
							$values_position
						);
						++$values_position;
					}
				}
			} else {
				$values = wc_get_text_attributes( (string) ( $config['value'] ?? '' ) );
				foreach ( $values as $value ) {
					ProductsTableDataStore::insert_attribute_value_row(
						ProductsTableDataStore::get_attribute_values_table_name(),
						$product_id,
						$attribute_row_id,
						'product',
						(string) $value,
						null,
						0,
						$values_position
					);
					++$values_position;
				}
			}

			// Default attribute (parent-level "first selection" hint).
			if ( ! empty( $default_attributes[ $attribute_key ] ) ) {
				ProductsTableDataStore::insert_attribute_value_row(
					ProductsTableDataStore::get_attribute_values_table_name(),
					$product_id,
					$attribute_row_id,
					'product',
					(string) $default_attributes[ $attribute_key ],
					null,
					1,
					0
				);
			}

			++$position;
		}
	}

	/**
	 * Variation attribute migration: each `attribute_*` postmeta becomes a
	 * single value row with `scope = 'variation'`. Tries to bind to the
	 * parent's attribute_id when possible.
	 *
	 * @param int                   $variation_id Variation ID.
	 * @param array<string, string> $postmeta     Variation postmeta map.
	 * @return void
	 */
	private function insert_variation_attributes( int $variation_id, array $postmeta ): void {
		global $wpdb;

		$parent_id = (int) wp_get_post_parent_id( $variation_id );

		$position = 0;
		foreach ( $postmeta as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
				continue;
			}

			$attribute_name = substr( (string) $key, 10 );
			$attribute_id   = 0;
			$term_id        = null;

			if ( $parent_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$attribute_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM %i WHERE product_id = %d AND ( name = %s OR taxonomy = %s ) LIMIT 1',
						ProductsTableDataStore::get_attributes_table_name(),
						$parent_id,
						$attribute_name,
						$attribute_name
					)
				);
			}

			if ( taxonomy_exists( $attribute_name ) && '' !== (string) $value ) {
				$term = get_term_by( 'slug', (string) $value, $attribute_name );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = (int) $term->term_id;
				}
			}

			ProductsTableDataStore::insert_attribute_value_row(
				ProductsTableDataStore::get_attribute_values_table_name(),
				$variation_id,
				$attribute_id,
				'variation',
				(string) $value,
				$term_id,
				0,
				$position
			);
			++$position;
		}
	}

	/**
	 * Migrate `_downloadable_files` postmeta to wc_product_downloads.
	 *
	 * @param int                   $product_id Product / variation ID.
	 * @param array<string, string> $postmeta   Postmeta map.
	 * @return void
	 */
	private function insert_downloads( int $product_id, array $postmeta ): void {
		global $wpdb;

		$raw = $postmeta['_downloadable_files'] ?? '';
		$raw = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return;
		}

		$position = 0;
		foreach ( $raw as $download_id => $download ) {
			$download = is_array( $download ) ? $download : array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				ProductsTableDataStore::get_downloads_table_name(),
				array(
					'product_id'  => $product_id,
					'download_id' => (string) $download_id,
					'name'        => (string) ( $download['name'] ?? '' ),
					'file'        => (string) ( $download['file'] ?? '' ),
					'sort_order'  => $position,
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
			++$position;
		}
	}

	/**
	 * Copy "extension" postmeta (anything not represented by a column or
	 * subtable) into wc_products_meta so user/extension data survives the
	 * migration.
	 *
	 * @param int                   $product_id Product / variation ID.
	 * @param array<string, string> $postmeta   Postmeta map.
	 * @return void
	 */
	private function insert_overflow_meta( int $product_id, array $postmeta ): void {
		global $wpdb;

		$internal = array_merge(
			array_keys( self::META_TO_COLUMN ),
			self::INTERNAL_META_KEYS
		);

		foreach ( $postmeta as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, $internal, true ) ) {
				continue;
			}
			if ( 0 === strpos( $key, 'attribute_' ) ) {
				continue; // Already migrated as variation attribute rows.
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				ProductsTableDataStore::get_meta_table_name(),
				array(
					'product_id' => $product_id,
					'meta_key'   => $key,
					'meta_value' => is_array( $value ) || is_object( $value ) ? maybe_serialize( $value ) : (string) $value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Read all postmeta for a product into a flat key=>value map (taking the
	 * first value when multi-values exist; this matches the legacy column
	 * mapping which only stores a single value per column).
	 *
	 * @param int $product_id Product / variation ID.
	 * @return array<string, string>
	 */
	private function read_postmeta( int $product_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
				$product_id
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( (string) $row->meta_key, $map ) ) {
				continue;
			}
			$map[ (string) $row->meta_key ] = (string) $row->meta_value;
		}
		return $map;
	}

	/**
	 * Coerce a postmeta string value to the right shape for its column.
	 *
	 * @param string                                          $column  Column name.
	 * @param mixed                                           $value   Raw postmeta value.
	 * @param array<string, array{name:string,type:string}>  $mapping Column mapping.
	 * @return mixed
	 */
	private function coerce_for_column( string $column, $value, array $mapping ) {
		$type = $mapping[ $column ]['type'] ?? 'string';

		switch ( $type ) {
			case 'int':
				return null === $value || '' === $value ? null : (int) $value;
			case 'decimal':
				return null === $value || '' === $value ? null : (string) $value;
			case 'bool':
				if ( '' === $value || null === $value ) {
					return 0;
				}
				return ( 'yes' === $value || '1' === (string) $value || 1 === $value || true === $value ) ? 1 : 0;
			case 'date':
				if ( null === $value || '' === $value ) {
					return null;
				}
				// Sale-date postmeta is stored as a Unix timestamp string by
				// the legacy CPT data store; convert to a GMT datetime string.
				if ( ctype_digit( (string) $value ) ) {
					return gmdate( 'Y-m-d H:i:s', (int) $value );
				}
				return $this->safe_datetime_gmt( (string) $value, '' );
			default:
				return null === $value ? null : (string) $value;
		}
	}

	/**
	 * Read the `product_visibility` taxonomy terms for a product and derive
	 * the equivalent column values (featured / catalog_visibility / rating).
	 *
	 * @param int $product_id Product ID.
	 * @return array{featured:?int,catalog_visibility:?string,average_rating:?string}
	 */
	private function resolve_visibility_facts( int $product_id ): array {
		$terms = wp_get_object_terms( $product_id, 'product_visibility', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) ) {
			return array(
				'featured'           => null,
				'catalog_visibility' => null,
				'average_rating'     => null,
			);
		}

		$names           = is_array( $terms ) ? $terms : array();
		$exclude_search  = in_array( 'exclude-from-search', $names, true );
		$exclude_catalog = in_array( 'exclude-from-catalog', $names, true );

		if ( $exclude_search && $exclude_catalog ) {
			$visibility = CatalogVisibility::HIDDEN;
		} elseif ( $exclude_search ) {
			$visibility = CatalogVisibility::CATALOG;
		} elseif ( $exclude_catalog ) {
			$visibility = CatalogVisibility::SEARCH;
		} else {
			$visibility = CatalogVisibility::VISIBLE;
		}

		$rating = null;
		foreach ( $names as $name ) {
			if ( 0 === strpos( (string) $name, 'rated-' ) ) {
				$rating = (string) substr( (string) $name, 6 );
				break;
			}
		}

		return array(
			'featured'           => in_array( 'featured', $names, true ) ? 1 : 0,
			'catalog_visibility' => $visibility,
			'average_rating'     => $rating,
		);
	}

	/**
	 * Normalise a datetime: prefer the GMT one, fall back to converting from
	 * site timezone, return null when neither is available or non-zero.
	 *
	 * @param string $gmt   GMT datetime string ("Y-m-d H:i:s") or empty.
	 * @param string $local Local datetime string or empty.
	 * @return string|null
	 */
	private function safe_datetime_gmt( string $gmt, string $local ): ?string {
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return $gmt;
		}
		if ( '' !== $local && '0000-00-00 00:00:00' !== $local ) {
			$timestamp = strtotime( $local . ' ' . wp_timezone_string() );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}
		return null;
	}
}
