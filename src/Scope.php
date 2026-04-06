<?php
/**
 * Base class for WordPress modules using WPDI.
 *
 * @package WPDI
 */

declare( strict_types = 1 );

namespace WPDI;

use WP_CLI;
use WPDI\Commands\Cli;
use WPDI\Exceptions\WPDI_Exception;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use WPDI\Exceptions\Not_Found_Exception;

require_once __DIR__ . '/version-check.php';

require_once __DIR__ . '/Exceptions/Wpdi_Exception.php';
require_once __DIR__ . '/Exceptions/Container_Exception.php';
require_once __DIR__ . '/Exceptions/Not_Found_Exception.php';
require_once __DIR__ . '/Exceptions/Circular_Dependency_Exception.php';
require_once __DIR__ . '/Class_Inspector.php';
require_once __DIR__ . '/Auto_Discovery.php';
require_once __DIR__ . '/Cache_Store.php';
require_once __DIR__ . '/Cache_Manager.php';
require_once __DIR__ . '/Resolver.php';
require_once __DIR__ . '/Container.php';

/*
 * Ensure the CLI is wired up and working as soon as this class is loaded.
 *
 * When the class is loaded conditionally, e.g. in a hook that does not fire
 * early enough for CLI setup, then this function can be safely called in your
 * app at the earliest possible time to ensure the presence of CLI commands:
 *
 * \WPDI\Commands\Cli::register_commands();
 */
Cli::register_commands();

/**
 * Base class for WordPress modules using WPDI (plugins, themes, etc.).
 *
 * Composition Root pattern: each module subclasses Scope, and its bootstrap()
 * method is the single place where service location occurs. After bootstrap(),
 * the container is discarded -- services communicate purely through
 * constructor-injected dependencies.
 */
abstract class Scope {

	/**
	 * Stores booted instances keyed by class name to prevent duplicate containers.
	 *
	 * @var array<string, static>
	 */
	private static array $booted = array();

	/**
	 * Boot the scope — idempotent, safe to call multiple times.
	 *
	 * The first time it's called, the `::bootstrap()` method is invoked
	 * and receives the DI Resolver. This is the only time the Resolver is
	 * available in the app lifecycle.
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__).
	 */
	public static function boot( string $scope_file ): void {
		if ( isset( self::$booted[ static::class ] ) ) {
			return;
		}

		try {
			self::$booted[ static::class ] = new static( $scope_file );
		} catch ( WPDI_Exception $e ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::error( $e->getMessage() );
			}

			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Clear the booted instance for this class.
	 *
	 * Intended for use in tests to reset the state between test cases.
	 */
	public static function clear(): void {
		unset( self::$booted[ static::class ] );
	}

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
	 * Determine the current environment type
	 *
	 * Override to provide a custom environment value (e.g., in non-WordPress contexts).
	 * In production, the cache is served as-is without staleness checks.
	 *
	 * @return string Environment type ('production', 'development', 'staging', 'local').
	 */
	protected function environment(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return wp_get_environment_type();
		}

		return 'development';
	}

	/**
	 * Initialize the module
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__).
	 * @throws Container_Exception When a dependency cannot be resolved.
	 * @throws Circular_Dependency_Exception When a circular dependency is detected.
	 * @throws Not_Found_Exception When a requested service is not found.
	 */
	protected function __construct( string $scope_file ) {
		$base_path       = dirname( $scope_file );
		$config_file     = $base_path . '/wpdi-config.php';
		$config_bindings = file_exists( $config_file ) ? require $config_file : array();

		$inspector     = new Class_Inspector();
		$store         = new Cache_Store( $base_path );
		$discovery     = new Auto_Discovery( $inspector );
		$cache_manager = new Cache_Manager(
			$store,
			$discovery,
			$inspector,
			$this->normalize_paths( $base_path, $this->autowiring_paths() ),
			$base_path,
			$this->environment()
		);

		$cached = $cache_manager->get_cache( $scope_file, $config_bindings );

		/*
		 * The DI container is intentionally a throw-away instance (not stored in a property).
		 *
		 * Principle: Composition Root — the container wires the object graph, then is discarded.
		 * Attention: A Resolver (narrow service locator) is passed to bootstrap only. Injecting
		 * the Resolver into domain services defeats this and should be avoided.
		 */
		$container = new Container();
		$container->load_compiled( $cached );

		$this->bootstrap( $container->resolver() );
	}

	/**
	 * Normalize relative autowiring paths to absolute paths
	 *
	 * @param string $base_path Base directory.
	 * @param array  $paths     Relative paths.
	 * @return array Absolute paths with trailing slashes removed.
	 */
	private function normalize_paths( string $base_path, array $paths ): array {
		$normalized = array();

		foreach ( $paths as $path ) {
			// Remove any .. to prevent traversal.
			$path = str_replace( '..', '', $path );

			// Convert relative to absolute.
			$absolute = $base_path . '/' . ltrim( $path, '/' );

			// Remove trailing slash.
			$absolute = rtrim( $absolute, '/' );

			$normalized[] = $absolute;
		}

		return $normalized;
	}

	/**
	 * Composition root -- the single place where service location is acceptable.
	 *
	 * Resolve your top-level services here via $resolver->get(). Domain services
	 * should receive their dependencies via constructor injection, never by
	 * holding a reference to the Resolver.
	 *
	 * @param Resolver $resolver Service resolver with get() and has() methods only.
	 */
	abstract protected function bootstrap( Resolver $resolver ): void;
}
