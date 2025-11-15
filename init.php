<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/Exceptions/Wpdi_Exception.php';
require_once __DIR__ . '/src/Exceptions/Container_Exception.php';
require_once __DIR__ . '/src/Exceptions/Not_Found_Exception.php';
require_once __DIR__ . '/src/Exceptions/Circular_Dependency_Exception.php';
require_once __DIR__ . '/src/Auto_Discovery.php';
require_once __DIR__ . '/src/Compiler.php';
require_once __DIR__ . '/src/Cache_Manager.php';
require_once __DIR__ . '/src/Resolver.php';
require_once __DIR__ . '/src/Container.php';
require_once __DIR__ . '/src/Scope.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Commands/cli.php';
}
