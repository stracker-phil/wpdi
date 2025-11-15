<?php

declare( strict_types = 1 );

namespace WPDI;

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
