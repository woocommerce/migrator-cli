# migrate-to-woo-cli

CLI commands to migrate product data from various platforms (initially Shopify) to WooCommerce.

## Architecture

This plugin uses a modular architecture:

*   **Controller** (`Migrate_CLI_Products_Controller` in `src/importer-core/controllers/`): Handles WP-CLI command registration, argument parsing, and orchestrates the migration flow.
*   **Fetcher** (e.g., `Shopify_Fetcher` in `src/platforms/shopify/`): Responsible for fetching data from the source platform API. Implements `Platform_Fetcher_Interface` (found in `src/importer-core/interfaces/`).
*   **Mapper** (e.g., `Shopify_Mapper` in `src/platforms/shopify/`): Responsible for transforming platform-specific data into a standardized WooCommerce format. Implements `Platform_Mapper_Interface` (found in `src/importer-core/interfaces/`).
*   **Importer** (`WooCommerce_Product_Importer` in `src/importer-core/importers/`): Takes the standardized data and creates/updates WooCommerce products, variations, images, taxonomies, etc.

This structure allows for adding support for new platforms (like Magento, BigCommerce) by creating new Fetcher and Mapper classes (typically within a new subdirectory under `src/platforms/`) and registering them via the `migrate_cli_available_platforms` filter.

## Getting Started

1.  Clone the repo into `wp-content/plugins`.
2.  Copy the `src/platforms/shopify/config-example.php` to `src/platforms/shopify/config.php`.
3.  Obtain the necessary API credentials for your source platform (e.g., Shopify Access token by [creating a custom app](https://help.shopify.com/en/manual/apps/app-types/custom-apps)). Ensure the required scopes for reading products are selected.
4.  Update the relevant credential constants (e.g., `SHOPIFY_DOMAIN`, `ACCESS_TOKEN`) in `src/platforms/shopify/config.php`.
5.  Activate the plugin via the WordPress admin or WP-CLI (`wp plugin activate migrate-to-woo`).

## Commands

### `wp wc migrate products`

Migrates products from a source platform store to WooCommerce. This command handles product details, images, variations, categories, tags, and more, attempting to map source data to corresponding WooCommerce fields. Use the options below to control the migration scope and behavior.

```bash
wp wc migrate products [--platform=<platform>] [--before=<date>] [--after=<date>] [--limit=<num>] [--perpage=<num>] [--next=<cursor>] [--status=<status>] [--ids=<ids>] [--exclude=<ids>] [--handle=<handle>] [--product-type=<type>] [--skip-update] [--fields=<fields>] [--exclude-fields=<fields>] [--variants-per-product=<num>] [--remove-orphans] [--verbose] [--disable-hooks]
```

**OPTIONS**

`--platform=<platform>`
: Select the source platform to migrate from. Registered platforms can be added via the `migrate_cli_available_platforms` filter.
: Default: `shopify`

`--before=<date>`
: Filter products created *before or on* this specific date/time (ISO 8601 format, e.g., "2023-10-27T10:00:00Z"). Platform-specific interpretation.

`--after=<date>`
: Filter products created *after or on* this specific date/time (ISO 8601 format). Platform-specific interpretation.

`--limit=<num>`
: Specify the *maximum total* number of products to process across all batches.
: Default: `PHP_INT_MAX` (process all matched products).

`--perpage=<num>`
: Define the *number of products to fetch* from the source platform in each API request (batch size).
: Default: `100`. Max: `250`.

`--next=<cursor>`
: Provide a platform-specific *pagination cursor* to resume migration from a specific point, skipping products before this cursor.

`--status=<status>`
: Filter products by their source platform status (e.g., `active`, `archived`, `draft` for Shopify).

`--ids=<ids>`
: Process *only* the products matching the specified comma-separated source platform Product IDs.

`--exclude=<ids>`
: *Skip* processing products matching the specified comma-separated source platform Product IDs. Takes precedence over `--ids` if a product ID is in both lists.

`--handle=<handle>`
: Filter products by their exact source platform handle (URL slug). (Primarily for Shopify).

`--product-type=<type>`
: Filter products by their source platform Product Type string (e.g., "T-Shirt", "Gift Card"). Use `all` to ignore this filter. (Primarily for Shopify).

`--skip-update`
: If a product with the same original source ID already exists in WooCommerce, *skip* updating it. By default, existing products are updated. *(Note: Requires `Importer::find_existing_product_id()` implementation).*

`--fields=<fields>`
: Specify a comma-separated list of fields (e.g., `title,sku,images`) to migrate. Only these selected fields will be created or updated on the WooCommerce product. Available fields depend on the Mapper.

`--exclude-fields=<fields>`
: Specify a comma-separated list of fields (e.g., `description,tags`) to *exclude* from migration/update. All other standard fields (as defined by the Mapper) will be processed.

`--variants-per-product=<num>`
: Number of variants to fetch per product from the source platform.
: Default: `100`. Range: `1` to `2000`.

`--remove-orphans`
: When updating a variable product, delete any existing WooCommerce variations that don't correspond to a variation in the current source data for that product. *(Note: Requires implementation in Importer).*

`--verbose`
: Enable detailed output during migration, including processing times per product, memory usage, and image upload details.

`--disable-hooks`
: Disable standard WordPress action hooks (like `save_post`, `wp_insert_post`, various WooCommerce hooks) during the migration process. This can significantly improve performance but might skip integrations relying on these hooks. Use with caution.

**Example:**

```bash
# Migrate first 50 active products from Shopify, 10 per batch, verbose output
wp wc migrate products --platform=shopify --limit=50 --perpage=10 --status=active --verbose

# Migrate only specific Shopify products by ID
wp wc migrate products --platform=shopify --ids=12345,67890

# Migrate all products except specific IDs, excluding description and tags
wp wc migrate products --platform=shopify --exclude=98765 --exclude-fields=description,tags
```