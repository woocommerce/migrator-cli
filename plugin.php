<?php
/**
 * Plugin Name:     Migrator
 * Version:         0.1.0
 *
 * @package         Migrator
 */

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', function () {
	( new Migrator\Main )->init();
} );
