<?php

class Migrator_Cli_Payment_Methods {

	const OLD_CUSTOMER_ID_POS = 0;
	const OLD_SOURCE_ID_POS   = 1;
	const NEW_CUSTOMER_ID_POS = 2;
	const NEW_SOURCE_ID_POS   = 3;

	const ORIGINAL_PAYMENT_GATEWAY_KEY   = '_original_payment_gateway';
	const ORIGINAL_PAYMENT_METHOD_ID_KEY = '_original_payment_method_id';
	const ORIGINAL_PAYMENT_LAST_4        = '_original_payment_last_4';

	public function woopayments( $assoc_args ) {
		if ( ! class_exists( 'WC_Payments_Customer_Service' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active%n' ) );
			die();
		}

		if ( ! is_file( $assoc_args['migration_file'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RFile not found%n' ) );
			die();
		}

//		$this->add_pan_import_data( $assoc_args['migration_file'] );
//		$this->update_orders_or_subscriptions( 'shop_order' );
		$this->update_orders_or_subscriptions( 'shop_subscription' );
	}

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

			$token->update_meta_data( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY, $data[ self::OLD_SOURCE_ID_POS ] );
			$token->save_meta_data();
		}
	}

	private function is_csv_correctly_formatted( $data ) {
		return 'customer_id_old' === $data[ self::OLD_CUSTOMER_ID_POS ] &&
		'source_id_old' === $data[ self::OLD_SOURCE_ID_POS ] &&
		'customer_id_new' === $data[ self::NEW_CUSTOMER_ID_POS ] &&
		'source_id_new' === $data[ self::NEW_SOURCE_ID_POS ];
	}

	private function get_customer_token( $user_id, $stripe_token_id ) {
		$saved_payment_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		foreach ( $saved_payment_tokens as $token ) {
			if ( $token->get_token() === $stripe_token_id ) {
				return $token;
			}
		}
	}

	private function get_customer_token_by_old_payment_method_id( $user_id, $old_payment_method_id, $old_payment_method_last_4 ) {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		foreach ( $tokens as $token ) {
			if ( $token->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY ) === $old_payment_method_id ) {
				if ( $old_payment_method_last_4 === $token->get_meta( 'last4' ) ) {
					return $token;
				} else {
					WP_CLI::line( WP_CLI::colorize( '%RMissmatch Payment Token last 4:%n' ) . $old_payment_method_id );
				}
			}
		}
	}

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

	private function update_orders_or_subscriptions( $type ) {
		$page                  = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type' => $type,
					'status' => 'all',
					'limit' => 100,
					'paged' => $page,
				)
			);

			/** @var WC_Order|WC_Subscription $order */
			foreach ( $orders as $order ) {
				switch ( $order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) ) {
					case 'shopify_payments':
						$this->process_shopify_payments( $order );
						break;
					default:
						WP_CLI::line( WP_CLI::colorize( ' %rUnkown payment gateway:%n ' ) . $order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_GATEWAY_KEY ) );
				}
			}

			++$page;
		} while ( $orders );
	}

	/**
	 * Extracted from https://github.com/Automattic/woocommerce-payments/blob/92525c2a637bf592ec412bb0a979ab91862575d1/includes/class-wc-payments-customer-service.php#L407-L416
	 * @return string
	 * @throws Exception
	 */
	public static function get_customer_id_option(): string {
		return WC_Payments::mode()->is_test()
			? WC_Payments_Customer_Service::WCPAY_TEST_CUSTOMER_ID_OPTION
			: WC_Payments_Customer_Service::WCPAY_LIVE_CUSTOMER_ID_OPTION;
	}

	private function process_shopify_payments( WC_Order|WC_Subscription $order ) {
		$old_payment_method_id     = $order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_METHOD_ID_KEY );
		$old_payment_method_last_4 = $order->get_meta( Migrator_Cli_Payment_Methods::ORIGINAL_PAYMENT_LAST_4 );
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
