<?php

use WCPay\Core\Server\Request;

class Migrator_CLI_WooPayments_Customers extends Request {

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 * @throws Invalid_Request_Parameter_Exception
	 */
	public function get_api(): string {
		return WC_Payments_API_Client::CUSTOMERS_API;
	}

	/**
	 * Returns the request's HTTP method.
	 */
	public function get_method(): string {
		return 'GET';
	}

	/**
	 * Stores the page.
	 *
	 * @param string $starting_after the last object id in the last page.
	 */
	public function set_starting_after( string $starting_after = '' ) {
		$this->set_param( 'starting_after', $starting_after );
	}

	/**
	 * Stores the page size.
	 *
	 * @param int $limit how many customers to get.
	 */
	public function set_per_page( int $limit = 1 ) {
		$this->set_param( 'limit', $limit );
	}
}
