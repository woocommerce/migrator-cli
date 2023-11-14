<?php

class Migrator_CLI_Orders {

	private $assoc_args, $order_items_mapping, $order_tax_rate_ids_mapping;

	public function migrate_orders( $assoc_args ) {
		$this->assoc_args = $assoc_args;

		Migrator_CLI_Utils::health_check();
		Migrator_CLI_Utils::disable_sequential_orders();

		$before             = isset( $assoc_args['before'] ) ? $assoc_args['before'] : null;
		$after              = isset( $assoc_args['after'] ) ? $assoc_args['after'] : null;
		$limit              = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$perpage            = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$next_link          = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';
		$status             = isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'any';
		$ids                = isset( $assoc_args['ids'] ) ? $assoc_args['ids'] : null;
		$exclude            = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$no_update          = isset( $assoc_args['no-update'] ) ? true : false;
		$sorting            = isset( $assoc_args['sorting'] ) ? $assoc_args['sorting'] : 'id asc';
		$mode               = isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'test';
		$send_notifications = isset( $assoc_args['send-notifications'] ) ? true : false;

		do {
			if ( $next_link ) {
				$response = Migrator_CLI_Utils::rest_request( $next_link );
			} else {
				$response = Migrator_CLI_Utils::rest_request(
					'orders.json',
					array(
						'limit'          => $perpage,
						'created_at_max' => $before,
						'created_at_min' => $after,
						'status'         => $status,
						'ids'            => $ids,
						'order'          => $sorting,
					)
				);
			}

			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			// Disable WP emails before migration starts, unless --send-notifications flag is added.
			if ( ! $send_notifications ) {
				add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			} else {
				WP_CLI::confirm( 'Are you sure you want to send out email notifications to users? This could potentially spam your users.' );
			}

			if ( empty( $response_data->orders ) ) {
				WP_CLI::error( 'No Shopify orders found.' );
			}

			WP_CLI::line( sprintf( 'Found %d orders in Shopify. Processing %d orders.', count( $response_data->orders ), min( $limit, $perpage, count( $response_data->orders ) ) ) );

			for ( $i = 0; $i < min( $limit, $perpage, count( $response_data->orders ) ); $i++ ) {
				$shopify_order = $response_data->orders[ $i ];

				if ( in_array( $shopify_order->id, $exclude ) ) {
					WP_CLI::line( sprintf( 'Order %s is excluded. Skipping...', $shopify_order->order_number ) );
					continue;
				}

				// Mask phone number in test mode.
				if ( 'test' === $mode ) {
					if ( isset( $shopify_order->shipping_address->phone ) ) {
						$shopify_order->shipping_address->phone = '9999999999';
					}

					if ( isset( $shopify_order->billing_address->phone) ) {
						$shopify_order->billing_address->phone = '9999999999';
					}
				}

				// Check if the order exists in WooCommerce.
				$woo_order = $this->get_corresponding_woo_order( $shopify_order );

				if ( $woo_order ) {
					WP_CLI::line( sprintf( 'Order %s already exists (%s). %s...', $shopify_order->order_number, $woo_order->get_id(), $no_update ? 'Skipping' : 'Updating' ) );

					if ( $no_update ) {
						continue;
					}
				} else {
					WP_CLI::line( sprintf( 'Order %s does not exist. Creating...', $shopify_order->order_number ) );
				}

				$this->create_or_update_woo_order( $shopify_order, $woo_order, $mode );
			}

			WP_CLI::line( '===============================' );

			$next_link = Migrator_CLI_Utils::get_rest_next_link( $response );
			if ( $next_link && $limit > $perpage ) {
				WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more orders to process.' );
				WP_CLI::line( 'Next: ' . $next_link );
				$limit -= $perpage;
			}
		} while ( $next_link );

		WP_CLI::success( 'All orders have been processed.' );

		// Enable WP emails after order migration completes.
		if ( ! $send_notifications ) {
			remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		}

		Migrator_CLI_Utils::enable_sequential_orders();
	}

	private function get_corresponding_woo_order( $shopify_order ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_original_order_id',
				'meta_value' => $shopify_order->id,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		// Find the order by Shopify order number.
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_order_number',
				'meta_value' => $shopify_order->order_number,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		return null;
	}

