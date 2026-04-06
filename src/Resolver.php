<?php
/**
 * Restricted service resolver for factory functions and bootstrap code.
 *
 * @package WPDI
 */

namespace WPDI;

use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use WPDI\Exceptions\Container_Exception;

/**
 * Read-only view of the Container, exposing only get() and has().
 *
 * Interface Segregation: callers that only need to resolve services cannot
 * accidentally call bind(), load_config(), or clear() on the container.
 * Used by factory functions in wpdi-config.php and Scope::bootstrap().
 */
class Resolver {

	/**
	 * Backing container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Backing container instance.
	 */
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
	 * @throws Circular_Dependency_Exception When depending on a parent class.
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
