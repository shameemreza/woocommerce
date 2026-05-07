<?php
/**
 * High-Performance Product Storage query translator.
 *
 * Builds and executes SQL against the HPPS `wc_products` table from the
 * same query-vars shape `WC_Product_Query` hands to the data store
 * (i.e. the contract `WC_Product_Data_Store_CPT::query()` consumes).
 *
 * @package WooCommerce\Internal\DataStores\Products
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\DataStores\Products;

defined( 'ABSPATH' ) || exit;

/**
 * HPPS-native query.
 *
 * The legacy CPT data store answers `wc_get_products()` by translating
 * the args into a `WP_Query` against `wp_posts` + `wp_postmeta`. On an
 * HPPS-enabled store that's wasted work — the column store already
 * holds the values the query is filtering on, so we can run a single
 * SELECT against `wc_products` and skip the postmeta JOIN hell.
 *
 * This class deliberately covers the "boring" half of the query
 * surface: column-mappable filters (status, type, sku, price, stock,
 * etc.) plus pagination and a curated orderby list. Anything that
 * needs taxonomy joins (`category`, `tag`, `shipping_class`),
 * arbitrary `meta_query` / `tax_query` clauses, full-text search
 * (`s`), date queries, or `reviews_allowed` falls back to the legacy
 * data store via {@see is_supported()}. That fallback keeps the
 * feature correct from day one while we incrementally migrate paths
 * over to HPPS-native handling.
 *
 * @see ProductsTableDataStore::query() for the entry point.
 * @see WC_Product_Data_Store_CPT::query() for the legacy contract.
 *
 * @internal For exclusive usage of WooCommerce core.
 *
 * @since 10.9.0
 */
class ProductsTableQuery {

