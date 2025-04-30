# migrator-cli

## Getting started

1. Clone the repo into `wp-content/plugins`.
2. Copy the `config-example.php` to `config.php`.
3. Grab the Shopify Access token by [creating a custom app](https://help.shopify.com/en/manual/apps/app-types/custom-apps). Make sure the required scopes for products and orders migration are selected.
4. Update domain and access token in the config.php file.
5. Activate the plugin.

## Important Notes

1. For Order migration - To prevent accidental sending of notifications, there is a `--mode` flag that is set to test by default. This masks the email and phone. Please add `--mode=live` flag whenever you wish to do a final migration with unmasked email and phone. 
2. For order imports, notifications are disabled. These notifications include default emails sent by WooCommerce to users ( 'New Account Created') and site admin ('New Order Received'), for every order has been disabled to prevent spamming. For any reason if you wish to have those notifications sent, please add `--send-notifications` 

## Commands

Migrates products from a Shopify store to WooCommerce using the Shopify GraphQL API. This command handles product details, images, variations, categories, tags, and more, attempting to map Shopify data to corresponding WooCommerce fields. Use the options below to control the migration scope and behavior.

```
  wp migrator products [--before] [--after] [--limit] [--perpage] [--next] [--status] [--ids] [--exclude] [--handle] [--product-type] [--skip-update] [--fields] [--exclude-fields] [--remove-orphans] [--verbose]

  OPTIONS

  [--before]
    Filter products created *before or on* this specific date/time. Use ISO 8601 format (e.g., "2023-10-27T10:00:00Z").

  [--after]
    Filter products created *after or on* this specific date/time. Use ISO 8601 format.

  [--limit]
    Specify the *maximum total* number of products to process across all batches. Defaults to processing all matched products.

  [--perpage]
    Define the *number of products to fetch* from Shopify in each API request (batch size). Max 250. Defaults to 250.

  [--next]
    Provide a Shopify *pagination cursor* to resume migration from a specific point, skipping products before this cursor.

  [--status]
    Filter products by their Shopify status (e.g., `active`, `archived`, `draft`).

  [--ids]
    Process *only* the products matching the specified comma-separated Shopify Product IDs.

  [--exclude]
    *Skip* processing products matching the specified comma-separated Shopify Product IDs. Takes precedence over `--ids` if a product is in both.

  [--handle]
    Filter products by their exact Shopify handle (URL slug).

  [--product-type]
    Filter products by their Shopify Product Type string (e.g., "T-Shirt", "Gift Card"). Use `all` to ignore this filter.

  [--skip-update]
    If a product with the same original Shopify ID already exists in WooCommerce, *skip* updating it. By default, existing products are updated.

  [--fields]
    Specify a comma-separated list of fields (e.g., `title,sku,images`) to migrate. Only these selected fields will be created or updated on the WooCommerce product.

  [--exclude-fields]
    Specify a comma-separated list of fields (e.g., `description,tags`) to *exclude* from migration/update. All other standard fields will be processed.

  [--remove-orphans]
    When updating a variable product, delete any existing WooCommerce variations that don't correspond to a variation in the current Shopify data for that product.

  [--verbose]
    Enable detailed output during migration, including processing times per product, memory usage, and image upload details.

  Example:
  wp migrator products --limit=100 --perpage=10 --status=active --product-type=single --exclude="CANAL_SKU_*"
```

```
  wp migrator orders [--before] [--after] [--limit] [--perpage] [--next] [--status] [--ids] [--exclude] [--no-update] [--sorting] [--remove-orphans] [--mode=<live|test>]

  OPTIONS

  [--before]
    Query Order before this date. ISO 8601 format.

  [--after]
    Query Order after this date. ISO 8601 format.

  [--limit]
    Limit the total number of orders to process. Set to PHP_INT_MAX by default.

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

  [--mode=<live|test>]
    Defaults to 'test' where email address is suffixed with '.masked' and phone number is blanked. Please set this flag as 'live' when you wish to do the final migration with unmasked email and phone.

	[--send-notifications]
		If this flag is added, the migrator will send out 'New Account created' email notifications to users, for every new user imported; and 'New
	 order' notification for each order to the site admin email. Beware of potential spamming before adding this flag!
```

```
  wp migrator skio_subscriptions [--subscriptions_export_file] [--orders_export_file]

  The json files can downloaded from the Skio dashboard at https://dashboard.skio.com/subscriptions/export 

  OPTIONS

  [--subscriptions_export_file]
    The subscriptions json file exported from Skio dashboard

  [--orders_export_file]
    The orders json file exported from Skio dashboard
```

```
wp import_stripe_data_into_woopayments [--limit]

  OPTIONS
  
  [--limit]
  : Limit the total number of coupons to process. This won't count the sub codes. Default to PHP_INT_MAX.

  Example
  wp import_stripe_data_into_woopayments --limit=1  
  
```


```
  wp migrator update_payment_methods [--migration_file] [--order-ids] [--subscription-ids]

  OPTIONS

  [--migration_file]
  : The csv file stripe created containing the mapping between old and new data
 
  [--order-ids]
  : A list of Woo order ids to be processed. Limited to 100.
	 
  [--subscription-ids]
  : A list of Woo subscription ids to be processed. Limited to 100.
	 
 
  Example:
 
  wp migrator update_payment_methods --migration_file=<absolute_path> --order-ids="1,2,3" --subscription-ids="3,4,5"	 
```

```
 
## Options
	wp migrator coupons [--limit] [--cursor]
	
  OPTIONS	
 
  [--limit]
  : Limit the total number of coupons to process. This won't count the sub codes. Default to PHP_INT_MAX.

  [--cursor]
  : The cursor of the last discount to start importing from

  Example:

  wp migrator coupons --limit=1 --cursor=<cursor>
```
