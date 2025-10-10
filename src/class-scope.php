<?php

declare( strict_types = 1 );

namespace WPDI;

/**
 * Base class for WordPress modules using WPDI (plugins, themes, etc.)
 */
abstract class Scope {
	private Container $container;

	public function __construct() {
		$this->container = new Container();
		$this->container->initialize( $this->get_base_path() );
		$this->bootstrap();
	}

	/**
	 * Get service from container (only available in this class)
	 */
	protected function get( string $class ) : mixed {
		return $this->container->get( $class );
	}

	/**
	 * Check if service exists
	 */
	protected function has( string $class ) : bool {
		return $this->container->has( $class );
	}

	/**
	 * Get base path for auto-discovery (plugin, theme, or module directory)
	 */
	protected function get_base_path() : string {
		$reflection = new \ReflectionClass( $this );

		return dirname( $reflection->getFileName() );
	}

	/**
	 * Composition root - only place where service location happens
	 */
	abstract protected function bootstrap() : void;
}