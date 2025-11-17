<?php

declare( strict_types = 1 );

namespace WPDI;

require_once __DIR__ . '/Exceptions/Wpdi_Exception.php';
require_once __DIR__ . '/Exceptions/Container_Exception.php';
require_once __DIR__ . '/Exceptions/Not_Found_Exception.php';
require_once __DIR__ . '/Exceptions/Circular_Dependency_Exception.php';
require_once __DIR__ . '/Auto_Discovery.php';
require_once __DIR__ . '/Compiler.php';
require_once __DIR__ . '/Cache_Manager.php';
require_once __DIR__ . '/Resolver.php';
require_once __DIR__ . '/Container.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/Commands/cli.php';
}

/**
 * Base class for WordPress modules using WPDI (plugins, themes, etc.)
 */
abstract class Scope {
	/**
	 * Initialize the module
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__).
	 */
	public function __construct( string $scope_file ) {
		$container = new Container();
		$container->initialize( $scope_file );
		$this->bootstrap( $container->resolver() );
	}

	/**
	 * Composition root - only place where service location happens
	 *
	 * @param Resolver $resolver Service resolver with get() and has() methods.
	 */
	abstract protected function bootstrap( Resolver $resolver ): void;
}