	private function create_or_update_woo_order( $shopify_order, $woo_order, $mode = 'test' ) {
		$order = new WC_Order( $woo_order );
		$order->save();

		$this->order_items_mapping = $order->get_meta( '_order_items_mapping', true ) ? $order->get_meta( '_order_items_mapping', true ) : array();

		// Store the original order id for future reference.
		$order->update_meta_data( '_original_order_id', $shopify_order->id );
		$order->update_meta_data( '_order_number', $shopify_order->order_number );

		// Prevent Points and Rewards add order notes.
		$order->update_meta_data( '_wc_points_earned', true );

		// Update order status.
		$order->update_status( $this->get_woo_order_status( $shopify_order->financial_status, $shopify_order->fulfillment_status ) );
		$order->set_order_stock_reduced( true );

		// Update order dates.
		$order->set_date_created( $shopify_order->created_at );
		$order->set_date_modified( $shopify_order->updated_at );
		$order->set_date_paid( $shopify_order->processed_at );
		$order->set_date_completed( $shopify_order->closed_at );

		// Update order totals
		$order->set_total( $shopify_order->total_price );
		$order->set_shipping_total( $shopify_order->total_shipping_price_set->shop_money->amount );
		$order->set_discount_total( $shopify_order->total_discounts );

		$this->process_order_tags( $order, $shopify_order );
		$this->process_order_addresses( $order, $shopify_order );

		if ( $shopify_order->email ) {
			// Mask email in test mode.
			if ( 'test' === $mode ) {
				$shopify_order->email .= '.masked';
			}
			$this->create_or_assign_customer( $order, $shopify_order, $mode );
		} else {
			$this->set_placeholder_billing_email( $order );
		}

		// Tax must be processed before line items.
		$this->process_tax_lines( $order, $shopify_order );
		$this->maybe_remove_orphan_items( $order, $shopify_order );
		$this->process_line_items( $order, $shopify_order );
		$this->process_shipping_lines( $order, $shopify_order );
		$this->process_discount_lines( $order, $shopify_order );

		$this->process_shipment_tracking( $order, $shopify_order );

		$order->update_meta_data( '_order_items_mapping', $this->order_items_mapping );
		$order->save();

		// Refunds
		$this->process_order_refunds( $order, $shopify_order );
	}

	private function get_woo_order_status( $financial_status, $fulfillment_status ) {
		$financial_mapping = array(
			'pending'            => 'pending',
			'authorized'         => 'processing',
			'partially_paid'     => 'processing',
			'paid'               => 'processing',
			'partially_refunded' => 'processing',
			'refunded'           => 'refunded',
			'voided'             => 'cancelled',
		);

		// Define the mapping arrays for Shopify fulfillment status to WooCommerce order status
		$fulfillment_mapping = array(
			'fulfilled' => 'completed',
			'partial'   => 'processing',
			'pending'   => 'processing',
		);

		$woo_status = 'pending'; // Default WooCommerce order status if no mapping found

		// Map financial status
		if ( isset( $financial_mapping[ $financial_status ] ) ) {
			$woo_status = $financial_mapping[ $financial_status ];
		}

		// Map fulfillment status
		if ( isset( $fulfillment_mapping[ $fulfillment_status ] ) ) {
			$woo_status = $fulfillment_mapping[ $fulfillment_status ];
		}

		return $woo_status;
	}

	private function process_order_tags( $order, $shopify_order ) {
		if ( ! taxonomy_exists( 'wcot_order_tag' ) ) {
			return;
		}

		$tags = explode( ',', $shopify_order->tags );

		foreach ( $tags as $tag ) {
			$tag = trim( $tag );

			if ( ! $tag ) {
				WP_CLI::line( 'Invalid tag, skipping.' );
				continue;
			}

			WP_CLI::line( sprintf( '- processing tag: "%s"', $tag, $order->get_id() ) );

			// Find the term id if it exists.
			$term = get_term_by( 'name', $tag, 'wcot_order_tag', ARRAY_A );

			if ( ! $term ) {
				$term = wp_insert_term( $tag, 'wcot_order_tag' );
			}

			wp_set_post_terms( $order->get_id(), $term['term_id'], 'wcot_order_tag', true );
		}
	}

