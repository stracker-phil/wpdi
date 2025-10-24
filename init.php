<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/exceptions/class-wpdi-exception.php';
require_once __DIR__ . '/src/exceptions/class-container-exception.php';
require_once __DIR__ . '/src/exceptions/class-not-found-exception.php';
require_once __DIR__ . '/src/exceptions/class-circular-dependency-exception.php';
require_once __DIR__ . '/src/class-auto-discovery.php';
require_once __DIR__ . '/src/class-compiler.php';
require_once __DIR__ . '/src/class-container.php';
require_once __DIR__ . '/src/class-scope.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/commands/class-compile-command.php';
}