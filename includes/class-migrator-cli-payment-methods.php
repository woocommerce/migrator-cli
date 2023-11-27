<?php

class Migrator_CLI_Payment_Methods {

	const OLD_CUSTOMER_ID_POS = 0;
	const OLD_SOURCE_ID_POS   = 1;
	const NEW_CUSTOMER_ID_POS = 2;
	const NEW_SOURCE_ID_POS   = 3;

	const ORIGINAL_PAYMENT_GATEWAY_KEY   = '_original_payment_gateway';
	const ORIGINAL_PAYMENT_METHOD_ID_KEY = '_original_payment_method_id';
	const ORIGINAL_PAYMENT_LAST_4        = '_original_payment_last_4';

	public function import_stripe_data_into_woopayments() {

		Migrator_CLI_Utils::health_check();

		if ( ! class_exists( 'WC_Payments' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active%n' ) );
			die();
		}

		$request          = \WCPay\Core\Server\Request::get( WC_Payments_API_Client::CUSTOMERS_API );
		$result           = $request->send();
		$stripe_customers = $result['data'];

		$this->import_customers_data( $stripe_customers );
		WP_CLI::line( 'Done' );
	}

	private function import_customers_data( $stripe_customers ) {
		foreach ( $stripe_customers as $stripe_customer ) {

			$user = get_user_by( 'email', $stripe_customer['email'] );
			if ( ! $user || is_wp_error( $user ) || 0 === $user->ID ) {
				WP_CLI::line( WP_CLI::colorize( '%RCustomer not found:%n ' . $stripe_customer['email'] ) );
				continue;
			}

			WP_CLI::line( 'Processing customer : ' . $stripe_customer['email'] . '(' . $user->ID . ')' );
			update_user_option( $user->ID, self::get_customer_id_option(), $stripe_customer['id'] );
			$this->import_payment_methods( $stripe_customer, $user );
		}
	}

	private function import_payment_methods( $stripe_customer, $user ) {
		$payments_api_client    = WC_Payments::get_payments_api_client();
		$stripe_payment_methods = $payments_api_client->get_payment_methods( $stripe_customer['id'], 'card' )['data'];

		$saved_payment_tokens = WC_Payment_Tokens::get_customer_tokens( $user->ID );

		foreach ( $stripe_payment_methods as $stripe_payment_method ) {

			// Prevents duplication of payment methods.
			$token = $this->search_payment_token_by_stripe_id( $saved_payment_tokens, $stripe_payment_method['id'] );

			if ( ! $token ) {
				$token = new WC_Payment_Token_CC();
			}

			$token->set_gateway_id( \WCPay\Payment_Methods\CC_Payment_Gateway::GATEWAY_ID );
			$token->set_expiry_month( $stripe_payment_method['card']['exp_month'] );
			$token->set_expiry_year( $stripe_payment_method['card']['exp_year'] );
			$token->set_card_type( strtolower( $stripe_payment_method['card']['brand'] ) );
			$token->set_last4( $stripe_payment_method['card']['last4'] );

			$token->set_token( $stripe_payment_method['id'] );
			$token->set_user_id( $user->ID );
			$token->save();
		}
	}

	private function search_payment_token_by_stripe_id( $saved_payment_tokens, $stripe_payment_method_id ) {
		foreach ( $saved_payment_tokens as $saved_payment_token ) {
			if ( $stripe_payment_method_id === $saved_payment_token->get_token() ) {
				return $saved_payment_token;
			}
		}
	}


	/**
	 * Reads the mapping csv generated by Stripe and updates
	 * the orders and subscriptions to the new methods.
	 *
	 * @param array $assoc_args containing the mapping_file absolute path.
	 * @return void
	 */
	public function update_orders_and_subscriptions_payment_methods( $assoc_args ) {
		if ( ! class_exists( 'WC_Payments_Customer_Service' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active%n' ) );
			die();
		}

		if ( ! is_file( $assoc_args['migration_file'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RFile not found%n' ) );
			die();
		}

		WP_CLI::line( 'Updating customers' );
		$this->add_pan_import_data( $assoc_args['migration_file'] );
		WP_CLI::line( 'Updating orders' );
		$this->update_orders_or_subscriptions( 'shop_order' );
		WP_CLI::line( 'Updating subscriptions' );
		$this->update_orders_or_subscriptions( 'shop_subscription' );
	}

	/**
	 * Reads the mapping file and sets the mapping info
	 * to the customer and payment methods.
	 *
	 * @param string $migration_file_path the absolute path to the migration file.
	 */
	private function add_pan_import_data( $migration_file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $migration_file_path );
		$lines    = explode( "\n", $contents );

		foreach ( $lines as $i => $line ) {
			$line = str_replace( '"', '', $line );
			$data = explode( ',', $line );

			if ( 0 === $i && ! $this->is_csv_correctly_formatted( $data ) ) {
				WP_CLI::line( WP_CLI::colorize( '%RCSV not correctly formatted%n' ) );
				die();
			}

			if ( 0 === $i ) {
				continue;
			}

			WP_CLI::line( 'Updating customer: ' . $data[ self::NEW_CUSTOMER_ID_POS ] );
			$user = $this->get_user_by_stripe_id( $data[ self::NEW_CUSTOMER_ID_POS ] );

			if ( ! $user ) {
				continue;
			}

			update_user_option( $user->ID, '_original_customer_id', $data[ self::OLD_CUSTOMER_ID_POS ] );

			$token = $this->get_customer_token( $user->ID, $data[ self::NEW_SOURCE_ID_POS ] );

			if ( ! $token ) {
				WP_CLI::line( WP_CLI::colorize( '%RToken not found:%n ' ) . $data[ self::NEW_SOURCE_ID_POS ] );
				continue;
			}

			$token->update_meta_data( self::ORIGINAL_PAYMENT_METHOD_ID_KEY, $data[ self::OLD_SOURCE_ID_POS ] );
			$token->save_meta_data();
		}
	}

	/**
	 * Checks if the mapping csv file is correctly formatted.
	 * It checks the header to make sure the data is in the correct column.
	 *
	 * @param array $data the header data.
	 * @return bool
	 */
	private function is_csv_correctly_formatted( $data ) {
		return 'customer_id_old' === $data[ self::OLD_CUSTOMER_ID_POS ] &&
			'source_id_old' === $data[ self::OLD_SOURCE_ID_POS ] &&
			'customer_id_new' === $data[ self::NEW_CUSTOMER_ID_POS ] &&
			'source_id_new' === $data[ self::NEW_SOURCE_ID_POS ];
	}

	/**
	 * Searches a customer token by it's stripe token id.
	 *
	 * @param int $user_id
	 * @param string $stripe_token_id
	 * @return void|WC_Payment_Token
	 */
	private function get_customer_token( $user_id, $stripe_token_id ) {
		$saved_payment_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		foreach ( $saved_payment_tokens as $token ) {
			if ( $token->get_token() === $stripe_token_id ) {
				return $token;
			}
		}
	}

	/**
	 * Gets the customer token by it's old payment method id.
	 *
	 * @param int $user_id the user to be searched.
	 * @param string $old_payment_method_id the old payment method id.
	 * @param string $old_payment_method_last_4 the last 4 digits of the old payment method to check if the new one matches.
	 * @return void|WC_Payment_Token
	 */
	private function get_customer_token_by_old_payment_method_id( $user_id, $old_payment_method_id, $old_payment_method_last_4 ) {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		foreach ( $tokens as $token ) {
			if ( $token->get_meta( self::ORIGINAL_PAYMENT_METHOD_ID_KEY ) === $old_payment_method_id ) {
				if ( (int) $old_payment_method_last_4 === (int) $token->get_meta( 'last4' ) ) {
					return $token;
				} else {
					WP_CLI::line( WP_CLI::colorize( '%RMissmatch Payment Token last 4:%n' ) . $old_payment_method_id );
				}
			}
		}
	}

	/**
	 * Searches a WP_User by the stripe id.
	 *
	 * @param $stripe_id
	 * @return WP_User
	 * @throws Exception
	 */
	private function get_user_by_stripe_id( $stripe_id ) {
		$users = get_users(
			array(
				'meta_key'   => 'wp_' . self::get_customer_id_option(),
				'meta_value' => $stripe_id,
			)
		);

		if ( is_wp_error( $users ) || ! $users ) {
			WP_CLI::line( WP_CLI::colorize( '%RCustomer not found%n' ) );
			return;
		}

		if ( count( $users ) > 1 ) {
			WP_CLI::line( WP_CLI::colorize( '%RMultiple Customers found%n' ) );
			return;
		}

		return reset( $users );
	}

	/**
	 * Sets the new payment method for orders and subscriptions
	 *
	 * @param string $type shop_order|shop_subscription.
	 */
	private function update_orders_or_subscriptions( $type ) {
		$page = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type'   => $type,
					'status' => 'all',
					'limit'  => 100,
					'paged'  => $page,
				)
			);

			/** @var WC_Order|WC_Subscription $order */
			foreach ( $orders as $order ) {
				WP_CLI::line( 'Processing ' . $type . ': ' . $order->get_id() );

				switch ( $order->get_meta( self::ORIGINAL_PAYMENT_GATEWAY_KEY ) ) {
					case 'shopify_payments':
						$this->process_shopify_payments( $order );
						break;
					case null:
						WP_CLI::line( WP_CLI::colorize( ' %RPayment gateway not set%n ' ) );
						break;
					default:
						WP_CLI::line( WP_CLI::colorize( '%RUnkown payment gateway:%n ' ) . $order->get_meta( self::ORIGINAL_PAYMENT_GATEWAY_KEY ) );
				}
			}

			++$page;
		} while ( $orders );
	}

