<?php

class Migrator_CLI_Subscriptions {

	/**
	 * Imports Skio subscriptions.
	 *
	 * @param $assoc_args ['subscriptions_export_file'] ['orders_export_file']
	 */
	public function import( $assoc_args ) {

		try {
			Migrator_CLI_Utils::health_check();

			if ( ! isset( $assoc_args['subscriptions_export_file'] ) || ! isset( $assoc_args['orders_export_file'] ) ) {
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Export Files not provided. Go to Skio dashboard > Export and export both subscriptions and orders. Then pass the file path to --subscriptions_export_file and --orders_export_file args' );
				return;
			}

			$skio_orders        = $this->get_data_from_file( $assoc_args['orders_export_file'] );
			$skio_subscriptions = $this->get_data_from_file( $assoc_args['subscriptions_export_file'] );

			Migrator_CLI_Utils::disable_sequential_orders();

			WP_CLI::line( 'Adding Subscription ids to orders' );
			$this->add_subscription_id_to_orders( $skio_orders );

			WP_CLI::line( 'Creating Subscriptions' );
			$this->create_or_update_subscriptions( $skio_subscriptions );

			Migrator_CLI_Utils::enable_sequential_orders();
		} catch ( \Exception $e ) {
			WP_CLI::line( WP_CLI::colorize( '%RError:%n ' . $e->getMessage() ) );
		}

		WP_CLI::line( WP_CLI::colorize( '%GDone%n' ) );
	}

	/**
	 * Clones the line item and adds it to the subscription.
	 *
	 * @param WC_Order_Item $item the item to be cloned.
	 * @param WC_Subscription $subscription the subscription to add the line item to.
	 */
	private function clone_item_to_subscription( $item, $subscription ) {
		$new_item = clone $item;
		$new_item->set_id( 0 );
		$new_item->set_order_id( $subscription->get_id() );
		$new_item->save();

		$subscription->add_item( $new_item );
	}

	/**
	 * Gets the json data from a file.
	 *
	 * @param string $file the file path.
	 * @return array
	 */
	private function get_data_from_file( $file ) {
		if ( ! is_file( $file ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'File not found: ' . $file );
			die();
		}

		return wp_json_file_decode( $file, array( 'associative' => true ) );
	}

	/**
	 * Add the Shopify subscription ids to Woo orders.
	 *
	 * @param array $skio_orders the Skio orders data.
	 */
	private function add_subscription_id_to_orders( $skio_orders ) {
		foreach ( $skio_orders as $key => $skio_order ) {
			WP_CLI::line( 'Processing order: ' . $skio_order['orderPlatformNumber'] );

			$args = array(
				'meta_key'     => '_order_number',
				'meta_value'   => preg_replace("/[^0-9]/", "", $skio_order['orderPlatformNumber'] ),
				'meta_compare' => '=',
				'numberposts'  => 1,
			);

			$skio_orders = wc_get_orders( $args );

			if ( ! $skio_orders ) {
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Woo Order not found for Shopify Order: ' . $skio_order['orderPlatformNumber'] );
				continue;
			}

			/** @var WC_Order $skio_order */
			$order = reset( $skio_orders );
			$order->update_meta_data( '_skio_subscription_id', $skio_order['subscriptionId'] );
			$order->save_meta_data();

			if ( $key % 100 === 0 ) {
				Migrator_CLI_Utils::reset_in_memory_cache();
			}
		}
	}