	private function process_order_addresses( WC_Order $order, $shopify_order ) {
		// Update order billing address.
		$order->set_billing_first_name( $shopify_order->billing_address->first_name );
		$order->set_billing_last_name( $shopify_order->billing_address->last_name );
		$order->set_billing_company( $shopify_order->billing_address->company );
		$order->set_billing_address_1( $shopify_order->billing_address->address1 );
		$order->set_billing_address_2( $shopify_order->billing_address->address2 );
		$order->set_billing_city( $shopify_order->billing_address->city );
		$order->set_billing_state( $shopify_order->billing_address->province_code );
		$order->set_billing_postcode( $shopify_order->billing_address->zip );
		$order->set_billing_country( $shopify_order->billing_address->country_code );
		$order->set_billing_phone( $shopify_order->billing_address->phone );

		// Update order shipping address.
		$order->set_shipping_first_name( $shopify_order->shipping_address->first_name );
		$order->set_shipping_last_name( $shopify_order->shipping_address->last_name );
		$order->set_shipping_company( $shopify_order->shipping_address->company );
		$order->set_shipping_address_1( $shopify_order->shipping_address->address1 );
		$order->set_shipping_address_2( $shopify_order->shipping_address->address2 );
		$order->set_shipping_city( $shopify_order->shipping_address->city );
		$order->set_shipping_state( $shopify_order->shipping_address->province_code );
		$order->set_shipping_postcode( $shopify_order->shipping_address->zip );
		$order->set_shipping_country( $shopify_order->shipping_address->country_code );
		$order->set_shipping_phone( $shopify_order->shipping_address->phone );

		$order->save();
	}

	private function create_or_assign_customer( $order, $shopify_order, $mode = 'test' ) {

		// Check if the customer exists in WooCommerce.
		$customer = get_user_by( 'email', $shopify_order->email );

		if ( ! $customer ) {
			WP_CLI::line( sprintf( 'Customer %s does not exist. Creating...', $shopify_order->email ) );

			// Create a new customer using Woo functions.
			$customer_id = wc_create_new_customer(
				$shopify_order->email,
				wc_create_new_customer_username( $shopify_order->email ),
				wp_generate_password()
			);

			if ( is_wp_error( $customer_id ) ) {
				WP_CLI::error( sprintf( 'Error creating customer %s: %s', $shopify_order->email, $customer_id->get_error_message() ) );
			}

			$customer = new WC_Customer( $customer_id );
			$customer->set_first_name( $shopify_order->customer->first_name );
			$customer->set_last_name( $shopify_order->customer->last_name );

			$customer->set_billing_first_name( $shopify_order->billing_address->first_name );
			$customer->set_billing_last_name( $shopify_order->billing_address->last_name );
			$customer->set_billing_company( $shopify_order->billing_address->company );
			$customer->set_billing_address_1( $shopify_order->billing_address->address1 );
			$customer->set_billing_address_2( $shopify_order->billing_address->address2 );
			$customer->set_billing_city( $shopify_order->billing_address->city );
			$customer->set_billing_state( $shopify_order->billing_address->province_code );
			$customer->set_billing_postcode( $shopify_order->billing_address->zip );
			$customer->set_billing_country( $shopify_order->billing_address->country );
			$customer->set_billing_phone( $shopify_order->billing_address->phone );
			$customer->set_billing_email( $shopify_order->email );

			$customer->set_shipping_first_name( $shopify_order->shipping_address->first_name );
			$customer->set_shipping_last_name( $shopify_order->shipping_address->last_name );
			$customer->set_shipping_company( $shopify_order->shipping_address->company );
			$customer->set_shipping_address_1( $shopify_order->shipping_address->address1 );
			$customer->set_shipping_address_2( $shopify_order->shipping_address->address2 );
			$customer->set_shipping_city( $shopify_order->shipping_address->city );
			$customer->set_shipping_state( $shopify_order->shipping_address->province_code );
			$customer->set_shipping_postcode( $shopify_order->shipping_address->zip );
			$customer->set_shipping_country( $shopify_order->shipping_address->country );
			$customer->set_shipping_phone( $shopify_order->shipping_address->phone );

			$customer->save();
		} else {
			WP_CLI::line( sprintf( 'Customer %s exists. Assigning...', $shopify_order->email ) );
			$customer_id = $customer->ID;
		}

		$order->set_customer_id( $customer_id );
		$order->save();
	}

	private function set_placeholder_billing_email( $order ) {
		// Create username from first name, last name, and phone number.
		$username  = $order->get_billing_first_name() ?? $order->get_shipping_first_name();
		$username .= $order->get_billing_last_name() ?? $order->get_shipping_last_name();
		$username .= substr( $order->get_billing_phone() ?? $order->get_shipping_phone(), -3 );
		$username  = preg_replace( '/[^a-zA-Z0-9]/', '', $username );
		$username  = strtolower( $username );
		$username  = str_replace( ' ', '', $username );

		if ( $username ) {
			$email = $username . '@example.com.invalid';

			$order->set_billing_email( $email );
		}

		$order->set_customer_id( 0 );
		$order->save();
	}