	/**
	 * Map of supported query vars to the matching `wc_products` column
	 * and the value type used for parameter binding / coercion.
	 *
	 * Order matches the public `WC_Product_Query::get_default_query_vars()`
	 * surface so it's easy to scan for additions.
	 *
	 * `comparator` is the SQL operator used when the value is a single
	 * scalar. Arrays always use `IN`. `coerce` selects the value
	 * normaliser; `format` is the wpdb format specifier.
	 *
	 * @var array<string, array{column:string,type:string,format:string,coerce:string,comparator?:string}>
	 */
	private const COLUMN_MAP = array(
		'sku'               => array(
			'column'     => 'sku',
			'type'       => 'string',
			'format'     => '%s',
			'coerce'     => 'string',
			'comparator' => 'LIKE',
		),
		'price'             => array(
			'column' => 'price',
			'type'   => 'decimal',
			'format' => '%s',
			'coerce' => 'decimal',
		),
		'regular_price'     => array(
			'column' => 'regular_price',
			'type'   => 'decimal',
			'format' => '%s',
			'coerce' => 'decimal',
		),
		'sale_price'        => array(
			'column' => 'sale_price',
			'type'   => 'decimal',
			'format' => '%s',
			'coerce' => 'decimal',
		),
		'total_sales'       => array(
			'column' => 'total_sales',
			'type'   => 'int',
			'format' => '%d',
			'coerce' => 'int',
		),
		'tax_status'        => array(
			'column' => 'tax_status',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'tax_class'         => array(
			'column' => 'tax_class',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'manage_stock'      => array(
			'column' => 'manage_stock',
			'type'   => 'bool',
			'format' => '%d',
			'coerce' => 'bool',
		),
		'stock_quantity'    => array(
			'column' => 'stock_quantity',
			'type'   => 'decimal',
			'format' => '%s',
			'coerce' => 'decimal',
		),
		'stock_status'      => array(
			'column' => 'stock_status',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'backorders'        => array(
			'column' => 'backorders',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'low_stock_amount'  => array(
			'column' => 'low_stock_amount',
			'type'   => 'int',
			'format' => '%d',
			'coerce' => 'int',
		),
		'sold_individually' => array(
			'column' => 'sold_individually',
			'type'   => 'bool',
			'format' => '%d',
			'coerce' => 'bool',
		),
		'weight'            => array(
			'column' => 'weight',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'length'            => array(
			'column' => 'length',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'width'             => array(
			'column' => 'width',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'height'            => array(
			'column' => 'height',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'virtual'           => array(
			'column' => 'virtual',
			'type'   => 'bool',
			'format' => '%d',
			'coerce' => 'bool',
		),
		'downloadable'      => array(
			'column' => 'downloadable',
			'type'   => 'bool',
			'format' => '%d',
			'coerce' => 'bool',
		),
		'featured'          => array(
			'column' => 'featured',
			'type'   => 'bool',
			'format' => '%d',
			'coerce' => 'bool',
		),
		'visibility'        => array(
			'column' => 'catalog_visibility',
			'type'   => 'string',
			'format' => '%s',
			'coerce' => 'string',
		),
		'download_limit'    => array(
			'column' => 'download_limit',
			'type'   => 'int',
			'format' => '%d',
			'coerce' => 'int',
		),
		'download_expiry'   => array(
			'column' => 'download_expiry',
			'type'   => 'int',
			'format' => '%d',
			'coerce' => 'int',
		),
		'average_rating'    => array(
			'column' => 'average_rating',
			'type'   => 'decimal',
			'format' => '%s',
			'coerce' => 'decimal',
		),
		'review_count'      => array(
			'column' => 'rating_count',
			'type'   => 'int',
			'format' => '%d',
			'coerce' => 'int',
		),
	);

	/**
	 * `orderby` query-var values we know how to translate into a column
	 * sort. `include` (alias `post__in`) is handled separately because
	 * it relies on `FIELD()` against the include list.
	 *
	 * @var array<string, string>
	 */
	private const ORDERBY_MAP = array(
		'id'             => 'id',
		'ID'             => 'id',
		'name'           => 'name',
		'title'          => 'name',
		'sku'            => 'sku',
		'price'          => 'price',
		'regular_price'  => 'regular_price',
		'sale_price'     => 'sale_price',
		'total_sales'    => 'total_sales',
		'popularity'     => 'total_sales',
		'rating'         => 'average_rating',
		'average_rating' => 'average_rating',
		'stock_quantity' => 'stock_quantity',
		'menu_order'     => 'menu_order',
		'date'           => 'date_created',
		'date_created'   => 'date_created',
		'modified'       => 'date_modified',
		'date_modified'  => 'date_modified',
	);

	/**
	 * Query-var keys that, when present and non-empty, force a fallback
	 * to the legacy CPT data store. Keep this list narrow; anything
	 * worth migrating to HPPS-native handling moves out of here.
	 *
	 * @var string[]
	 */
	private const UNSUPPORTED_KEYS = array(
		'meta_query',
		'tax_query',
		'date_query',
		'date_created',
		'date_modified',
		'date_on_sale_from',
		'date_on_sale_to',
		'reviews_allowed',
		's',
	);

	/**
	 * Taxonomy filters HPPS handles natively via EXISTS subqueries on
	 * `wp_term_relationships`. The slug form takes precedence over the
	 * ID form when both are present (matches the legacy translator's
	 * contract, which also gives `category` priority over
	 * `product_category_id`).
	 *
	 * @var array<string, array{taxonomy:string,field:string,fallback_for?:string}>
	 */
	private const TAXONOMY_FILTERS = array(
		'category'            => array(
			'taxonomy' => 'product_cat',
			'field'    => 'slug',
		),
		'product_category_id' => array(
			'taxonomy'     => 'product_cat',
			'field'        => 'id',
			'fallback_for' => 'category',
		),
		'tag'                 => array(
			'taxonomy' => 'product_tag',
			'field'    => 'slug',
		),
		'product_tag_id'      => array(
			'taxonomy'     => 'product_tag',
			'field'        => 'id',
			'fallback_for' => 'tag',
		),
		'shipping_class'      => array(
			'taxonomy' => 'product_shipping_class',
			'field'    => 'slug',
		),
	);

	/**
	 * Original query vars handed to the constructor. Never mutated.
	 *
	 * @var array<string, mixed>
	 */
	private array $query_vars;

	/**
	 * `wc_products` table name (resolved once for SQL building).
	 *
	 * @var string
	 */
	private string $table_name;

	public function __construct( array $query_vars ) {
		$this->query_vars = $query_vars;
		$this->table_name = ProductsTableDataStore::get_products_table_name();
	}

	/**
	 * Whether the HPPS-native path can answer this query, or whether
	 * the caller should fall back to the legacy CPT data store.
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		foreach ( self::UNSUPPORTED_KEYS as $key ) {
			if ( ! empty( $this->query_vars[ $key ] ) ) {
				return false;
			}
		}

		// `name` query-var (single value) is supported via a JOIN to
		// `wp_posts` to read post_title. We treat empty / missing as a
		// no-op. Wildcard SKUs ('*') are handled in clause building.
		return true;
	}

	/**
	 * Execute the query and return results in the shape
	 * {@see WC_Product_Data_Store_CPT::query()} produces:
	 *
	 *   - `return = 'ids'`   → array of int IDs.
	 *   - `return = 'objects'` → array of WC_Product instances.
	 *   - `paginate = true`  → object { products, total, max_num_pages }.
	 *
	 * @return array<int, int>|array<int, \WC_Product>|object
	 */
	public function get_results() {
		global $wpdb;

		$paginate = ! empty( $this->query_vars['paginate'] );
		$return   = $this->query_vars['return'] ?? 'objects';
		$limit    = $this->resolve_limit();
		$offset   = $this->resolve_offset( $limit );

		$where_parts = array();
		$where_args  = array();
		$joins       = array();

		$this->build_column_filters( $where_parts, $where_args );
		$this->build_status_filter( $where_parts, $where_args );
		$this->build_type_filter( $where_parts, $where_args );
		$this->build_id_filters( $where_parts, $where_args );
		$this->build_parent_filters( $where_parts, $where_args );
		$this->build_name_filter( $where_parts, $where_args, $joins );
		$this->build_taxonomy_filters( $where_parts, $where_args );

		$where_sql = empty( $where_parts ) ? '1=1' : implode( ' AND ', $where_parts );

		// Always include all rows by default; status defaulting is the
		// caller's responsibility (WC_Product_Query supplies a default).
		// `build_order_sql` may push joins (e.g. `wc_product_meta_lookup`
		// for price ordering); collect them before assembling $join_sql.
		$order_sql = $this->build_order_sql( $joins );
		$join_sql  = empty( $joins ) ? '' : ' ' . implode( ' ', $joins );
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d, %d', $offset, $limit ) : '';

		$id_sql = "SELECT p.id FROM {$this->table_name} AS p{$join_sql} WHERE {$where_sql}{$order_sql}{$limit_sql}";

		// `prepare()` only supports placeholders inside the format
		// string itself, so we stitch the prepared $where + values
		// together before passing into get_col(). The order_by /
		// limit fragments are already prepared individually.
		$prepared_id_sql = empty( $where_args )
			? $id_sql
			: $wpdb->prepare( $id_sql, ...$where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'intval', (array) $wpdb->get_col( $prepared_id_sql ) );

		$total         = 0;
		$max_num_pages = 0;
		if ( $paginate ) {
			$count_sql = "SELECT COUNT(*) FROM {$this->table_name} AS p{$join_sql} WHERE {$where_sql}";
			$prepared_count_sql = empty( $where_args )
				? $count_sql
				: $wpdb->prepare( $count_sql, ...$where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $prepared_count_sql );

			$max_num_pages = $limit > 0 ? (int) ceil( $total / max( 1, $limit ) ) : 0;
		}

		if ( 'ids' === $return ) {
			$products = $ids;
		} else {
			$products = array();
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$products[] = $product;
				}
			}
		}

		if ( $paginate ) {
			return (object) array(
				'products'      => $products,
				'total'         => $total,
				'max_num_pages' => $max_num_pages,
			);
		}

		return $products;
	}

	/**
	 * Resolve `limit` from the query vars: WC default is the
	 * `posts_per_page` option, but a caller can pass `-1` to request
	 * "no limit".
	 *
	 * @return int 0 means no LIMIT.
	 */
	private function resolve_limit(): int {
		$limit = $this->query_vars['limit'] ?? -1;
		$limit = (int) $limit;
		return $limit < 0 ? 0 : $limit;
	}

	/**
	 * Resolve offset, honouring `page` over `offset` so paginated
	 * callers behave the same as on the legacy CPT path.
	 *
	 * @param int $limit Resolved limit.
	 * @return int
	 */
	private function resolve_offset( int $limit ): int {
		$offset = $this->query_vars['offset'] ?? '';
		if ( '' !== $offset ) {
			return max( 0, (int) $offset );
		}

		$page = (int) ( $this->query_vars['page'] ?? 1 );
		if ( $page > 1 && $limit > 0 ) {
			return ( $page - 1 ) * $limit;
		}

		return 0;
	}

	/**
	 * Walk {@see COLUMN_MAP} and append a clause for every present and
	 * non-empty query var. Bool / int / decimal / string coercion lives
	 * in {@see coerce_value()}.
	 *
	 * @param string[] $where_parts Output: list of `WHERE` fragments.
	 * @param mixed[]  $where_args  Output: positional args for `prepare()`.
	 */
	private function build_column_filters( array &$where_parts, array &$where_args ): void {
		foreach ( self::COLUMN_MAP as $key => $config ) {
			if ( ! isset( $this->query_vars[ $key ] ) || '' === $this->query_vars[ $key ] || array() === $this->query_vars[ $key ] ) {
				continue;
			}

			$raw    = $this->query_vars[ $key ];
			$column = $config['column'];

			// SKU has special semantics: '*' means "any non-empty SKU"
			// (matches the legacy CPT data store's '*' contract).
			if ( 'sku' === $key && '*' === $raw ) {
				$where_parts[] = "p.{$column} <> ''";
				continue;
			}

			if ( is_array( $raw ) ) {
				$values = array_map( fn( $v ) => $this->coerce_value( $config['coerce'], $v ), $raw );
				$placeholders = implode( ', ', array_fill( 0, count( $values ), $config['format'] ) );
				$where_parts[] = "p.{$column} IN ({$placeholders})";
				array_push( $where_args, ...$values );
				continue;
			}

			$value = $this->coerce_value( $config['coerce'], $raw );

			if ( 'sku' === $key ) {
				// Escape SQL LIKE meta-characters so an `_` in a SKU like
				// `ABC_123` doesn't accidentally match every other SKU of
				// the same length. The README documents `*` as the only
				// supported wildcard (handled above), so any literal `_`
				// or `%` in the user's SKU is data, not a pattern.
				global $wpdb;
				$where_parts[] = "p.{$column} LIKE {$config['format']}";
				$where_args[]  = $wpdb->esc_like( (string) $value );
				continue;
			}

			$comparator    = $config['comparator'] ?? '=';
			$where_parts[] = "p.{$column} {$comparator} {$config['format']}";
			$where_args[]  = $value;
		}
	}

	/**
	 * `status` accepts a string or array. Defaults are supplied by
	 * `WC_Product_Query`, so we can rely on this being non-empty.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 */
	private function build_status_filter( array &$where_parts, array &$where_args ): void {
		$status = $this->query_vars['status'] ?? '';
		if ( '' === $status || array() === $status ) {
			return;
		}

		$values       = array_map( 'strval', (array) $status );
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
		$where_parts[] = "p.status IN ({$placeholders})";
		array_push( $where_args, ...$values );
	}

	/**
	 * `type` accepts a string or array of product type slugs. HPPS
	 * keeps the type in a native column, so this is a direct match —
	 * no `product_type` taxonomy round-trip.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 */
	private function build_type_filter( array &$where_parts, array &$where_args ): void {
		$type = $this->query_vars['type'] ?? '';
		if ( '' === $type || array() === $type ) {
			return;
		}

		$values       = array_map( 'strval', (array) $type );
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
		$where_parts[] = "p.type IN ({$placeholders})";
		array_push( $where_args, ...$values );
	}

	/**
	 * `include` / `exclude` map to `id IN (...)` / `id NOT IN (...)`.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 */
	private function build_id_filters( array &$where_parts, array &$where_args ): void {
		foreach ( array( 'include' => 'IN', 'exclude' => 'NOT IN' ) as $key => $operator ) {
			$ids = $this->query_vars[ $key ] ?? array();
			if ( empty( $ids ) ) {
				continue;
			}

			$ids = array_filter( array_map( 'intval', (array) $ids ) );
			if ( empty( $ids ) ) {
				continue;
			}

			$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$where_parts[] = "p.id {$operator} ({$placeholders})";
			array_push( $where_args, ...$ids );
		}
	}

	/**
	 * `parent` / `parent_exclude` map to `parent_id` clauses. `parent`
	 * accepts a single ID or array; `parent_exclude` accepts an array.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 */
	private function build_parent_filters( array &$where_parts, array &$where_args ): void {
		$parent = $this->query_vars['parent'] ?? '';
		if ( '' !== $parent && array() !== $parent ) {
			$ids = array_map( 'intval', (array) $parent );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$where_parts[] = "p.parent_id IN ({$placeholders})";
			array_push( $where_args, ...$ids );
		}

		$parent_exclude = $this->query_vars['parent_exclude'] ?? array();
		if ( ! empty( $parent_exclude ) ) {
			$ids = array_map( 'intval', (array) $parent_exclude );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$where_parts[] = "p.parent_id NOT IN ({$placeholders})";
			array_push( $where_args, ...$ids );
		}
	}

	/**
	 * `name` matches `wp_posts.post_title` exactly. Requires a JOIN
	 * because HPPS keeps the canonical product name in `wc_products.name`
	 * but legacy callers commonly pass the WP post title; we match
	 * on either to keep behaviour predictable across migrated and
	 * unmigrated stores.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 * @param string[] $joins       Output: list of JOIN fragments.
	 */
	private function build_name_filter( array &$where_parts, array &$where_args, array &$joins ): void {
		global $wpdb;

		$name = $this->query_vars['name'] ?? '';
		if ( '' === $name ) {
			return;
		}

		// Constrain the join to product-shaped post types so a foreign post
		// id that happens to collide with a wc_products.id never satisfies
		// the predicate. (`product_placeholder` is HPPS's non-public CPT;
		// `product` is the legacy/migrated row; `product_variation` is the
		// child variation post.)
		$joins[]       = "INNER JOIN {$wpdb->posts} AS posts ON posts.ID = p.id AND posts.post_type IN ('product','product_placeholder','product_variation')";
		$where_parts[] = '( p.name = %s OR posts.post_title = %s )';
		$where_args[]  = (string) $name;
		$where_args[]  = (string) $name;
	}

	/**
	 * Emit one EXISTS subquery against `wp_term_relationships` per
	 * present taxonomy filter ({@see TAXONOMY_FILTERS}). Multiple
	 * filters AND together; multiple values within a single filter
	 * OR via `IN`, matching the legacy `tax_query` `IN` semantics.
	 *
	 * The slug form takes precedence over the ID form: if `category`
	 * is set, `product_category_id` is ignored even when also present.
	 *
	 * If a filter resolves to zero matching `term_taxonomy_id`s (the
	 * caller passed slugs / IDs that don't exist), we short-circuit
	 * the entire WHERE with `1=0` so the query returns no rows. That
	 * matches what `WP_Query` does on an empty `tax_query` term list.
	 *
	 * @param string[] $where_parts Output.
	 * @param mixed[]  $where_args  Output.
	 */
	private function build_taxonomy_filters( array &$where_parts, array &$where_args ): void {
		global $wpdb;

		foreach ( self::TAXONOMY_FILTERS as $key => $config ) {
			if ( empty( $this->query_vars[ $key ] ) ) {
				continue;
			}

			// Slug form already populated this taxonomy — skip the
			// id-based fallback so we don't double-filter.
			if ( isset( $config['fallback_for'] ) && ! empty( $this->query_vars[ $config['fallback_for'] ] ) ) {
				continue;
			}

			$term_taxonomy_ids = $this->resolve_term_taxonomy_ids(
				$config['taxonomy'],
				(array) $this->query_vars[ $key ],
				$config['field']
			);

			if ( empty( $term_taxonomy_ids ) ) {
				$where_parts[] = '1=0';
				return;
			}

			$placeholders  = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
			$where_parts[] = "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} AS tr_{$config['taxonomy']} WHERE tr_{$config['taxonomy']}.object_id = p.id AND tr_{$config['taxonomy']}.term_taxonomy_id IN ({$placeholders}) )";
			array_push( $where_args, ...$term_taxonomy_ids );
		}
	}

	/**
	 * Resolve a list of term slugs or IDs into matching
	 * `term_taxonomy_id`s for a given taxonomy.
	 *
	 * Routes through `get_terms()` (rather than rolling our own SQL)
	 * so callers that have set up filters / fixtures (test factories,
	 * multilingual plugins, taxonomy term overrides) keep getting the
	 * same resolution they would on the legacy CPT path.
	 *
	 * @param string            $taxonomy Taxonomy slug.
	 * @param array<int, mixed> $values   Slugs or IDs.
	 * @param string            $field    Either `'slug'` or `'id'`.
	 * @return int[] List of `term_taxonomy_id` values.
	 */
	private function resolve_term_taxonomy_ids( string $taxonomy, array $values, string $field ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		if ( 'slug' === $field ) {
			$slugs = array_filter( array_map( 'strval', $values ) );
			if ( empty( $slugs ) ) {
				return array();
			}
			$args['slug'] = $slugs;
		} else {
			$ids = array_filter( array_map( 'intval', $values ) );
			if ( empty( $ids ) ) {
				return array();
			}
			$args['include'] = $ids;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[] = (int) $term->term_taxonomy_id;
			}
		}
		return $out;
	}

	/**
	 * Build the ORDER BY clause, honouring `orderby` and `order`.
	 * Falls back to `id DESC` when nothing is supplied or the value
	 * isn't in {@see ORDERBY_MAP}.
	 *
	 * @return string SQL fragment, with leading space.
	 */
	private function build_order_sql( array &$joins = array() ): string {
		global $wpdb;

		$order = strtoupper( (string) ( $this->query_vars['order'] ?? 'DESC' ) );
		$order = ( 'ASC' === $order || 'DESC' === $order ) ? $order : 'DESC';

		$orderby = $this->query_vars['orderby'] ?? 'date';

		// `orderby = 'include'` (or 'post__in') preserves the order of
		// the include list. Surface it via FIELD() — same semantics as
		// the legacy WP_Query path.
		if ( in_array( $orderby, array( 'include', 'post__in' ), true ) ) {
			$ids = array_filter( array_map( 'intval', (array) ( $this->query_vars['include'] ?? array() ) ) );
			if ( ! empty( $ids ) ) {
				return ' ORDER BY FIELD( p.id, ' . implode( ', ', $ids ) . ' )';
			}
			return ' ORDER BY p.id ' . $order;
		}

		if ( 'none' === $orderby ) {
			return '';
		}

		$column = self::ORDERBY_MAP[ $orderby ] ?? null;
		if ( null === $column ) {
			// Unknown orderby — fall back to ID. Callers that need the
			// legacy WP_Query orderby semantics (e.g. `meta_value_num`)
			// won't reach this method because they'll have unsupported
			// args that route through the CPT fallback.
			return ' ORDER BY p.id ' . $order;
		}

		// Variable parents don't carry their own price in `wc_products.price`
		// (the column is empty; the min/max live on the variations and are
		// aggregated into `wc_product_meta_lookup`). Sorting by `p.price`
		// would scatter all variable products to the bottom (or top) of the
		// list with NULL prices. Route price/sale_price/regular_price ordering
		// through the lookup table so variable parents get a sensible value
		// alongside simple/external products. `COALESCE` falls back to the
		// row column for HPPS rows that don't yet have a lookup entry
		// (e.g. mid-migration), preserving the previous behaviour.
		if ( in_array( $orderby, array( 'price', 'sale_price', 'regular_price' ), true ) ) {
			$joins[] = "LEFT JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON lookup.product_id = p.id";
			$lookup_column = ( 'sale_price' === $orderby || 'regular_price' === $orderby ) ? $orderby : ( 'ASC' === $order ? 'min_price' : 'max_price' );
			return " ORDER BY COALESCE( lookup.{$lookup_column}, p.{$column} ) {$order}";
		}

		return " ORDER BY p.{$column} {$order}";
	}

	/**
	 * Coerce a single scalar value to the shape `wc_products`
	 * expects, matching the convention {@see ProductDataSyncListener::coerce_for_column()}
	 * uses on the postmeta side.
	 *
	 * @param string $type      Type tag from {@see COLUMN_MAP}.
	 * @param mixed  $raw_value Raw value from the query var.
	 * @return mixed Coerced value, suitable for binding via wpdb->prepare.
	 */
	private function coerce_value( string $type, $raw_value ) {
		switch ( $type ) {
			case 'bool':
				if ( '' === $raw_value || null === $raw_value ) {
					return 0;
				}
				if ( is_bool( $raw_value ) ) {
					return $raw_value ? 1 : 0;
				}
				return ( 'yes' === $raw_value || '1' === (string) $raw_value || 1 === $raw_value ) ? 1 : 0;

			case 'int':
				return (int) $raw_value;

			case 'decimal':
				return (string) wc_format_decimal( $raw_value );

			case 'string':
			default:
				return (string) $raw_value;
		}
	}
}