	/**
	 * Creates or updates subscriptions.
	 *
	 * @param array $skio_subscriptions the Skio subscriptions array.
	 */
	private function create_or_update_subscriptions( $skio_subscriptions ) {
		foreach ( $skio_subscriptions as $key => $skio_subscription ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Processing subscription: ' . $skio_subscription['subscriptionId'] );

			// Get all the orders for that subscription.
			$args            = array(
				'meta_key'     => '_skio_subscription_id',
				'meta_value'   => $skio_subscription['subscriptionId'],
				'meta_compare' => '=',
				'numberposts'  => -1,
				'orderby'      => 'date_created',
				'order'        => 'ASC',
			);
			$existing_orders = wc_get_orders( $args );

			if ( ! $existing_orders ) {
				if ( 'CANCELLED' !== $skio_subscription['status'] ) {
					WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Woo Order not found for Skio Subscription: ' . $skio_subscription['subscriptionId'] );
				}
				continue;
			}

			// Used as the order that originated the subscription.
			$oldest_order = reset( $existing_orders );
			// Most up to date order to make sure the subscription is correct.
			$latest_order = end( $existing_orders );

			$subscription = $this->get_or_create_subscription( $skio_subscription, $oldest_order );

			if ( is_wp_error( $subscription ) ) {
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'When creating the subscription: ' . $subscription->get_error_message() );
				continue;
			}

			$this->add_line_items( $subscription, $latest_order );
			$this->update_billing_address( $subscription, $latest_order );
			$this->update_shipping_address( $subscription, $latest_order );

			$subscription->set_requires_manual_renewal( true );
			$subscription->set_payment_method( $latest_order->get_payment_method() );
			$subscription->set_payment_method_title( $latest_order->get_payment_method_title() );
			$subscription->set_shipping_total( $latest_order->get_shipping_total() );
			$subscription->set_discount_total( $latest_order->get_discount_total() );
			$subscription->update_meta_data( '_skio_subscription_id', $skio_subscription['subscriptionId'] );

			$this->attatch_orders( $subscription, $existing_orders, $oldest_order );
			$this->set_subscription_status( $subscription, $skio_subscription );
			$this->process_payment_method( $subscription, $skio_subscription, $latest_order );

			$subscription->save();
			$subscription->calculate_totals();
			if ( $subscription->get_total() !== $latest_order->get_total() ) {
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Totals mismatch between last order and subscription. This could mean the coupon is wrong or some item is missing' );
			}

			if ( $key % 100 === 0 ) {
				Migrator_CLI_Utils::reset_in_memory_cache();
			}
		}
	}

	/**
	 * Gets or creates a new Woo subscription.
	 *
	 * @param array $skio_subscription the skio subscription data.
	 * @param WC_Order $oldest_order the oldest Woo order for this subscription.
	 * @return WC_Subscription
	 */
	private function get_or_create_subscription( $skio_subscription, $oldest_order ) {
		$args = array(
			'type'         => 'shop_subscription',
			'meta_key'     => '_skio_subscription_id',
			'meta_value'   => $skio_subscription['subscriptionId'],
			'meta_compare' => '=',
			'numberposts'  => 1,
			'status'       => 'any',
		);

		$existing_subscriptions = wcs_get_orders_with_meta_query( $args );

		if ( $existing_subscriptions ) {
			/** @var WC_Subscription $subscription */
			$subscription = end( $existing_subscriptions );

			$subscription->update_dates(
				array(
					'cancelled'                 => 0,
					'end'                       => 0,
					'next_payment'              => 0,
					'start'                     => 0,
					'date_created'              => 0,
					'date_modified'             => 0,
					'date_paid'                 => 0,
					'date_completed'            => 0,
					'last_order_date_created'   => 0,
					'trial_end'                 => 0,
					'last_order_date_paid'      => 0,
					'last_order_date_completed' => 0,
					'payment_retry'             => 0,
				)
			);

			$subscription->save();

			WP_CLI::line( 'Found existing subscription updating it instead' );
		} else {
			WP_CLI::line( 'Creating new subscription' );

			$create_date = date_create( $skio_subscription['createdAt'] );
			$create_date = date_format( $create_date, 'Y-m-d H:i:s' );

			$subscription = wcs_create_subscription(
				array(
					'status'           => '',
					'order_id'         => $oldest_order->get_id(),
					'customer_id'      => $oldest_order->get_customer_id(),
					'date_created'     => $create_date,
					'billing_interval' => $skio_subscription['billingPolicyIntervalCount'],
					'billing_period'   => mb_strtolower( $skio_subscription['billingPolicyInterval'] ),
				)
			);
		}

		WP_CLI::line( 'Woo Subscription id: ' . $subscription->get_id() );

		return $subscription;
	}

	/**
	 * Copies line items from a order to a subscription.
	 *
	 * @param WC_Subscription $subscription the Woo subscription.
	 * @param WC_Order $latest_order the Woo order.
	 */
	private function add_line_items( $subscription, $latest_order ) {

		// Prevents duplication on updates.
		foreach ( $subscription->get_items( array( 'line_item', 'tax', 'shipping', 'coupon' ) ) as $subscription_item ) {
			$subscription->remove_item( $subscription_item->get_id() );
		}

		foreach ( $latest_order->get_items( array( 'line_item', 'tax', 'shipping' ) ) as $item ) {
			$this->clone_item_to_subscription( $item, $subscription );
		}

		foreach ( $latest_order->get_items( array( 'coupon' ) ) as $item ) {

			$coupon = new WC_Coupon( $item->get_code() );

			// Coupons that apply to the first cycle only shouldn't be added to the subscription
			if ( 'yes' === $coupon->get_meta( '_apply_to_first_cycle_only' ) || 1 === (int) $coupon->get_meta( '_wcs_number_payments' ) ) {
				WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'This coupon should only be used in the first cycle. Not applying to Subscription. Total mismatch may happen. But that should be ok' );
				return;
			}

			$result = $subscription->apply_coupon( $item->get_code() );
			if ( ! $result ) {
				WP_CLI::line( WP_CLI::colorize( '%RError%n ' ) . 'Could not apply coupon: ' . $item->get_code() );
			}

			if ( is_wp_error( $result ) ) {
				WP_CLI::line( WP_CLI::colorize( '%RError%n ' ) . 'Could not apply coupon: ' . $item->get_code() . ' ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Copies the billing address from an order into the subscription.
	 *
	 * @param WC_Subscription $subscription the Woo subscription.
	 * @param WC_Order $latest_order the latest Woo order for that subscription.
	 */
	private function update_billing_address( $subscription, $latest_order ) {
		$subscription->set_billing_first_name( $latest_order->get_billing_first_name() );
		$subscription->set_billing_last_name( $latest_order->get_billing_last_name() );
		$subscription->set_billing_company( $latest_order->get_billing_company() );
		$subscription->set_billing_address_1( $latest_order->get_billing_address_1() );
		$subscription->set_billing_address_2( $latest_order->get_billing_address_2() );
		$subscription->set_billing_city( $latest_order->get_billing_city() );
		$subscription->set_billing_state( $latest_order->get_billing_state() );
		$subscription->set_billing_postcode( $latest_order->get_billing_postcode() );
		$subscription->set_billing_country( $latest_order->get_billing_country() );
		$subscription->set_billing_phone( $latest_order->get_billing_phone() );
	}

	/**
	 * Copies the shipping address from an order into the subscription.
	 *
	 * @param WC_Subscription $subscription the Woo subscription.
	 * @param WC_Order $latest_order the latest Woo order for that subscription.
	 */
	private function update_shipping_address( $subscription, $latest_order ) {
		$subscription->set_shipping_first_name( $latest_order->get_shipping_first_name() );
		$subscription->set_shipping_last_name( $latest_order->get_shipping_last_name() );
		$subscription->set_shipping_company( $latest_order->get_shipping_company() );
		$subscription->set_shipping_address_1( $latest_order->get_shipping_address_1() );
		$subscription->set_shipping_address_2( $latest_order->get_shipping_address_2() );
		$subscription->set_shipping_city( $latest_order->get_shipping_city() );
		$subscription->set_shipping_state( $latest_order->get_shipping_state() );
		$subscription->set_shipping_postcode( $latest_order->get_shipping_postcode() );
		$subscription->set_shipping_country( $latest_order->get_shipping_country() );
		$subscription->set_shipping_phone( $latest_order->get_shipping_phone() );
	}

	/**
	 * Attaches the orders to a subscription.
	 *
	 * @param WC_Subscription $subscription the Woo subscription.
	 * @param array $existing_orders the orders that should be attached to that subscription.
	 * @param WC_Order $oldest_order the oldest order for that subscription.
	 */
	private function attatch_orders( $subscription, $existing_orders, $oldest_order ) {
		foreach ( $existing_orders as $order ) {
			// Prevents adding the oldest order twice.
			if ( $order->get_id() === $oldest_order->get_id() ) {
				continue;
			}

			WCS_Related_Order_Store::instance()->add_relation( $order, $subscription, 'renewal' );
		}
	}

	/**
	 * Converts and sets the subscription status
	 *
	 * @param WC_Subscription $subscription the Woo subscription.
	 * @param array $skio_subscription the Skio subscription data.
	 */
	private function set_subscription_status( $subscription, $skio_subscription ) {

		if ( ! in_array( $skio_subscription['status'], array( 'ACTIVE', 'CANCELLED', 'FAILED' ), true ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Unknown subscription status: ' . $skio_subscription['status'] );
		}

		$subscription->set_status( mb_strtolower( $skio_subscription['status'] ) );

		/**
		 * Skio does not store failed orders.
		 * Set it as active to try again so the customer can update the payment method.
		 */
		if ( 'FAILED' === $skio_subscription['status'] ) {
			$subscription->set_status( 'active' );
		}

		if ( in_array( $skio_subscription['status'], array( 'ACTIVE', 'FAILED' ), true ) && isset( $skio_subscription['nextBillingDate'] ) ) {
			$next_payment = date_create( $skio_subscription['nextBillingDate'] );
			$next_payment = date_format( $next_payment, 'Y-m-d H:i:s' );
			$subscription->update_dates(
				array(
					'next_payment' => $next_payment,
				)
			);
		}

		if ( 'CANCELLED' === $skio_subscription['status'] && isset( $skio_subscription['cancelledAt'] ) ) {
			$cancelled_date = new DateTime( $skio_subscription['cancelledAt'] );
			$cancelled_date = date_format( $cancelled_date, 'Y-m-d H:i:s' );
			$subscription->update_dates(
				array(
					'cancelled' => $cancelled_date,
					'end'       => $cancelled_date,
				)
			);
		}
	}

	/**
	 * Saves the old payment method data into the subscription meta table
	 * so it can be used during the mapping update by
	 * Migrator_CLI_Payment_Methods::update_orders_and_subscriptions_payment_methods.
	 *
	 * @param WC_Subscription $subscription the subscription to be updated.
	 * @param array $skio_subscription the skio subscription data.
	 * @param WC_Order $latest_order the last order to be used to extract data from.
	 */
	private function process_payment_method( WC_Subscription $subscription, $skio_subscription, WC_Order $latest_order ) {

		$subscription->update_meta_data( '_payment_method_id', $latest_order->get_meta( '_payment_method_id' ) );
		$subscription->update_meta_data( '_payment_tokens', $latest_order->get_meta( '_payment_tokens' ) );

		// Case matters here.
		switch ( $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) ) {
			case 'shopify_payments':
				if ( ! class_exists( 'WC_Gateway_PPEC_Plugin' ) ) {
					WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'PayPal Express Plugin not installed. It will be necessary to process payments for this subscription' );
				}

				if ( (int) $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_LAST_4 ) !== (int) $skio_subscription['paymentMethodLastDigits'] ) {
					WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Mismatch in subscription payment method last 4' );
					return;
				}

				$subscription->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY, $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) );
				$subscription->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY, $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY ) );
				$subscription->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_LAST_4, $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_LAST_4 ) );
				break;
			// 'paypal' not 'PayPal' they are two different gateways.
			case 'paypal':
				if ( ! class_exists( 'WC_Gateway_PPEC_Plugin' ) ) {
					WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'PayPal Express Checkout Plugin not installed. It will be necessary to process payments for this subscription' );
				}

				$subscription->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY, $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) );
				$subscription->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY, $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY ) );

				$subscription->set_payment_method( 'ppec_paypal' );
				$subscription->update_meta_data( '_ppec_billing_agreement_id', $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY ) );
				break;
			default:
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Unsupported payment gateway: ' . $latest_order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) );
		}
	}
}
