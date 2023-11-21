<?php

class Migrator_CLI extends WP_CLI_Command {

	/**
	 * 1. Fetch orders from Shopify then loop through them.
	 * 2. Check if the corresponding Woo order has tags.
	 * 3. Set the tags for Woo order if need.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Run the command without actually doing anything
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * @when after_wp_load
	 */
	public function fix_missing_order_tags( $args, $assoc_args ) {
		$order_tags = new Migrator_CLI_Order_Tags();
		$order_tags->fix_missing_order_tags( $assoc_args );
	}

	/**
	 * Migrate products from Shopify to WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * [--status]
	 * : Product status.
	 *
	 * [--ids]
	 * : Query products by IDs.
	 *
	 * [--exclude]
	 * : Exclude products by IDs or by SKU pattern.
	 *
	 * [--handle]
	 * : Query products by handles
	 *
	 * [--product-type]
	 * : single or variable or all.
	 *
	 * [--no-update]
	 * : Force create new products instead of updating existing one base on the handle.
	 *
	 * [--fields]
	 * : Only migrate/update selected fields.
	 *
	 * [--exclude-fields]
	 * : Exclude selected fields from update.
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * Example:
	 * wp migrator products --limit=100 --perpage=10 --status=active --product-type=single --exclude="CANAL_SKU_*"
	 *
	 * @when after_wp_load
	 */
	public function products( $args, $assoc_args ) {
		$products = new Migrator_CLI_Products();
		$products->migrate_products( $assoc_args );
	}

	/**
	 * Migrate Shopify orders to WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * [--status]
	 * : Order status.
	 *
	 * [--ids]
	 * : Query orders by IDs.
	 *
	 * [--exclude]
	 * : Exclude orders by IDs.
	 *
	 * [--no-update]
	 * : Skip existing order without updating.
	 *
	 * [--sorting]
	 * : Sort the response. Default to 'id asc'.
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * [--mode=<live|test>]
	 * : Switching to the 'live' mode will directly copy the email and phone number without any suffix, ensuring they remain intact. In 'test' mode, as a
	 * precaution to prevent accidental notifications to customers, both the email and phone number will be masked with a suffix. The default setting is
	 * 'test'
	 *
	 * [--send-notifications]
	 * : If this flag is added, the migrator will send out 'New Account created' email notifications to users, for every new user imported; and 'New
	 * order' notification for each order to the site admin email. Beware of potential spamming before adding this flag!
	 *
	 * @when after_wp_load
	 */
	public function orders( $args, $assoc_args ) {
		$this->assoc_args = $assoc_args;
		$orders           = new Migrator_CLI_Orders();
		$orders->migrate_orders( $assoc_args );
	}

	/**
	 * Migrate subscriptions from Skio to WooCommerce.
	 * This funciton will import from json files not from the api.
	 *
	 * ## OPTIONS
	 *
	 * [--subscriptions_export_file]
	 * : The subscriptions json file exported from Skio dashboard
	 *
	 *  [--orders_export_file]
	 * : The orders json file exported from Skio dashboard
	 *
	 * Example:
	 * wp migrator skio_subscriptions --subscriptions_export_file=subscriptions.json --orders_export_file=orders.json
	 *
	 * @when after_wp_load
	 */
	public function skio_subscriptions( $args, $assoc_args ) {
		$subscriptions = new Migrator_CLI_Subscriptions();
		$subscriptions->import( $assoc_args );
	}

	/**
	 * Import the customer data from Stripe into WooPayments.
	 * This function uses the WooPayments plugin connection
	 * to the WooPayments server.
	 * So WooPayments needs to be active and linked to the server.
	 *
	 * It will not create the site customer it will just update
	 * one that matches the email in Stripe.
	 *
	 * Example:
	 * wp import_stripe_data_into_woopayments
	 *
	 * @when after_wp_load
	 */
	public function import_stripe_data_into_woopayments() {
		$payment_methods = new Migrator_CLI_Payment_Methods();
		$payment_methods->import_stripe_data_into_woopayments();
	}

	/**
	 * Reads the stripe csv created after the PAN import and saves info about
	 * the original `cus_` and `pm_`s to the customer and payment tokens.
	 *
	 * ## Options
	 *
	 * [--migration_file]
	 * : The csv file stripe created containing the mapping between old and data
	 *
	 * Example:
	 *
	 * wp migrator add_woopayments_migration_data --migration_file=<absolute_path>
	 *
	 * @when after_wp_load
	 */
	public function add_woopayments_migration_data( $args, $assoc_args ) {
		$payment_methods = new Migrator_Cli_Payment_Methods();
		$payment_methods->update_orders_and_subscriptions_payment_methods( $assoc_args );
	}
}
