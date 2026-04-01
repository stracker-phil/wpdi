<?php
/**
 * Service resolver providing limited container access
 *
 * This class provides a restricted API for service resolution,
 * exposing only get() and has() methods. Used by factory functions
 * in wpdi-config.php and the Scope::bootstrap() method.
 */

namespace WPDI;

use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use WPDI\Exceptions\Container_Exception;

class Resolver {
	private Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Get service from container
	 *
	 * @param string $id Service identifier (class or interface name).
	 * @return mixed Service instance.
	 *
	 * @throws Not_Found_Exception When the requested item does not exist.
	 * @throws Circular_Dependency_Exception When depending on a parent class
	 * @throws Container_Exception Dependency is not instantiable.
	 * @throws Container_Exception Unresolvable dependency.
	 */
	public function get( string $id ) {
		return $this->container->get( $id );
	}

	/**
	 * Check if a service exists in container
	 *
	 * @param string $id Service identifier (class or interface name).
	 * @return bool True if service exists.
	 */
	public function has( string $id ): bool {
		return $this->container->has( $id );
	}
}
