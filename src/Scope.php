<?php

declare( strict_types = 1 );

namespace WPDI;

/**
 * Base class for WordPress modules using WPDI (plugins, themes, etc.)
 */
abstract class Scope {
	private Container $container;

	/**
	 * Initialize the module
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__)
	 */
	public function __construct( string $scope_file ) {
		$this->container = new Container();
		$this->container->initialize( $scope_file );
		$this->bootstrap();
	}

	/**
	 * Get service from container (only available in this class)
	 *
	 * @return mixed Service instance
	 */
	protected function get( string $class ) {
		return $this->container->get( $class );
	}

	/**
	 * Check if service exists
	 */
	protected function has( string $class ): bool {
		return $this->container->has( $class );
	}

	/**
	 * Composition root - only place where service location happens
	 */
	abstract protected function bootstrap(): void;
}