	private function process_tax_lines( WC_Order $order, $shopify_order ) {
		$order->remove_order_items( 'tax' );
		$this->order_tax_rate_ids_mapping = array();

		foreach ( $shopify_order->tax_lines as $index => $tax_line ) {
			$item = new WC_Order_Item_Tax();
			$item->set_rate_id( $index );
			$item->set_label( $tax_line->title );
			$item->set_tax_total( $tax_line->price );
			$item->set_rate_percent( $tax_line->rate );

			$order->add_item( $item );
			$this->order_tax_rate_ids_mapping[ $tax_line->title ] = $index;
		}

		$order->save();
	}

	private function maybe_remove_orphan_items( $order, $shopify_order ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

		foreach ( $order->get_items( array( 'line_item', 'shipping' ) ) as $item ) {
			if ( ! in_array( $item->get_id(), $this->order_items_mapping ) ) {
				WP_CLI::line( sprintf( 'Removing orphan item %d', $item->get_id() ) );
				$order->remove_item( $item->get_id() );
			}
		}

		$order->save();
	}

	private function process_line_items( $order, $shopify_order ) {
		foreach ( $shopify_order->line_items as $line_item ) {
			$line_item_id = 0;
			if ( isset( $this->order_items_mapping[ $line_item->id ] ) ) {
				WP_CLI::line( sprintf( 'Line item %d already exists (%d). Updating.', $line_item->id, $this->order_items_mapping[ $line_item->id ] ) );
				$line_item_id = $this->order_items_mapping[ $line_item->id ];
			} else {
				WP_CLI::line( sprintf( 'Creating line item %d', $line_item->id ) );
			}

			// Create a product line item
			$item = new WC_Order_Item_Product( $line_item_id );

			list( $product_id, $variation_id ) = $this->find_line_item_product( $line_item );

			if ( $product_id ) {
				$item->set_product_id( $product_id );
			}

			if ( $variation_id ) {
				$item->set_variation_id( $variation_id );
			}

			$item->set_quantity( $line_item->quantity );
			$item->set_subtotal( $line_item->price * $line_item->quantity );
			$item->set_total( $line_item->price * $line_item->quantity - $line_item->total_discount );
			$item->set_name( $line_item->name );

			// Taxes
			$this->set_line_item_taxes( $item, $line_item );

			$item->save();

			$order->add_item( $item );
			$this->order_items_mapping[ $line_item->id ] = $item->get_id();
		}

		$order->save();
	}

	private function process_shipping_lines( $order, $shopify_order ) {
		foreach ( $shopify_order->shipping_lines as $shipping_line ) {
			$line_item_id = 0;
			if ( isset( $this->order_items_mapping[ $shipping_line->id ] ) ) {
				WP_CLI::line( sprintf( 'Shipping line item %d already exists (%d). Updating.', $shipping_line->id, $this->order_items_mapping[ $shipping_line->id ] ) );
				$line_item_id = $this->order_items_mapping[ $shipping_line->id ];
			} else {
				WP_CLI::line( sprintf( 'Creating shipping line item %d', $shipping_line->id ) );
			}

			$item = new WC_Order_Item_Shipping( $line_item_id );
			$item->set_method_title( $shipping_line->title );
			$item->set_total( $shipping_line->price );
			$this->set_line_item_taxes( $item, $shipping_line );
			$item->save();
			$order->add_item( $item );
			$this->order_items_mapping[ $shipping_line->id ] = $item->get_id();
		}

		$order->save();
	}

	private function process_discount_lines( $order, $shopify_order ) {
		$order->remove_order_items( 'coupon' );

		foreach ( $shopify_order->discount_applications as $discount ) {
			$item = new WC_Order_Item_Coupon();
			$item->set_discount( $discount->value );
			if ( $discount->type === 'discount_code' ) {
				$item->set_code( $discount->code );
			} else {
				$item->set_code( $discount->title );
			}

			$order->add_item( $item );
		}

		$order->save();
	}

	private function process_shipment_tracking( $order, $shopify_order ) {
		if ( ! class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
			return;
		}

		if ( ! isset( $shopify_order->fulfillments ) ) {
			return;
		}

		WP_CLI::line( 'Processing shipment tracking.' );

		$st = WC_Shipment_Tracking_Actions::get_instance();
		$st->save_tracking_items( $order->get_id(), array() );

		foreach ( $shopify_order->fulfillments as $fulfillment ) {
			foreach ( $fulfillment->tracking_numbers as $index => $tracking_number ) {
				$st->add_tracking_item(
					$order->get_id(),
					array(
						'tracking_provider'        => '',
						'tracking_number'          => $tracking_number,
						'custom_tracking_link'     => $fulfillment->tracking_urls[ $index ],
						'custom_tracking_provider' => $fulfillment->tracking_company,
						'date_shipped'             => $fulfillment->created_at,
					)
				);
			}
		}
	}

