<?php

class Migrator_Cli_Payment_Methods {

	const OLD_CUSTOMER_ID_POS = 0;
	const OLD_SOURCE_ID_POS   = 1;
	const NEW_CUSTOMER_ID_POS = 2;
	const NEW_SOURCE_ID_POS   = 3;

	public function woopayments( $assoc_args ) {
		if ( ! class_exists( 'WC_Payments_Customer_Service' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RWooPayments is not active%n' ) );
			die();
		}

		if ( ! is_file( $assoc_args['migration_file'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RFile not found%n' ) );
			die();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $assoc_args['migration_file'] );
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

			$token = $this->get_customer_token( $user, $data[ self::NEW_SOURCE_ID_POS ] );

			if ( ! $token ) {
				WP_CLI::line( WP_CLI::colorize( '%RToken not found:%n ' ) . $data[ self::NEW_SOURCE_ID_POS ] );
				continue;
			}

			$token->update_meta_data( '_original_payment_method_id', $data[ self::OLD_SOURCE_ID_POS ] );
			$token->save_meta_data();
		}
	}

	private function is_csv_correctly_formatted( $data ) {
		return 'customer_id_old' === $data[ self::OLD_CUSTOMER_ID_POS ] &&
		'source_id_old' === $data[ self::OLD_SOURCE_ID_POS ] &&
		'customer_id_new' === $data[ self::NEW_CUSTOMER_ID_POS ] &&
		'source_id_new' === $data[ self::NEW_SOURCE_ID_POS ];
	}

	private function get_customer_token( $user, $stripe_token_id ) {
		$saved_payment_tokens = WC_Payment_Tokens::get_customer_tokens( $user->ID );

		foreach ( $saved_payment_tokens as $token ) {
			if ( $token->get_token() === $stripe_token_id ) {
				return $token;
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
}
