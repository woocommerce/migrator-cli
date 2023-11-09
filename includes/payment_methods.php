<?php

class Migrator_CLI_Payment_Methods {

	public function import_stripe_data_into_woopayments() {

		if ( ! class_exists( 'WC_Payments_Customer_Service' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active' ) );
			die();
		}

		$stripe_customers = $this->stripe_request( 'customers' );

		foreach ( $stripe_customers as $stripe_customer ) {

			$user = get_user_by('email', $stripe_customer['email'] );
			if ( ! $user || is_wp_error( $user ) || $user->ID === 0 ) {
				WP_CLI::line( 'Customer not found: ' . $stripe_customer['email'] );
				continue;
			}

			update_user_option( $user->ID, $this->get_customer_id_option(), $stripe_customer['id'] );

			$endpoint = 'customers/' . $stripe_customer['id'] . '/payment_methods';
			$stripe_payment_methods = $this->stripe_request( $endpoint );

			foreach ( $stripe_payment_methods as $stripe_payment_method ) {
				$token = new WC_Payment_Token_CC();
				$token->set_gateway_id( 'woocommerce_payments' );
				$token->set_expiry_month( $stripe_payment_method['card']['exp_month'] );
				$token->set_expiry_year( $stripe_payment_method['card']['exp_year'] );
				$token->set_card_type( strtolower( $stripe_payment_method['card']['brand'] ) );
				$token->set_last4( $stripe_payment_method['card']['last4'] );

				$token->set_token( $stripe_payment_method['id'] );
				$token->set_user_id( $user->ID );
				$token->save();
			}
		}

		WP_CLI::line( 'Done' );
	}

	private function stripe_request( $endpoint, $type = 'GET', $args = [] ) {
		$response = wp_safe_remote_request(
			'https://api.stripe.com/v1/' . $endpoint,
			[
				'method'  => $type,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( STRIPE_SECRET_KEY . ':' ),
					'Stripe-Account' => STRIPE_CONNECTED_ACCOUNT
				],
			]
		);

		$this->check_response_for_platform_errors( $response );

		$response = json_decode( $response['body'], true );
		return $response['data'];
	}

	private function check_response_for_platform_errors( $response ) {
		// for WP_Error, add Stripe's error message too.
		if ( is_wp_error( $response ) && ! empty( $response->get_error_message() ) ) {
			$error_message = 'Received error from Stripe - ' . $response->get_error_message();
			WP_CLI::line( WP_CLI::colorize( '%R' . $error_message . " Response: " . wp_json_encode( $response, JSON_PRETTY_PRINT ) ) );
			die();
		}

		// response should not be an error and should always contain the expected fields.
		if ( is_wp_error( $response )
			|| ! is_array( $response )
			|| empty( $response['body'] )
			|| empty( $response['response'] )
			|| ! isset( $response['response']['code'] )
		) {
			WP_CLI::line( WP_CLI::colorize( '%RUnexpected Stripe response.' . " Response: " . wp_json_encode( $response, JSON_PRETTY_PRINT ) ) );
			die();
		}

		// HTTP 401 and 403 codes indicate problems with the platform's secret keys.
		$http_code = $response['response']['code'];
		if ( 401 === $http_code || 403 === $http_code ) {
			WP_CLI::line( WP_CLI::colorize( '%RReceived error from Stripe (HTTP ' . $http_code . '). Response: ' . wp_json_encode( $response, JSON_PRETTY_PRINT ) ) );
			die();
		}
	}


	/**
	 * Extracted from https://github.com/Automattic/woocommerce-payments/blob/92525c2a637bf592ec412bb0a979ab91862575d1/includes/class-wc-payments-customer-service.php#L407-L416
	 * @return string
	 * @throws Exception
	 */
	private function get_customer_id_option(): string {
		return WC_Payments::mode()->is_test()
			? WC_Payments_Customer_Service::WCPAY_TEST_CUSTOMER_ID_OPTION
			: WC_Payments_Customer_Service::WCPAY_LIVE_CUSTOMER_ID_OPTION;
	}
}
