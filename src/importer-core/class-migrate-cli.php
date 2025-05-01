<?php

class Migrate_CLI extends WP_CLI_Command {

	/**
	 * Migrate products from Shopify to WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--platform=<platform>]
	 * : Specify the source platform (e.g., shopify). Defaults to 'shopify'.
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of products to process.
	 *
	 * [--perpage]
	 * : Limit the number of products to process each time. (default: 100, max: 250).
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
	 * [--skip-update]
	 * : Force create new products instead of updating existing one base on the handle.
	 *
	 * [--fields]
	 * : Only migrate/update selected fields.
	 *
	 * [--exclude-fields]
	 * : Exclude selected fields from update.
	 *
	 * [--variants-per-product]
	 * : Number of variants to fetch per product (default: 100, max: 2000).
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * [--verbose]
	 * : Show verbose output of performance and product info
	 *
	 * [--disable-hooks]
	 * : Disable WordPress hooks (like save_post) during migration for performance. Use with caution.
	 *
	 * Example:
	 * wp migrate products --limit=100 --perpage=10 --status=active --product-type=single --exclude="CANAL_SKU_*"
	 *
	 * @when after_wp_load
	 */
	public function products( $args, $assoc_args ) {
		Migrate_CLI_Utils::set_importing_const();

		$products = new Migrate_CLI_Products();
		$products->migrate_products( $assoc_args );
	}
}
