<?php

class Migrator_CLI_Order_Tags {
	public function __invoke( $args, $assoc_args ) {
		Migrator_CLI_Utils::health_check();

		$dry_run   = isset( $assoc_args['dry-run'] ) ? true : false;
		$before    = isset( $assoc_args['before'] ) ? $assoc_args['before'] : null;
		$after     = isset( $assoc_args['after'] ) ? $assoc_args['after'] : null;
		$limit     = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$perpage   = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$perpage   = min( $perpage, $limit );
		$next_link = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';

		if ( $next_link ) {
			$response = Migrator_CLI_Utils::rest_request( $next_link );
		} else {
			$response = Migrator_CLI_Utils::rest_request(
				'orders.json?',
				array(
					'limit'          => $perpage,
					'created_at_max' => $before,
					'created_at_min' => $after,
					'status'         => 'any',
					'fields'         => 'id,order_number,tags,created_at',
				)
			);
		}

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response_data->orders ) ) {
			WP_CLI::error( 'Could not find order in Shopify.' );
		}

		WP_CLI::line( sprintf( 'Found %d orders in Shopify. Processing %d orders.', count( $response_data->orders ), min( $limit, $perpage, count( $response_data->orders ) ), count( $response_data->orders ) ) );

		foreach ( $response_data->orders as $shopify_order ) {
			WP_CLI::line( '-------------------------------' );
			$order_number = $shopify_order->order_number;

			WP_CLI::line( sprintf( 'Processing Shopify order: %d, created @ %s', $order_number, $shopify_order->created_at ) );

			if ( ! $shopify_order->tags ) {
				WP_CLI::line( sprintf( 'Order %d has no tags.', $order_number ) );
				continue;
			}

			$tags = explode( ',', $shopify_order->tags );

			// search for the order by the order number
			$query_args = array(
				'numberposts' => 1,
				'meta_key'    => '_order_number',
				'meta_value'  => $order_number,
				'post_type'   => 'shop_order',
				'post_status' => 'any',
				'fields'      => 'ids',
			);

			$posts            = get_posts( $query_args );
			list( $order_id ) = ! empty( $posts ) ? $posts : null;

			if ( ! $order_id ) {
				WP_CLI::error( 'Could not find the corresponding order in WooCommerce.' );
				continue;
			}

			WP_CLI::line( sprintf( 'Found the WooCommerce order: %d', $order_id ) );

			// Set tag for the current order.
			foreach ( $tags as $tag ) {
				$tag = trim( $tag );

				if ( ! $tag ) {
					WP_CLI::line( 'Invalid tag, skipping.' );
					continue;
				}

				WP_CLI::line( sprintf( '- processing tag: "%s"', $tag, $order_id ) );

				if ( $dry_run ) {
					WP_CLI::line( 'Dry run, skipping.' );
					continue;
				}

				// Find the term id if it exists.
				$term = get_term_by( 'name', $tag, 'wcot_order_tag', ARRAY_A );

				if ( ! $term ) {
					$term = wp_insert_term( $tag, 'wcot_order_tag' );
				}

				wp_set_post_terms( $order_id, $term['term_id'], 'wcot_order_tag', true );
			}
		}

		WP_CLI::line( '===============================' );

		$next_link = Migrator_CLI_Utils::get_rest_next_link( $response );
		if ( $next_link && $limit > $perpage ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more orders to process.' );
			$this->fix_missing_order_tags(
				array(
					'next'  => $next_link,
					'limit' => $limit - $perpage,
				)
			);
		} else {
			WP_CLI::success( 'All orders have been processed.' );
		}
	}
}
