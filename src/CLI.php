<?php

namespace Migrator;

use WP_CLI;
use WP_CLI_Command;

class CLI extends WP_CLI_Command {
	/**
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
	 * [--fields]
	 * : Only migrate/update selected fields.
	 *
	 * [--exclude-fields]
	 * : Exclude selected fields from update.
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * [--source]
	 * : Source ID.
	 *
	 * @when after_wp_load
	 */
	public function products( $args, $assoc_args ) {
		$migrator = Main::container()->get(
			Migrators\Product::class,
			[ 'source' => $assoc_args['source'] ]
		);
		$migrator->run( $assoc_args );
	}
}