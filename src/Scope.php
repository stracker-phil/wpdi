<?php

declare( strict_types = 1 );

namespace WPDI;

require_once __DIR__ . '/version-check.php';

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
Commands\Cli::register_commands();

/**
 * Base class for WordPress modules using WPDI (plugins, themes, etc.)
 */
abstract class Scope {

	/**
	 * Stores booted instances keyed by class name to prevent duplicate containers.
	 *
	 * @var array<string, static>
	 */
	private static $booted = array();

	/**
	 * Boot the scope — idempotent, safe to call multiple times.
	 *
	 * Use this instead of `new` to avoid unused-return-value warnings and
	 * to prevent accidentally creating multiple containers for the same scope.
	 *
	 * @param string $scope_file Path to the implementing file (use __FILE__).
	 */
	public static function boot( string $scope_file ): void {
		if ( isset( self::$booted[ static::class ] ) ) {
			return;
		}
		self::$booted[ static::class ] = new static( $scope_file );
	}

	/**
	 * Clear the booted instance for this class.
	 *
	 * Intended for use in tests to reset state between test cases.
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
	 */
	protected function __construct( string $scope_file ) {
		$container = new Container();
		$container->initialize( $scope_file, $this->autowiring_paths(), $this->environment() );
		$this->bootstrap( $container->resolver() );
	}

	/**
	 * Composition root - only place where service location happens
	 *
	 * @param Resolver $resolver Service resolver with get() and has() methods.
	 */
	abstract protected function bootstrap( Resolver $resolver ): void;
}
