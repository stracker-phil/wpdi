<?php

declare( strict_types = 1 );

namespace WPDI;

require_once __DIR__ . '/Exceptions/Wpdi_Exception.php';
require_once __DIR__ . '/Exceptions/Container_Exception.php';
require_once __DIR__ . '/Exceptions/Not_Found_Exception.php';
require_once __DIR__ . '/Exceptions/Circular_Dependency_Exception.php';
require_once __DIR__ . '/Class_Inspector.php';
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
	 * Define paths for autowiring class discovery
	 *
	 * Override to specify where autowirable classes are located.
	 * Paths are relative to the scope file directory.
	 *
	 * Return an empty array to disable auto-discovery (manual bindings only).
	 *
	 * @return array Relative directory paths (e.g., ['src', 'modules/auth/src'])
	 */
	protected function autowiring_paths(): array {
		return array( 'src' );
	}

	/**
	 * Initialize the module
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__).
	 */
	public function __construct( string $scope_file ) {
		$container = new Container();
		$container->initialize( $scope_file, $this->autowiring_paths() );
		$this->bootstrap( $container->resolver() );
	}

	/**
	 * Composition root - only place where service location happens
	 *
	 * @param Resolver $resolver Service resolver with get() and has() methods.
	 */
	abstract protected function bootstrap( Resolver $resolver ): void;
}
