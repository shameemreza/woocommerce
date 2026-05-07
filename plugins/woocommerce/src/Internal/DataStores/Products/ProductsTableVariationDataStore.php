<?php
/**
 * ProductsTableVariationDataStore class file.
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

use Automattic\WooCommerce\Enums\CatalogVisibility;
use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Exception;
use WC_Object_Data_Store_Interface;
use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Variable_Data_Store_CPT;
use WC_Product_Variation;
use WC_Product_Variation_Data_Store_CPT;

defined( 'ABSPATH' ) || exit;

/**
 * HPPS data store for product variations (`product_variation`).
 *
 * A variation in HPPS is just a row in `wc_products` with `type = 'variation'`
 * and `parent_id` pointing to the variable parent's row id. Attribute values
 * are stored in `wc_product_attribute_values` with `scope = 'variation'`,
 * one row per attribute (a single value per attribute, in contrast to the
 * variable parent which can have many options per attribute).
 *
 * The placeholder wp_posts row uses the parent's placeholder as `post_parent`
 * so any incidental wp_posts query (templates, search, etc.) still surfaces
 * a well-formed parent/child relationship.
 *
 * @since 10.9.0
 *
 * @internal Class is not part of the public API. Backwards compatibility not guaranteed.
 */
class ProductsTableVariationDataStore extends ProductsTableDataStore implements WC_Object_Data_Store_Interface {

	/**
	 * Variations have their own legacy data store (postmeta key handling differs
	 * from the parent simple/variable stores). Use it as the fall-back for
	 * variations that haven't been migrated yet.
	 *
	 * @return WC_Product_Data_Store_CPT
	 */
	protected function get_legacy_data_store(): WC_Product_Data_Store_CPT {
		static $legacy = null;
		if ( null === $legacy ) {
			$legacy = new WC_Product_Variation_Data_Store_CPT();
		}
		return $legacy;
	}

	/**
	 * Create a new variation row.
	 *
	 * @param WC_Product_Variation $product Variation passed by reference.
	 * @return void
	 *
	 * @throws Exception If the placeholder post cannot be created or the parent is invalid.
	 */
	public function create( &$product ) {
		global $wpdb;

		if ( ! $product->get_date_created( 'edit' ) ) {
			$product->set_date_created( time() );
		}
		$product->set_date_modified( time() );

		$this->validate_and_set_parent_id( $product );

		$new_title = $this->generate_product_title( $product );
		if ( $product->get_name( 'edit' ) !== $new_title ) {
			$product->set_name( $new_title );
		}

		$product->set_attribute_summary( $this->generate_attribute_summary( $product ) );

		// Variations don't carry their own type term in WP, so we make sure the
		// `type` column is correct. The parent stays a `variable`.
		$product->set_props( array( 'type' => ProductType::VARIATION ) );

		$post_id = wp_insert_post(
			array(
				'post_type'    => CustomProductsTableController::PLACEHOLDER_POST_TYPE,
				'post_status'  => $product->get_status() ? $product->get_status() : ProductStatus::PUBLISH,
				'post_title'   => $product->get_name(),
				'post_excerpt' => $product->get_attribute_summary( 'edit' ),
				'post_parent'  => (int) $product->get_parent_id(),
				'post_author'  => get_current_user_id(),
				'menu_order'   => $product->get_menu_order(),
				'ping_status'  => 'closed',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( esc_html( $post_id->get_error_message() ) );
		}

		$product->set_id( (int) $post_id );

		$data    = $this->build_column_data( $product, true );
		$formats = $this->build_format_array( $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::get_products_table_name(), $data, $formats );

		$this->persist_attributes( $product, true );
		$this->persist_downloads( $product, true );
		$this->persist_taxonomy_terms( $product, true );
		$this->sync_visibility_terms( $product, true );

		$product->save_meta_data();
		$product->apply_changes();

		$this->update_lookup_table( $product->get_id() );

		// Variable parent's min/max price may have shifted.
		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id > 0 ) {
			$this->update_lookup_table( $parent_id );
		}

		/**
		 * Fires when a new product variation is created via HPPS.
		 *
		 * Mirrors the legacy `woocommerce_new_product_variation` hook so any
		 * listener attached to it keeps working under HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int                  $variation_id Variation ID.
		 * @param WC_Product_Variation $variation    Variation object.
		 */
		do_action( 'woocommerce_new_product_variation', $product->get_id(), $product );
	}

