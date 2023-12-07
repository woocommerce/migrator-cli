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
  wp migrator orders [--before] [--after] [--limit] [--perpage] [--next] [--status] [--ids] [--exclude] [--no-update] [--sorting] [--remove-orphans] [--mode=<live|test>]

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
wp import_stripe_data_into_woopayments
```


```
  wp migrator add_woopayments_migration_data [--migration_file]

  OPTIONS

  [--migration_file]
  : The csv file stripe created containing the mapping between old and new data
 
  Example:
 
  wp migrator add_woopayments_migration_data --migration_file=<absolute_path>	 
```

```
 
## Options
	wp migrator coupons [--limit] [--cursor]
	
  OPTIONS	
 
  [--limit]
  : Limit the total number of coupons to process. This won't count the sub codes. Default to 1000.

  [--cursor]
  : The cursor of the last discount to start importing from

  Example:

  wp migrator coupons --limit=1 --cursor=<cursor>
```
