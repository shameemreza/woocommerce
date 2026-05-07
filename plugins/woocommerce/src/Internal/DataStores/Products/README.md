# High-Performance Product Storage (HPPS)

## Index

- [Summary](#summary)
- [Enabling and disabling the feature](#enabling-and-disabling-the-feature)
- [Architecture](#architecture)
- [Migration](#migration)
- [WP-CLI reference](#wp-cli-reference)
- [Hooks](#hooks)
- [Rolling back](#rolling-back)
- [Bidirectional sync (opt-in)](#bidirectional-sync-opt-in)
- [Authoritative-source flag](#authoritative-source-flag)
- [Phase 1 limitations](#phase-1-limitations)

## Summary

High-Performance Product Storage moves product data from the legacy `wp_posts` and `wp_postmeta` tables into five dedicated tables under `$wpdb->prefix`:

- `wc_products`: one row per product holds the columns that drive shop, single-product, and admin list queries (price, stock, status, type, sku, dates, COGS).
- `wc_product_attributes` and `wc_product_attribute_values`: per-product attribute records and the value lists driving each attribute, replacing the `_product_attributes` serialized blob.
- `wc_product_downloads`: one row per downloadable file, replacing the `_downloadable_files` serialized blob.
- `wc_products_meta`: HPPS' own meta store, taking the place of `wp_postmeta` for product meta.

The storefront and admin keep using the same `WC_Product` API. The win is in query shape: a shop page that previously ran 1 + n + n*30+ queries (one `WP_Query`, one read per product, 30+ postmeta hits per product) collapses to a small number of joined queries against `wc_products` and friends.

HPPS mirrors the architectural choices of High-Performance Order Storage (HPOS): a feature toggle, a background migration controller, a typed data store hierarchy, and a per-feature controller that wires admin UX, CLI, and lifecycle.

## Enabling and disabling the feature

The feature is exposed under WooCommerce, Settings, Advanced, Features as Product data storage. The radio writes to the `woocommerce_custom_product_tables_enabled` option (`yes` / `no`).

Programmatic toggle:

```
// Turn on (creates tables and enqueues the background migration if needed).
update_option( 'woocommerce_custom_product_tables_enabled', 'yes' );

// Turn off (storefront immediately switches back to CPT reads).
update_option( 'woocommerce_custom_product_tables_enabled', 'no' );
```

When the option flips on, `CustomProductsTableController::on_feature_option_updated()` fires and:

1. Creates the five HPPS tables if they don't exist (idempotent `dbDelta`).
2. Marks the migration as pending (`woocommerce_products_table_migration_status` set to `pending`).
3. Enqueues the `PostsToProductsMigrationController` batch processor.

If WooCommerce starts and finds the option set to `yes` but at least one HPPS table missing (a back-up restored without DDL, for example), `CustomProductsTableController::maybe_self_heal_tables()` recreates the schema once per request before the data-store filter is allowed to route to HPPS.

## Architecture

### Class map

| Concern | Class |
|---|---|
| Feature lifecycle, admin UX, data-store routing | `CustomProductsTableController` |
| Base CRUD against the HPPS tables | `ProductsTableDataStore` |
| Variable-product CRUD | `ProductsTableVariableDataStore` |
| Variation CRUD | `ProductsTableVariationDataStore` |
| Grouped-product CRUD | `ProductsTableGroupedDataStore` |
| Product meta (parallel to `OrdersTableDataStoreMeta`) | `ProductsTableDataStoreMeta` |
| Migration high-level orchestration | `ProductDataSynchronizer` |
| Background batch migration | `PostsToProductsMigrationController` |
| One-pass migration logic | `PostToProductTableMigrator` |
| WP-CLI surface | `CLIRunner` |

### `product_placeholder` post type

Every product still has a row in `wp_posts`. HPPS uses a non-public CPT, `product_placeholder`, to allocate the ID without firing any of the legacy `product` hooks or exposing the row publicly. The post is created in `ProductsTableDataStore::create()` immediately before the `wc_products` row, so:

- Foreign keys that point at `wp_posts.ID` (taxonomy relationships, term metadata, comments) keep working.
- `wp_delete_post()` cleans up term relationships and any straggler postmeta.
- Existing extensions that read `wp_posts` to reach a product ID don't break.

The post type is registered in `CustomProductsTableController::register_placeholder_post_type()` with `public => false`, `show_ui => false`, and `exclude_from_search => true`.

### Data-store routing and fallback

`CustomProductsTableController::filter_product_data_store()` listens on `woocommerce_data_stores` and returns the HPPS class for `product`, `product_variable`, `product_variation`, and `product_grouped` only when:

1. The HPPS feature option is `yes`, and
2. The `wc_products` table exists.

If either is false, the legacy CPT data store stays in place. This means a user can flip the feature on and the storefront keeps working, even before tables are created (the self-heal will run on the first admin or front-end request).

For products that exist in `wp_posts` but haven't been migrated yet, `ProductsTableDataStore::read()`, `update()`, `delete()`, and `get_product_type()` delegate to the legacy CPT data store via `get_legacy_data_store()`. This lets the system function during a long migration: shop pages serve the still-legacy products from CPT and the migrated ones from HPPS.

### Lookup table sync

`wc_product_meta_lookup` is still the canonical filterable index for shop and admin list queries. The HPPS data store keeps it in lockstep on every write through `ProductsTableDataStore::update_lookup_table()`. The grouped store overrides this method to compute price MIN/MAX from children's `wc_products.price` directly, skipping the postmeta dance the CPT store does.

## Migration

`ProductDataSynchronizer` is the high-level orchestrator. The actual work runs in two layers:

1. `PostsToProductsMigrationController` is a `BatchProcessorInterface` implementation that fits into WooCommerce's standard batch-processing pipeline. Action Scheduler fires `wc_run_batch_process` and the controller pulls the next chunk of unmigrated product IDs.
2. `PostToProductTableMigrator::migrate_products( array $ids )` does the per-product work: read every needed field via the legacy `WC_Product_Data_Store_CPT`, normalize, write the HPPS rows in a single transaction, mark the product as migrated.

The migrator is idempotent: a product that already has a `wc_products` row is reported as `skipped` rather than re-written. `is_already_migrated()` is used both as a fast pre-check and as the source of truth for `verify`.

Pending count comes from `ProductDataSynchronizer::get_pending_count()`, which compares the live `product` and `product_variation` count in `wp_posts` to the row count in `wc_products`. The result feeds:

- The yellow admin banner.
- The Features-page status string.
- The `wp wc hpps status` CLI output.

## WP-CLI reference

All commands live in `CLIRunner` and are registered under `wp wc hpps`.

### `wp wc hpps status`

Prints pending count, migration option state, and whether the background processor is enqueued.

```
$ wp wc hpps status
Pending products: 17
Migration status: pending
Background processor enqueued: yes
```

### `wp wc hpps sync`

Runs the migration synchronously to completion. Useful in CI or when an operator wants to bypass Action Scheduler.

```text
$ wp wc hpps sync --batch-size=50
Synced 412 product(s) (0 skipped, 0 errors) over 9 batch(es).
```

Options:

- `--batch-size=<n>`: products per batch. Default 25.
- `--limit=<n>`: maximum products to migrate this run. Default unlimited.

### `wp wc hpps verify`

Per-product structural diff between the legacy CPT side and HPPS. For each product it reads via both data stores, normalizes the data, and reports any field that differs.

```text
$ wp wc hpps verify --limit=5
All 5 product(s) match between legacy and HPPS storage.
```

Options:

- `--limit=<n>`: maximum products to compare. Default 100. Use `--limit=0` for unlimited.
- `--id=<id>`: compare a specific product only.
- `--ignore=<csv>`: comma-separated list of fields to skip (for example `date_modified`).
- `--format=<table|json>`: output format for mismatches.

Exits 1 if any mismatch is found, 0 otherwise.

## Hooks

HPPS mirrors the legacy hook surface so existing extensions keep working. The data store fires:

- `woocommerce_new_product` and `woocommerce_new_product_variation`: after `create()`.
- `woocommerce_update_product` and `woocommerce_update_product_variation`: after `update()`.
- `woocommerce_delete_product` and `woocommerce_trash_product`: after `delete()`.
- `woocommerce_product_read`: at the end of `read()`, with the same `$product_id, $product` signature.
- `woocommerce_product_attributes_updated`: after attributes are persisted.
- `woocommerce_updated_product_price`: after `sync_price()` on a variable product.

Filters available specifically for HPPS callers:

- `woocommerce_data_stores`: route a custom product type to your own data store, or replace HPPS' default.
- `woocommerce_install_get_tables`: extend the table list HPPS keeps in lockstep with `dbDelta`.
- `woocommerce_hpps_data_sync_enabled`: force the CPT → HPPS listener on or off without flipping the persistent option (see [Bidirectional sync](#bidirectional-sync-opt-in)).
- `woocommerce_hpps_authoritative_source`: pick which storage layer is canonical for reads while the feature is on (see [Authoritative-source flag](#authoritative-source-flag)).

## Rolling back

To revert to legacy CPT storage:

1. Set the feature option to `no` (Features page or `update_option`).
2. The storefront immediately switches back to CPT reads. Products that were migrated continue to work because every HPPS write also writes back to `wp_posts`/`wp_postmeta` via the placeholder + lookup-table path.
3. Optionally, drop the HPPS tables via WooCommerce, Status, Tools, Delete the HPPS tables. The Tools entry is only enabled when the feature is off.

The HPPS tables are also included in `WC_Install::get_tables()`, so a full WC uninstall with `WC_REMOVE_ALL_DATA = true` cleans them up automatically. The placeholder posts are removed in the same uninstall path.

## Bidirectional sync (opt-in)

`ProductDataSyncListener` keeps `wp_postmeta` and `wc_products` in agreement in both directions, for the curated set of high-impact meta keys defined in `META_TO_COLUMN_MAP` (price, stock, sku, weight and dimensions, virtual/downloadable flags, tax class/status, manage stock, stock status, low-stock amount, sold individually, ratings).

Three ways to enable it:

1. **Features page UI**: WooCommerce, Settings, Advanced, Features. Tick "Enable compatibility mode (Synchronize products between High-performance product storage and WordPress posts storage)" under the Product data storage radio. The checkbox is exposed as an `additional_settings` entry on the HPPS feature definition, mirroring the HPOS compatibility-mode toggle.
2. **WP-CLI / option update**:

   ```php
   update_option( 'woocommerce_custom_product_tables_data_sync_enabled', 'yes' );
   ```

3. **Filter** (no persistence):

   ```php
   add_filter( 'woocommerce_hpps_data_sync_enabled', '__return_true' );
   ```

### CPT → HPPS

Hooked on `updated_post_meta`, `added_post_meta`, and `deleted_post_meta` at priority 20. Catches third-party plugins that bypass the WC API and write postmeta directly (a pricing plugin doing `update_post_meta( $product_id, '_price', '9.99' )`, an importer poking `_stock`) and replays the change into the matching `wc_products` column with `$wpdb->update()`.

The listener no-ops when sync is off, the post isn't a product or variation, the meta key isn't mapped, or the product hasn't been migrated into HPPS yet.

### HPPS → CPT

Hooked on `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`, and `woocommerce_update_product_variation` at priority 20. After a save through the WC API completes, the listener reads the canonical row out of `wc_products` and writes the mapped values back into postmeta with `update_post_meta()`. This keeps legacy reads (REST endpoints not migrated to HPPS, custom `WP_Query` + `meta_query` calls, third-party plugins that read postmeta directly) seeing fresh values.

The writeback no-ops for products that don't exist in HPPS, so the legacy CPT data-store fallback path doesn't double-write postmeta.

### Reentrancy

Both directions share a depth-counted reentrancy guard via `ProductDataSyncListener::start_internal_write()` / `end_internal_write()`. The HPPS → CPT writeback opens the guard before its `update_post_meta()` loop, so the inverse listener bails on the resulting `updated_post_meta` events instead of echoing them back into `wc_products`. The guard is depth-counted, so an extension hooking `updated_post_meta` and triggering another product save inside our writeback stays safe.

To bracket an HPPS-internal postmeta write from your own code so the listener doesn't echo it back, wrap the write in:

```php
ProductDataSyncListener::start_internal_write();
try {
    update_post_meta( $product_id, '_price', $value );
} finally {
    ProductDataSyncListener::end_internal_write();
}
```

## Authoritative-source flag

`ProductDataSynchronizer::authoritative_source()` returns `'hpps'` (default) or `'cpt'`. The data-store router consults it after the feature gate, so a site can flip the option on, run sync to keep both stores warm, and cut reads back to CPT temporarily by filtering:

```php
add_filter( 'woocommerce_hpps_authoritative_source', static fn() => 'cpt' );
```

This is the seam Phase 2 dual-mode cutover hangs off. In Phase 1 the default keeps HPPS authoritative whenever the feature is on, so existing behaviour doesn't change.

## Phase 1 limitations

These are known and tracked. Don't ship them as bugs.

- **Listener covers the high-impact meta keys, not every column.** Composite columns (`gallery_image_ids`, `date_on_sale_*`) and the COGS columns are not yet mirrored in either direction; they update on the next full save through the WC API.
- **Taxonomies stay in WP.** `product_cat`, `product_tag`, `product_brand`, `product_shipping_class`, and `product_visibility` continue to live in `wp_term_relationships`. Moving them is out of scope for HPPS.
- **Reports keep using the existing analytics tables.** HPPS feeds `wc_product_meta_lookup` so admin list filters work, but `wc_order_product_lookup` and the WC Analytics tables are unchanged.