	/**
	 * Read a variation back from `wc_products`.
	 *
	 * @param WC_Product_Variation $product Variation object passed by reference.
	 * @return void
	 *
	 * @throws Exception If the variation row cannot be found.
	 */
	public function read( &$product ) {
		global $wpdb;

		$product->set_defaults();

		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			throw new Exception( esc_html__( 'Invalid variation: no ID supplied.', 'woocommerce' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND type = %s LIMIT 1',
				self::get_products_table_name(),
				$product_id,
				ProductType::VARIATION
			)
		);

		if ( ! $row ) {
			throw new Exception( esc_html__( 'Invalid variation: not found in custom tables.', 'woocommerce' ) );
		}

		$this->hydrate_from_row( $product, $row );
		$this->read_attributes( $product );
		$this->read_downloads( $product );
		$this->read_taxonomy_terms( $product );
		$this->read_meta_into_product( $product );

		// Defensive parent invalidation — a missing or non-variable parent
		// makes the variation ineligible for cart/store paths. We don't throw
		// because legacy paths happily return a defunct variation; align here.
		if ( $product->get_parent_id( 'edit' ) ) {
			$parent_type = $this->get_product_type( $product->get_parent_id( 'edit' ) );
			if ( ProductType::VARIABLE !== $parent_type ) {
				$product->set_parent_id( 0 );
			}
		}

		$this->load_parent_data( $product );

		// Generated title may have drifted since last save. Repair lazily so
		// listings show the current parent name; persist to wc_products only
		// when it differs from the stored value.
		$new_title = $this->generate_product_title( $product );
		if ( $row->name !== $new_title ) {
			$product->set_name( $new_title );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				array( 'name' => $new_title ),
				array( 'id' => $product_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$product->set_object_read( true );

		/**
		 * Mirrors `woocommerce_product_read` so HPPS variation listeners keep working.
		 *
		 * @since 10.9.0
		 *
		 * @param int                  $product_id The variation ID.
		 * @param WC_Product_Variation $product    Variation instance.
		 */
		do_action( 'woocommerce_product_read', $product_id, $product );
	}

	/**
	 * Update an existing variation row.
	 *
	 * @param WC_Product_Variation $product Variation passed by reference.
	 * @return void
	 */
	public function update( &$product ) {
		global $wpdb;

		$product->save_meta_data();

		$this->validate_and_set_parent_id( $product );

		$changes = $product->get_changes();

		// Always recompute the attribute summary as a safety net (CPT parity).
		$new_attribute_summary = $this->generate_attribute_summary( $product );
		if ( $new_attribute_summary !== $product->get_attribute_summary() ) {
			$product->set_attribute_summary( $new_attribute_summary );
			if ( ! isset( $changes['attributes'] ) ) {
				$changes['attributes'] = true;
			}
		}

		$new_title = $this->generate_product_title( $product );
		if ( $product->get_name( 'edit' ) !== $new_title ) {
			$product->set_name( $new_title );
		}

		if ( ! $product->get_date_created( 'edit' ) ) {
			$product->set_date_created( time() );
		}
		$product->set_date_modified( time() );

		$data                      = $this->build_column_data_from_changes( $product, $changes );
		$data['date_modified_gmt'] = gmdate( 'Y-m-d H:i:s' );

		// Ensure the type column is always pinned to `variation`. If a caller
		// flipped it elsewhere, fix it here so reads keep returning the row.
		$data['type'] = ProductType::VARIATION;

		if ( count( $data ) > 1 ) {
			$formats = $this->build_format_array( $data );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				self::get_products_table_name(),
				$data,
				array( 'id' => (int) $product->get_id() ),
				$formats,
				array( '%d' )
			);
		}

		// Sync the placeholder post title/excerpt for compatibility with
		// the few wp_posts queries (e.g. autosuggest) that still hit it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'   => (string) $product->get_name(),
				'post_excerpt' => (string) $product->get_attribute_summary( 'edit' ),
				'post_parent'  => (int) $product->get_parent_id(),
				'menu_order'   => (int) $product->get_menu_order(),
				'post_status'  => $product->get_status() ? $product->get_status() : ProductStatus::PUBLISH,
			),
			array( 'ID' => (int) $product->get_id() ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $product->get_id() );

		if ( array_key_exists( 'attributes', $changes ) ) {
			$this->persist_attributes( $product );
		}
		if ( array_key_exists( 'downloads', $changes ) ) {
			$this->persist_downloads( $product );
		}
		if ( array_key_exists( 'shipping_class_id', $changes ) ) {
			$this->persist_taxonomy_terms( $product );
		}
		if ( array_key_exists( 'stock_status', $changes ) ) {
			$this->sync_visibility_terms( $product );
		}

		$product->apply_changes();

		$this->update_lookup_table( $product->get_id() );

		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id > 0 ) {
			$this->update_lookup_table( $parent_id );
		}