	/**
	 * Returns the meta_key where the stripe customer_id is stored without the wp_ at the beginning.
	 * Extracted from https://github.com/Automattic/woocommerce-payments/blob/92525c2a637bf592ec412bb0a979ab91862575d1/includes/class-wc-payments-customer-service.php#L407-L416
	 * @return string
	 * @throws Exception
	 */
	private static function get_customer_id_option(): string {
		return WC_Payments::mode()->is_test()
			? WC_Payments_Customer_Service::WCPAY_TEST_CUSTOMER_ID_OPTION
			: WC_Payments_Customer_Service::WCPAY_LIVE_CUSTOMER_ID_OPTION;
	}

	/**
	 * Updates shopify_payments to WooPayments.
	 *
	 * @param WC_Order $order the order or subscription to be updated
	 * @throws WC_Data_Exception
	 */
	private function process_shopify_payments( WC_Order $order ) {
		$old_payment_method_id     = $order->get_meta( self::ORIGINAL_PAYMENT_METHOD_ID_KEY );
		$old_payment_method_last_4 = $order->get_meta( self::ORIGINAL_PAYMENT_LAST_4 );
		$customer                  = new WC_Customer( $order->get_customer_id() );

		$token = $this->get_customer_token_by_old_payment_method_id( $order->get_customer_id(), $old_payment_method_id, $old_payment_method_last_4 );

		if ( ! $token ) {
			WP_CLI::line( WP_CLI::colorize( '%RPayment Token not found:%n ' ) . $old_payment_method_id );
			return;
		}

		$order->set_payment_method( WC_Payments::get_registered_card_gateway()::GATEWAY_ID );
		$order->update_meta_data( WC_Payments_Order_Service::CUSTOMER_ID_META_KEY, $customer->get_meta( 'wp_' . self::get_customer_id_option() ) );
		$order->add_payment_token( $token );
		$order->save();
	}
}
