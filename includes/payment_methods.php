<?php

class Migrator_CLI_Payment_Methods {

	public function import_stripe_data_into_woopayments() {

		Migrator_CLI_Utils::health_check();

		if ( ! class_exists( 'WC_Payments' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active' ) );
			die();
		}

		$request = \WCPay\Core\Server\Request::get( WC_Payments_API_Client::CUSTOMERS_API );
		$result = $request->send();
		$stripe_customers = $result['data'];

		$this->import_customers_data( $stripe_customers );
		WP_CLI::line( 'Done' );
	}

	private function import_customers_data( $stripe_customers ) {
		foreach ( $stripe_customers as $stripe_customer ) {

			$user = get_user_by('email', $stripe_customer['email'] );
			if ( ! $user || is_wp_error( $user ) || $user->ID === 0 ) {
				WP_CLI::line( WP_CLI::colorize( '%RCustomer not found: ' . $stripe_customer['email'] ) );
				continue;
			}

			update_user_option( $user->ID, $this->get_customer_id_option(), $stripe_customer['id'] );
			$this->import_payment_methods( $stripe_customer, $user );
		}
	}

	private function import_payment_methods( $stripe_customer, $user ) {
		$customer_service_api = WC_Payments::get_customer_service_api();
		$stripe_payment_methods = $customer_service_api->get_payment_methods_for_customer( $stripe_customer['id'] );

		foreach ( $stripe_payment_methods as $stripe_payment_method ) {
			$token = new WC_Payment_Token_CC();
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

	/**
	 * Extracted from https://github.com/Automattic/woocommerce-payments/blob/92525c2a637bf592ec412bb0a979ab91862575d1/includes/class-wc-payments-customer-service.php#L407-L416
	 */
	private function get_customer_id_option(): string {
		return WC_Payments::mode()->is_test()
			? WC_Payments_Customer_Service::WCPAY_TEST_CUSTOMER_ID_OPTION
			: WC_Payments_Customer_Service::WCPAY_LIVE_CUSTOMER_ID_OPTION;
	}
}