	private function process_order_refunds( $order, $shopify_order ) {
		foreach ( $shopify_order->refunds as $shopify_refund ) {

			// Check if the refund exists
			$refunds = wc_get_orders(
				array(
					'limit'      => 1,
					'type'       => 'shop_order_refund',
					'meta_key'   => '_original_refund_id',
					'meta_value' => $shopify_refund->id,
				)
			);

			if ( count( $refunds ) > 0 ) {
				// Deleting the refund then create a new one so we can reuse the logic in wc_create_refund.
				WP_CLI::line( sprintf( 'Refund %d already exists (%d). Deleting to create a new one.', $shopify_refund->id, $refunds[0]->get_id() ) );
				wp_delete_post( $refunds[0]->get_id(), true );
			}

			// Refunded line items
			$refunded_line_items = array();
			foreach ( $shopify_refund->refund_line_items as $refund_line_item ) {
				$refunded_line_items[ $this->order_items_mapping[ $refund_line_item->line_item_id ] ] = array(
					'qty'          => $refund_line_item->quantity,
					'refund_total' => $refund_line_item->subtotal,
				);
			}

			$refund_total = 0;
			foreach ( $shopify_refund->transactions as $transaction ) {
				if ( $transaction->status === 'success' ) {
					$refund_total += $transaction->amount;
				}
			}

			// Create a refund
			$refund = wc_create_refund(
				array(
					'amount'       => $refund_total,
					'reason'       => $shopify_refund->note,
					'order_id'     => $order->get_id(),
					'line_items'   => $refunded_line_items,
					'date_created' => $shopify_refund->created_at,
				)
			);

			// Update refund date
			$refund->update_meta_data( '_refund_completed_date', $shopify_refund->processed_at );

			// Update refund transaction ID
			if ( count( $shopify_refund->transactions ) > 0 && property_exists( $shopify_refund->transactions[0], 'receipt' ) && property_exists( $shopify_refund->transactions[0]->receipt, 'refund_transaction_id' ) ) {
				$refund->update_meta_data( '_transaction_id', $shopify_refund->transactions[0]->receipt->refund_transaction_id );
			}

			// Store the Shopify refund ID
			$refund->update_meta_data( '_original_refund_id', $shopify_refund->id );

			$refund->save();
		}
	}

	private function find_line_item_product( $line_item ) {
		$product_id   = 0;
		$variation_id = 0;
		if ( $line_item->sku ) {
			$_id = wc_get_product_id_by_sku( $line_item->sku );
			if ( $_id ) {
				$product_id = $_id;
				$_product   = wc_get_product( $_id );
				if ( is_a( $_product, 'WC_Product' ) && $_product->is_type( 'variation' ) ) {
					$product_id   = $_product->get_parent_id();
					$variation_id = $_product->get_id();
				}
			}
		} elseif ( $line_item->product_exists ) {
			$_products = wc_get_products(
				array(
					'limit'      => 1,
					'meta_key'   => '_original_product_id',
					'meta_value' => $line_item->product_id,
				)
			);

			if ( count( $_products ) === 1 ) {
				$product_id = $_products[0]->get_id();
				if ( $_products[0]->is_type( 'variable' ) ) {
					$migration_data = $_products[0]->get_meta( '_migration_data', true ) ?: array();
					if ( isset( $migration_data['variations_mapping'][ $line_item->variant_id ] ) ) {
						$variation_id = $migration_data['variations_mapping'][ $line_item->variant_id ];
					}
				}
			}
		}

		return array( $product_id, $variation_id );
	}

	private function set_line_item_taxes( $line_item, $shopify_line_item ) {
		if ( empty( $this->order_tax_rate_ids_mapping ) || empty( $shopify_line_item->tax_lines ) ) {
			return;
		}

		$taxes = array(
			'subtotal' => array(),
			'total'    => array(),
		);

		foreach ( $shopify_line_item->tax_lines as $tax_line ) {
			if ( ! isset( $this->order_tax_rate_ids_mapping[ $tax_line->title ] ) ) {
				continue;
			}
			$tax_rate_id                       = $this->order_tax_rate_ids_mapping[ $tax_line->title ];
			$taxes['subtotal'][ $tax_rate_id ] = $tax_line->price;
			$taxes['total'][ $tax_rate_id ]    = $tax_line->price;
		}

		$line_item->set_taxes( $taxes );
	}
}
