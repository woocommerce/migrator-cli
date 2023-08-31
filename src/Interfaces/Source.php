<?php

namespace Migrator\Interfaces;

interface Source {
	const IDENTIFIER = '';

	const CREDENTIALS = [];
	
	public function get_products();

	public function get_orders();
}