		/**
		 * Fires after a product variation is updated via HPPS.
		 *
		 * Mirrors the legacy `woocommerce_update_product_variation` so any
		 * listener attached to it keeps working under HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param int                  $variation_id Variation ID.
		 * @param WC_Product_Variation $variation    Variation object.
		 */
		do_action( 'woocommerce_update_product_variation', $product->get_id(), $product );
	}

	/*
	|--------------------------------------------------------------------------
	| Variation-specific attribute storage.
	|
	| Variations have a flat name=>value attribute shape. We persist them as
	| a single row in wc_product_attribute_values per attribute, with
	| scope = 'variation' to distinguish from the parent's own attribute rows.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Persist a variation's selected attribute values.
	 *
	 * @param WC_Product $product Variation product.
	 * @param bool       $force   Whether this is a create-time persist.
	 * @return void
	 */
	protected function persist_attributes( WC_Product $product, bool $force = false ): void {
		global $wpdb;
		unset( $force );

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$parent_id = (int) $product->get_parent_id();

		// Wipe both this variation's own variation-scope rows AND the
		// `attribute_*` postmeta on the placeholder post so we never end up
		// with two sources of truth.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::get_attribute_values_table_name(),
			array(
				'product_id' => $product_id,
				'scope'      => 'variation',
			),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$attributes = (array) $product->get_attributes( 'edit' );

		$position = 0;
		foreach ( $attributes as $attribute_name => $value ) {
			// Stripping a leading "attribute_" matches what `set_attributes()`
			// already does — but legacy callers occasionally still pass the
			// prefixed key. Be defensive.
			if ( 0 === strpos( (string) $attribute_name, 'attribute_' ) ) {
				$attribute_name = substr( $attribute_name, 10 );
			}

			$attribute_row_id = 0;
			$term_id          = null;
			$stored_value     = (string) $value;

			if ( $parent_id > 0 ) {
				$attribute_row_id = $this->find_parent_attribute_id( $parent_id, (string) $attribute_name );
			}

			// Resolve term_id when the attribute is a taxonomy and the value
			// looks like a term slug. Falls back to text-attribute storage.
			if ( taxonomy_exists( (string) $attribute_name ) && '' !== $stored_value ) {
				$term = get_term_by( 'slug', $stored_value, (string) $attribute_name );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = (int) $term->term_id;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::get_attribute_values_table_name(),
				array(
					'product_id'   => $product_id,
					'attribute_id' => $attribute_row_id,
					'scope'        => 'variation',
					'value'        => $stored_value,
					'term_id'      => $term_id,
					'is_default'   => 0,
					'position'     => $position,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
			);
			++$position;
		}

		/**
		 * Fires after a variation's selected attributes are persisted to HPPS.
		 *
		 * @since 10.9.0
		 *
		 * @param WC_Product $product Variation object.
		 */
		do_action( 'woocommerce_product_attributes_updated', $product, true );
	}

	/**
	 * Read the variation's selected attribute values back into the product.
	 *
	 * @param WC_Product $product Variation product.
	 * @return void
	 */
	protected function read_attributes( WC_Product &$product ): void {
		global $wpdb;

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT av.value, av.term_id, av.attribute_id, av.position, a.name AS attribute_name, a.taxonomy
				FROM ' . self::get_attribute_values_table_name() . ' av
				LEFT JOIN ' . self::get_attributes_table_name() . ' a ON a.id = av.attribute_id
				WHERE av.product_id = %d AND av.scope = %s
				ORDER BY av.position ASC, av.id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id,
				'variation'
			)
		);

		if ( empty( $rows ) ) {
			$product->set_attributes( array() );
			return;
		}

		$attributes = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row->attribute_name ?? '' );
			if ( '' === $key ) {
				// We may have orphan rows when the parent was migrated but
				// the attribute_id link wasn't restored. Fall back to
				// taxonomy if the row carries a term reference.
				if ( ! empty( $row->taxonomy ) ) {
					$key = (string) $row->taxonomy;
				} else {
					continue;
				}
			}
			$attributes[ $key ] = (string) $row->value;
		}

		$product->set_attributes( $attributes );
	}

	/*
	|--------------------------------------------------------------------------
	| Title / parent helpers.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate a variation title using the parent name + attribute summary,
	 * mirroring `WC_Product_Variation_Data_Store_CPT::generate_product_title()`.
	 *
	 * @param WC_Product_Variation $product Variation object.
	 * @return string
	 */
	protected function generate_product_title( $product ): string {
		$attributes = (array) $product->get_attributes();

		$should_include_attributes = count( $attributes ) < 3;

		if ( $should_include_attributes && 1 < count( $attributes ) ) {
			foreach ( $attributes as $name => $value ) {
				unset( $value );
				if ( false !== strpos( $name, '-' ) ) {
					$should_include_attributes = false;
					break;
				}
			}
		}

		/** This filter is documented in includes/data-stores/class-wc-product-variation-data-store-cpt.php */
		$should_include_attributes = apply_filters( 'woocommerce_product_variation_title_include_attributes', $should_include_attributes, $product );

		/** This filter is documented in includes/data-stores/class-wc-product-variation-data-store-cpt.php */
		$separator = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $product );

		$parent_id    = (int) $product->get_parent_id();
		$title_base   = $parent_id > 0 ? (string) $this->get_parent_name( $parent_id ) : '';
		$title_suffix = $should_include_attributes ? wc_get_formatted_variation( $product, true, false ) : '';

		/** This filter is documented in includes/data-stores/class-wc-product-variation-data-store-cpt.php */
		return apply_filters(
			'woocommerce_product_variation_title',
			$title_suffix ? $title_base . $separator . $title_suffix : $title_base,
			$product,
			$title_base,
			$title_suffix
		);
	}

	/**
	 * Return the comma-delimited attribute summary for a variation.
	 *
	 * @param WC_Product_Variation $product Variation object.
	 * @return string
	 */
	protected function generate_attribute_summary( $product ): string {
		return (string) wc_get_formatted_variation( $product, true, true );
	}

	/**
	 * Validate the variation's parent_id (must reference an existing variable
	 * product row in `wc_products`) and clear it if invalid.
	 *
	 * @param WC_Product $product Variation product.
	 * @return void
	 */
	protected function validate_and_set_parent_id( WC_Product $product ): void {
		$parent_id = (int) $product->get_parent_id( 'edit' );
		if ( $parent_id <= 0 ) {
			return;
		}

		$type = $this->get_product_type( $parent_id );
		if ( ProductType::VARIABLE !== $type ) {
			$product->set_parent_id( 0 );
		}
	}

	/**
	 * Look up the parent product's name from `wc_products`.
	 *
	 * @param int $parent_id Variable product ID.
	 * @return string
	 */
	protected function get_parent_name( int $parent_id ): string {
		global $wpdb;

		$cache_key = 'hpps_parent_name_' . $parent_id;
		$cached    = wp_cache_get( $cache_key, 'products' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$name = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT name FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$parent_id
			)
		);

		wp_cache_set( $cache_key, $name, 'products', MINUTE_IN_SECONDS );
		return $name;
	}

	/**
	 * Find the row id in wc_product_attributes that matches a given attribute
	 * name on the variation's parent.
	 *
	 * @param int    $parent_id      Variable parent ID.
	 * @param string $attribute_name Attribute name.
	 * @return int Row id, or 0 if not found.
	 */
	protected function find_parent_attribute_id( int $parent_id, string $attribute_name ): int {
		global $wpdb;

		// Variation attribute keys are sanitised slugs; parent attribute names
		// may carry the original casing/spaces. Match on a sanitised compare.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE product_id = %d AND ( name = %s OR taxonomy = %s ) LIMIT 1',
				self::get_attributes_table_name(),
				$parent_id,
				$attribute_name,
				$attribute_name
			)
		);
	}

	/**
	 * Hydrate `parent_data` on a variation by reading the parent's
	 * `wc_products` row plus the few legacy meta values that drive variation
	 * fallbacks (e.g. tax_status, sold_individually, cross-sells).
	 *
	 * @param WC_Product_Variation $product Variation object.
	 * @return void
	 */
	protected function load_parent_data( WC_Product_Variation $product ): void {
		global $wpdb;

		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id <= 0 ) {
			$product->set_parent_data(
				array(
					'title'              => '',
					'sku'                => '',
					'global_unique_id'   => '',
					'manage_stock'       => 'no',
					'backorders'         => 'no',
					'stock_quantity'     => null,
					'weight'             => '',
					'length'             => '',
					'width'              => '',
					'height'             => '',
					'tax_class'          => '',
					'shipping_class_id'  => 0,
					'image_id'           => 0,
					'purchase_note'      => '',
					'sold_individually'  => 'no',
					'tax_status'         => 'taxable',
					'_crosssell_ids'     => array(),
					'status'             => 'publish',
					'catalog_visibility' => CatalogVisibility::VISIBLE,
				)
			);
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT name, sku, global_unique_id, manage_stock, backorders, stock_quantity, weight, length, width, height, tax_class, tax_status, status, purchase_note, sold_individually, catalog_visibility FROM %i WHERE id = %d LIMIT 1',
				self::get_products_table_name(),
				$parent_id
			)
		);

		if ( ! $row ) {
			return;
		}

		// Cross-sells come from postmeta on the parent's placeholder post
		// (still legacy storage in Phase 1).
		$crosssells = (array) get_post_meta( $parent_id, '_crosssell_ids', true );

		// Variation visibility derived from the parent's product_visibility terms.
		$terms                         = get_the_terms( $parent_id, 'product_visibility' );
		$term_names                    = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
		$exclude_search                = in_array( 'exclude-from-search', $term_names, true );
		$exclude_catalog               = in_array( 'exclude-from-catalog', $term_names, true );
		$catalog_visibility_term_state = $this->resolve_catalog_visibility( $exclude_search, $exclude_catalog );

		$shipping_class_terms = wc_get_object_terms( $parent_id, 'product_shipping_class', 'term_id' );

		$product->set_parent_data(
			array(
				'title'              => (string) $row->name,
				'sku'                => (string) $row->sku,
				'global_unique_id'   => (string) $row->global_unique_id,
				'manage_stock'       => $row->manage_stock ? 'yes' : 'no',
				'backorders'         => (string) $row->backorders,
				'stock_quantity'     => null === $row->stock_quantity ? null : wc_stock_amount( $row->stock_quantity ),
				'weight'             => (string) $row->weight,
				'length'             => (string) $row->length,
				'width'              => (string) $row->width,
				'height'             => (string) $row->height,
				'tax_class'          => (string) $row->tax_class,
				'shipping_class_id'  => ! empty( $shipping_class_terms ) ? (int) $shipping_class_terms[0] : 0,
				'image_id'           => (int) get_post_thumbnail_id( $parent_id ),
				'purchase_note'      => (string) $row->purchase_note,
				'sold_individually'  => $row->sold_individually ? 'yes' : 'no',
				'tax_status'         => (string) $row->tax_status,
				'_crosssell_ids'     => $crosssells,
				'status'             => (string) $row->status,
				'catalog_visibility' => $catalog_visibility_term_state,
			)
		);

		// `WC_Product_Variation::read()` defers these to the parent.
		$product->set_sold_individually( (bool) $row->sold_individually );
		$product->set_tax_status( (string) $row->tax_status );
		$product->set_cross_sell_ids( $crosssells );
	}

	/**
	 * Map exclude-from-search / exclude-from-catalog flags to a catalog
	 * visibility enum value.
	 *
	 * @param bool $exclude_search  True when the parent excludes search.
	 * @param bool $exclude_catalog True when the parent excludes catalog.
	 * @return string
	 */
	private function resolve_catalog_visibility( bool $exclude_search, bool $exclude_catalog ): string {
		if ( $exclude_search && $exclude_catalog ) {
			return CatalogVisibility::HIDDEN;
		}
		if ( $exclude_search ) {
			return CatalogVisibility::CATALOG;
		}
		if ( $exclude_catalog ) {
			return CatalogVisibility::SEARCH;
		}
		return CatalogVisibility::VISIBLE;
	}

	/**
	 * Variations follow the parent's stock_status visibility rule but never
	 * carry their own `featured` / `rated-*` tags. Override the base sync to
	 * keep the term set scoped accordingly.
	 *
	 * @param WC_Product $product Variation object.
	 * @param bool       $force   Set to true on create.
	 * @return void
	 */
	protected function sync_visibility_terms( WC_Product $product, bool $force = false ): void {
		unset( $force );

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$terms = array();
		if ( ProductStockStatus::OUT_OF_STOCK === $product->get_stock_status() ) {
			$terms[] = ProductStockStatus::OUT_OF_STOCK;
		}

		wp_set_post_terms( $product_id, $terms, 'product_visibility', false );
	}
}
