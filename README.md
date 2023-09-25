# migrator-cli

## Getting started

1. Clone the repo into `wp-content/plugins`.
2. Copy the `config.example.php` to `config.php`.
3. Grab the Shopify Access token by [creating a custom app](https://help.shopify.com/en/manual/apps/app-types/custom-apps). Make sure the required scopes for products and orders migration are selected.
4. Update domain and access token in the config.php file.
5. Activate the plugin.

## Commands

```
  wp migrator products [--dry-run] [--before] [--after] [--limit] [--perpage] [--next] [--status] [--ids] [--exclude] [--handle] [--product-type] [--no-update] [--fields] [--exclude-fields] [--remove-orphans]

  OPTIONS

  [--before]
    Query Order before this date. ISO 8601 format.

  [--after]
    Query Order after this date. ISO 8601 format.

  [--limit]
    Limit the total number of orders to process. Set to 1000 by default.

  [--perpage]
    Limit the number of orders to process each time.

  [--next]
    Next page link from Shopify.

  [--status]
    Product status.

  [--ids]
    Query products by IDs.

  [--exclude]
    Exclude products by IDs or by SKU pattern.

  [--handle]
    Query products by handles

  [--product-type]
    single or variable or all.

  [--no-update]
    Force create new products instead of updating existing one base on the handle.

  [--fields]
    Only migrate/update selected fields.

  [--exclude-fields]
    Exclude selected fields from update.

  [--remove-orphans]
    Remove orphans order items

  Example:
  wp migrator products --limit=100 --perpage=10 --status=active --product-type=single --exclude="CANAL_SKU_*"
```

```
  wp migrator orders [--before] [--after] [--limit] [--perpage] [--next] [--status] [--ids] [--exclude] [--no-update] [--sorting] [--remove-orphans]

  OPTIONS

  [--before]
    Query Order before this date. ISO 8601 format.

  [--after]
    Query Order after this date. ISO 8601 format.

  [--limit]
    Limit the total number of orders to process. Set to 1000 by default.

  [--perpage]
    Limit the number of orders to process each time.

  [--next]
    Next page link from Shopify.

  [--status]
    Order status.

  [--ids]
    Query orders by IDs.

  [--exclude]
    Exclude orders by IDs.

  [--no-update]
    Skip existing order without updating.

  [--sorting]
    Sort the response. Default to 'id asc'.

  [--remove-orphans]
    Remove orphans order items
```
