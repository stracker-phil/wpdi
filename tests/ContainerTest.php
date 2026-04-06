<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Auto_Discovery;
use WPDI\Cache_Manager;
use WPDI\Cache_Store;
use WPDI\Class_Inspector;
use WPDI\Container;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Tests\Fixtures\ClassWithMultipleDependencies;
use WPDI\Tests\Fixtures\ClassWithOptionalDependency;
use WPDI\Tests\Fixtures\LoggerInterface;
use WPDI\Tests\Fixtures\ArrayLogger;
use WPDI\Tests\Fixtures\ClassWithInterface;
use WPDI\Tests\Fixtures\AbstractClass;
use WPDI\Tests\Fixtures\ClassWithDefaultValue;
use WPDI\Tests\Fixtures\CircularA;
use WPDI\Tests\Fixtures\ClassWithNullableParameter;
use WPDI\Tests\Fixtures\CacheInterface;
use WPDI\Tests\Fixtures\DB_Cache;
use WPDI\Tests\Fixtures\File_Cache;
use WPDI\Tests\Fixtures\ClassWithContextualDeps;
use WPDI\Tests\Fixtures\ClassWithUnmatchedContextualDep;

/**
 * @covers \WPDI\Container
 */
class ContainerTest extends TestCase {

	private Container $container;

	protected function setUp(): void {
		$this->container = new Container();
	}

	protected function tearDown(): void {
		$this->container->clear();
	}

	// ========================================
	// Basic Binding and Resolution Tests
	// ========================================

	/**
	 * GIVEN a fresh container
	 * WHEN a simple class is bound and resolved
	 * THEN the container returns a valid instance with expected behavior
	 */
	public function test_can_bind_and_resolve_simple_class(): void {
		$this->container->bind( SimpleClass::class );

		$instance = $this->container->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
		$this->assertEquals( 'Hello from SimpleClass', $instance->get_message() );
	}

	/**
	 * GIVEN a container
	 * WHEN a class is bound with a custom factory closure
	 * THEN the container uses the factory to create instances
	 */
	public function test_can_bind_with_custom_factory(): void {
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass() );

		$instance = $this->container->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
	}

	/**
	 * GIVEN a container with different singleton configurations
	 * WHEN services are resolved multiple times
	 * THEN singleton services return the same instance and non-singletons return different
	 * instances
	 *
	 * @dataProvider singleton_behavior_provider
	 */
	public function test_singleton_behavior( bool $is_singleton, bool $expect_same ): void {
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass(), $is_singleton );

		$instance1 = $this->container->get( SimpleClass::class );
		$instance2 = $this->container->get( SimpleClass::class );

		if ( $expect_same ) {
			$this->assertSame( $instance1, $instance2 );
		} else {
			$this->assertNotSame( $instance1, $instance2 );
		}
		$this->assertInstanceOf( SimpleClass::class, $instance1 );
		$this->assertInstanceOf( SimpleClass::class, $instance2 );
	}

	public function singleton_behavior_provider(): array {
		return array(
			'singleton returns same instance'           => array( true, true ),
			'non-singleton returns different instances' => array( false, false ),
		);
	}

	// ========================================
	// Autowiring Tests
	// ========================================

	/**
	 * GIVEN a container with no explicit bindings
	 * WHEN a class is resolved
	 * THEN the container autowires it via reflection and injects all dependencies
	 *
	 * @dataProvider autowiring_scenarios_provider
	 */
	public function test_autowires_classes_with_varying_complexity(
		string $class_name,
		?callable $assertions
	): void {
		$instance = $this->container->get( $class_name );

		$this->assertInstanceOf( $class_name, $instance );

		if ( $assertions ) {
			$assertions( $instance, $this );
		}
	}

	public function autowiring_scenarios_provider(): array {
		return array(
			'class without constructor'        => array(
				SimpleClass::class,
				null,
			),
			'class with single dependency'     => array(
				ClassWithDependency::class,
				function ( $instance, $test ) {
					$test->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
				},
			),
			'class with multiple dependencies' => array(
				ClassWithMultipleDependencies::class,
				function ( $instance, $test ) {
					$test->assertInstanceOf( SimpleClass::class, $instance->get_first() );
					$test->assertInstanceOf( ClassWithDependency::class, $instance->get_second() );
				},
			),
			'class with optional dependency'   => array(
				ClassWithOptionalDependency::class,
				function ( $instance, $test ) {
					$test->assertTrue( $instance->has_dependency() );
					$test->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
				},
			),
			'class with default values'        => array(
				ClassWithDefaultValue::class,
				function ( $instance, $test ) {
					$test->assertEquals( 'default', $instance->get_name() );
					$test->assertEquals( 10, $instance->get_count() );
				},
			),
		);
	}

	/**
	 * GIVEN multiple services with shared dependencies
	 * WHEN services are resolved from the container
	 * THEN shared dependencies are singletons and injected as the same instance
	 */
	public function test_autowires_shared_dependencies_as_singletons(): void {
		$instance1 = $this->container->get( ClassWithDependency::class );
		$instance2 = $this->container->get( ClassWithMultipleDependencies::class );

		// The SimpleClass dependency should be the same instance
		$this->assertSame(
			$instance1->get_dependency(),
			$instance2->get_first()
		);
	}

	/**
	 * GIVEN a class with nullable type-hinted parameter
	 * WHEN the parameter type cannot be resolved
	 * THEN the container injects null instead of throwing exception
	 */
	public function test_autowires_class_with_nullable_unresolvable_dependency(): void {
		// ClassWithNullableParameter has a nullable LoggerInterface parameter
		// Since LoggerInterface is not bound, it should resolve to null
		$instance = $this->container->get( ClassWithNullableParameter::class );

		$this->assertInstanceOf( ClassWithNullableParameter::class, $instance );
		$this->assertFalse( $instance->has_dependency() );
		$this->assertNull( $instance->get_dependency() );
	}

	// ========================================
	// Interface Binding Tests
	// ========================================

	/**
	 * GIVEN an interface bound to a concrete implementation
	 * WHEN the interface is resolved
	 * THEN the container returns an instance of the bound implementation
	 */
	public function test_can_bind_interface_to_implementation(): void {
		$this->container->bind( LoggerInterface::class, fn() => new ArrayLogger() );

		$logger = $this->container->get( LoggerInterface::class );

		$this->assertInstanceOf( LoggerInterface::class, $logger );
		$this->assertInstanceOf( ArrayLogger::class, $logger );
	}

	/**
	 * GIVEN an interface bound to an implementation
	 * WHEN a class with interface dependency is autowired
	 * THEN the bound implementation is injected and works correctly
	 */
	public function test_resolves_interface_dependencies(): void {
		$this->container->bind( LoggerInterface::class, fn() => new ArrayLogger() );

		$instance = $this->container->get( ClassWithInterface::class );

		$this->assertInstanceOf( ClassWithInterface::class, $instance );
		$this->assertInstanceOf( LoggerInterface::class, $instance->get_logger() );

		$instance->do_something();
		$logs = $instance->get_logger()->get_logs();

		$this->assertCount( 1, $logs );
		$this->assertEquals( 'Something was done', $logs[0] );
	}

	// ========================================
	// PSR-11 Compliance Tests
	// ========================================

	/**
	 * GIVEN a container in various states
	 * WHEN has() is called with a service identifier
	 * THEN it returns true if the service can be resolved, false otherwise
	 *
	 * @dataProvider psr11_has_scenarios_provider
	 */
	public function test_psr11_has_method_reports_service_availability(
		?callable $setup,
		string $service_id,
		bool $expected_result
	): void {
		if ( $setup ) {
			$setup( $this->container );
		}

		$this->assertEquals( $expected_result, $this->container->has( $service_id ) );
	}

	public function psr11_has_scenarios_provider(): array {
		return array(
			'explicitly bound service'    => array(
				function ( $container ) {
					$container->bind( SimpleClass::class );
				},
				SimpleClass::class,
				true,
			),
			'existing autoloadable class' => array(
				null,
				SimpleClass::class,
				true,
			),
			'non-existent class'          => array(
				null,
				'NonExistentClass',
				false,
			),
			'already resolved instance'   => array(
				function ( $container ) {
					$container->get( SimpleClass::class );
				},
				SimpleClass::class,
				true,
			),
		);
	}

	// ========================================
	// Configuration Tests
	// ========================================

	/**
	 * GIVEN a configuration array with service bindings
	 * WHEN the configuration is loaded into the container
	 * THEN all services are registered and resolvable
	 */
	public function test_can_load_config_array(): void {
		$config = array(
			LoggerInterface::class => ArrayLogger::class,
			SimpleClass::class     => SimpleClass::class,
		);

		$this->container->load_config( $config );

		$this->assertTrue( $this->container->has( LoggerInterface::class ) );
		$this->assertTrue( $this->container->has( SimpleClass::class ) );

		$logger = $this->container->get( LoggerInterface::class );
		$this->assertInstanceOf( ArrayLogger::class, $logger );
	}

	/**
	 * GIVEN an array of discovered class names
	 * WHEN load_compiled() is called with the class list
	 * THEN all classes are registered and autowirable
	 */
	public function test_can_load_compiled_classes(): void {
		// load_compiled expects cache array with 'classes' and 'bindings' sections
		$this->container->load_compiled( array(
			'classes' => array(
				SimpleClass::class         => '/fake/path/SimpleClass.php',
				ClassWithDependency::class => '/fake/path/ClassWithDependency.php',
			),
			'bindings' => array(),
		) );

		// Classes should be registered
		$this->assertTrue( $this->container->has( SimpleClass::class ) );
		$this->assertTrue( $this->container->has( ClassWithDependency::class ) );

		// Should be able to resolve them
		$simple = $this->container->get( SimpleClass::class );
		$this->assertInstanceOf( SimpleClass::class, $simple );

		$withDep = $this->container->get( ClassWithDependency::class );
		$this->assertInstanceOf( ClassWithDependency::class, $withDep );
	}

	/**
	 * GIVEN a container with explicit custom bindings
	 * WHEN compiled classes are loaded that include already-bound services
	 * THEN custom bindings take precedence and are not overridden
	 */
	public function test_load_compiled_does_not_override_existing_bindings(): void {
		// Bind with custom factory first
		$customInstance = new SimpleClass();
		$this->container->bind( SimpleClass::class, fn() => $customInstance );

		// Load compiled classes including SimpleClass (expects cache array with classes and bindings)
		$this->container->load_compiled( array(
			'classes'  => array( SimpleClass::class => '/fake/path/SimpleClass.php' ),
			'bindings' => array(),
		) );

		// Should still return the custom instance, not autowired
		$retrieved = $this->container->get( SimpleClass::class );
		$this->assertSame( $customInstance, $retrieved );
	}

	/**
	 * GIVEN a production environment with a cached container file
	 * WHEN load_compiled() is called with cached data
	 * THEN services are resolvable from the container
	 */
	public function test_load_compiled_loads_cache_in_production_environment(): void {
		// Create a temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_prod_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/cache', 0777, true );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a cache file
		$cache_file    = $temp_dir . '/cache/wpdi-container.php';
		$cache_content = "<?php\nreturn array(\n    '" . SimpleClass::class . "',\n);";
		file_put_contents( $cache_file, $cache_content );

		try {
			$cached = $this->build_cache( $temp_dir, $temp_dir . '/scope.php', 'production' );
			$this->container->load_compiled( $cached );

			$this->assertTrue( $this->container->has( SimpleClass::class ) );
			$instance = $this->container->get( SimpleClass::class );
			$this->assertInstanceOf( SimpleClass::class, $instance );
		} finally {
			// Cleanup
			unlink( $cache_file );
			$gitignore = $temp_dir . '/cache/.gitignore';
			if ( file_exists( $gitignore ) ) {
				unlink( $gitignore );
			}
			rmdir( $temp_dir . '/cache' );
			rmdir( $temp_dir . '/src' );
			rmdir( $temp_dir );
		}
	}

	/**
	 * GIVEN config bindings loaded via Cache_Manager
	 * WHEN load_compiled() is called
	 * THEN interface bindings are available in the container
	 */
	public function test_load_compiled_with_config_bindings(): void {
		// Create a temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_config_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a config file that binds an interface
		$config_file    = $temp_dir . '/wpdi-config.php';
		$config_content = "<?php\nreturn array(\n    '" . LoggerInterface::class . "' => '" . ArrayLogger::class . "',\n);";
		file_put_contents( $config_file, $config_content );

		$scope_file = $temp_dir . '/test-scope.php';
		file_put_contents( $scope_file, "<?php\n// Fake scope file for testing" );

		$config = require $config_file;
		$cached = $this->build_cache( $temp_dir, $scope_file, 'development', $config );
		$this->container->load_compiled( $cached );

		// Should have loaded the interface binding from config
		$this->assertTrue( $this->container->has( LoggerInterface::class ) );
		$logger = $this->container->get( LoggerInterface::class );
		$this->assertInstanceOf( ArrayLogger::class, $logger );

		// Cleanup
		unlink( $config_file );
		unlink( $scope_file );
		rmdir( $temp_dir . '/src' );
		if ( is_dir( $temp_dir . '/cache' ) ) {
			$cache_file = $temp_dir . '/cache/wpdi-container.php';
			if ( file_exists( $cache_file ) ) {
				unlink( $cache_file );
			}
			$gitignore_file = $temp_dir . '/cache/.gitignore';
			if ( file_exists( $gitignore_file ) ) {
				unlink( $gitignore_file );
			}
			rmdir( $temp_dir . '/cache' );
		}
		rmdir( $temp_dir );
	}

	/**
	 * GIVEN a directory with discoverable PHP classes in src/
	 * WHEN Cache_Manager discovers and load_compiled() is called
	 * THEN classes are bound and a cache file is created
	 */
	public function test_load_compiled_discovers_and_binds_classes(): void {
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_bind_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a new class file with unique namespace to avoid conflicts
		$dest_class    = $temp_dir . '/src/DiscoverableTestClass.php';
		$class_content = <<<'PHP'
<?php
namespace WPDI\Tests\Discovery;

class DiscoverableTestClass {
    public function get_message(): string {
        return 'I was discovered!';
    }
}
PHP;
		file_put_contents( $dest_class, $class_content );

		// Load the class so it can be reflected during discovery
		require_once $dest_class;

		$container = new Container();

		$scope_file = $temp_dir . '/test-scope.php';
		file_put_contents( $scope_file, "<?php\n// Fake scope file for testing" );

		$cached = $this->build_cache( $temp_dir, $scope_file );
		$container->load_compiled( $cached );

		$this->assertTrue( $container->has( 'WPDI\Tests\Discovery\DiscoverableTestClass' ) );

		$instance = $container->get( 'WPDI\Tests\Discovery\DiscoverableTestClass' );
		$this->assertInstanceOf( 'WPDI\Tests\Discovery\DiscoverableTestClass', $instance );
		$this->assertEquals( 'I was discovered!', $instance->get_message() );

		$cache_file = $temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file );

		// Cleanup
		unlink( $cache_file );
		$gitignore_file = $temp_dir . '/cache/.gitignore';
		if ( file_exists( $gitignore_file ) ) {
			unlink( $gitignore_file );
		}
		unlink( $dest_class );
		unlink( $scope_file );
		rmdir( $temp_dir . '/cache' );
		rmdir( $temp_dir . '/src' );
		rmdir( $temp_dir );
	}

	/**
	 * Build cache using Cache_Manager (mirrors what Scope does)
	 */
	private function build_cache( string $base_path, string $scope_file, string $environment = 'development', array $config_bindings = array() ): array {
		$inspector     = new Class_Inspector();
		$store         = new Cache_Store( $base_path );
		$discovery     = new Auto_Discovery( $inspector );
		$cache_manager = new Cache_Manager(
			$store,
			$discovery,
			$inspector,
			array( $base_path . '/src' ),
			$base_path,
			$environment
		);

		return $cache_manager->get_cache( $scope_file, $config_bindings );
	}

	// ========================================
	// Exception Tests
	// ========================================

	/**
	 * GIVEN invalid service identifiers or unresolvable dependencies
	 * WHEN the container attempts to bind or resolve them
	 * THEN appropriate exceptions are thrown with descriptive messages
	 *
	 * @dataProvider exception_scenarios_provider
	 */
	public function test_throws_appropriate_exceptions(
		string $exception_class,
		?string $message_contains,
		callable $action
	): void {
		$this->expectException( $exception_class );
		if ( $message_contains ) {
			$this->expectExceptionMessage( $message_contains );
		}

		$action( $this->container );
	}

	public function exception_scenarios_provider(): array {
		return array(
			'invalid class name in bind'            => array(
				Container_Exception::class,
				"'InvalidClass' must be a valid class or interface name",
				function ( $container ) {
					$container->bind( 'InvalidClass' );
				},
			),
			'invalid class name in get'             => array(
				Not_Found_Exception::class,
				"'invalid-string' must be a valid class or interface name",
				function ( $container ) {
					$container->get( 'invalid-string' );
				},
			),
			'abstract class cannot be instantiated' => array(
				Container_Exception::class,
				'is not instantiable',
				function ( $container ) {
					$container->get( AbstractClass::class );
				},
			),
			'unbound interface in dependency chain' => array(
				Container_Exception::class,
				null,
				function ( $container ) {
					$container->get( ClassWithInterface::class );
				},
			),
			'interface without binding'             => array(
				Not_Found_Exception::class,
				'Service ' . LoggerInterface::class . ' not found',
				function ( $container ) {
					$container->get( LoggerInterface::class );
				},
			),
			'unresolvable primitive parameter'      => array(
				Container_Exception::class,
				'Cannot resolve parameter',
				function ( $container ) {
					$class = new class( 'test' ) {
						public function __construct( string $required ) {
						}
					};
					$container->get( get_class( $class ) );
				},
			),
		);
	}

	// Note: test_throws_exception_for_non_callable_factory removed
	// The ?callable type hint makes it impossible to pass non-callable values,
	// so there's no code path to test. PHP's type system handles this.

	// ========================================
	// Utility Method Tests
	// ========================================

	/**
	 * GIVEN a container with multiple bound services
	 * WHEN get_registered() is called
	 * THEN it returns an array of all registered service identifiers
	 */
	public function test_get_registered_returns_bound_services(): void {
		$this->container->bind( SimpleClass::class );
		$this->container->bind( LoggerInterface::class, fn() => new ArrayLogger() );

		$registered = $this->container->get_registered();

		$this->assertContains( SimpleClass::class, $registered );
		$this->assertContains( LoggerInterface::class, $registered );
		$this->assertCount( 2, $registered );
	}

	/**
	 * GIVEN a container with bindings and resolved instances
	 * WHEN clear() is called
	 * THEN all bindings and instances are removed but autowiring still works
	 */
	public function test_clear_removes_all_bindings_and_instances(): void {
		$this->container->bind( SimpleClass::class );
		$this->container->get( SimpleClass::class );

		$this->assertTrue( $this->container->has( SimpleClass::class ) );

		$this->container->clear();

		$registered = $this->container->get_registered();
		$this->assertEmpty( $registered );

		// Should still be able to autowire after clear
		$this->assertTrue( $this->container->has( SimpleClass::class ) );
	}

	// ========================================
	// Contextual Binding Tests
	// ========================================

	/**
	 * GIVEN an interface with contextual bindings keyed by parameter name
	 * WHEN a class with matching parameter names is autowired
	 * THEN each parameter receives the correct implementation
	 */
	public function test_contextual_binding_resolves_by_parameter_name(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache'   => DB_Cache::class,
				'$file_cache' => File_Cache::class,
			)
		);

		$instance = $this->container->get( ClassWithContextualDeps::class );

		$this->assertInstanceOf( DB_Cache::class, $instance->get_db_cache() );
		$this->assertInstanceOf( File_Cache::class, $instance->get_file_cache() );
	}

	/**
	 * GIVEN a contextual binding with a default (empty string key)
	 * WHEN a parameter name doesn't match any specific key
	 * THEN the default binding is used
	 */
	public function test_contextual_binding_uses_default_for_unmatched_param(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache' => DB_Cache::class,
				'default'   => File_Cache::class,
			)
		);

		$instance = $this->container->get( ClassWithUnmatchedContextualDep::class );

		$this->assertInstanceOf( File_Cache::class, $instance->get_cache() );
	}

	/**
	 * GIVEN a contextual binding without a default
	 * WHEN a parameter name doesn't match any key
	 * THEN a Container_Exception is thrown
	 */
	public function test_contextual_binding_throws_without_default(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache' => DB_Cache::class,
			)
		);

		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'No contextual binding' );

		$this->container->get( ClassWithUnmatchedContextualDep::class );
	}

	/**
	 * GIVEN contextual bindings for an interface
	 * WHEN the same class is resolved multiple times
	 * THEN each branch returns the same singleton instance
	 */
	public function test_contextual_binding_caches_singletons_per_branch(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache'   => DB_Cache::class,
				'$file_cache' => File_Cache::class,
			)
		);

		$instance1 = $this->container->get( ClassWithContextualDeps::class );

		$this->assertInstanceOf( DB_Cache::class, $instance1->get_db_cache() );
		$this->assertInstanceOf( File_Cache::class, $instance1->get_file_cache() );
		$this->assertNotSame( $instance1->get_db_cache(), $instance1->get_file_cache() );

		// Resolve again — ClassWithContextualDeps is itself a singleton
		$instance2 = $this->container->get( ClassWithContextualDeps::class );
		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * GIVEN a contextual binding with a default
	 * WHEN get() is called directly on the interface
	 * THEN the default binding is used
	 */
	public function test_contextual_binding_direct_get_uses_default(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache' => DB_Cache::class,
				'default'   => File_Cache::class,
			)
		);

		$result = $this->container->get( CacheInterface::class );

		$this->assertInstanceOf( File_Cache::class, $result );
	}

	/**
	 * GIVEN a contextual binding without a default
	 * WHEN get() is called directly on the interface
	 * THEN a Container_Exception is thrown
	 */
	public function test_contextual_binding_direct_get_throws_without_default(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'$db_cache' => DB_Cache::class,
			)
		);

		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'No contextual binding' );

		$this->container->get( CacheInterface::class );
	}

	/**
	 * GIVEN a config array with both simple and contextual bindings
	 * WHEN load_config() is called
	 * THEN both binding types are registered correctly
	 */
	public function test_load_config_handles_contextual_bindings(): void {
		$config = array(
			LoggerInterface::class => ArrayLogger::class,
			CacheInterface::class  => array(
				'$db_cache'   => DB_Cache::class,
				'$file_cache' => File_Cache::class,
				'default'     => DB_Cache::class,
			),
		);

		$this->container->load_config( $config );

		$this->assertTrue( $this->container->has( LoggerInterface::class ) );
		$this->assertTrue( $this->container->has( CacheInterface::class ) );

		$this->assertInstanceOf( ArrayLogger::class, $this->container->get( LoggerInterface::class ) );
		$this->assertInstanceOf( DB_Cache::class, $this->container->get( CacheInterface::class ) );
	}

	/**
	 * GIVEN an invalid contextual binding key (no $ prefix)
	 * WHEN bind_contextual() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_contextual_binding_validates_keys(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'Invalid contextual binding key' );

		$this->container->bind_contextual(
			CacheInterface::class,
			array(
				'no_dollar' => DB_Cache::class,
			)
		);
	}

	// ========================================
	// Configuration Validation Tests
	// ========================================

	/**
	 * GIVEN a config array with a non-string concrete value
	 * WHEN load_config() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_load_config_throws_for_non_string_concrete(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'must be a valid class or interface name' );

		$this->container->load_config( array(
			SimpleClass::class => 42,
		) );
	}

	/**
	 * GIVEN a config array with an invalid class name string
	 * WHEN load_config() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_load_config_throws_for_invalid_class_string(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( "'NonExistentClass'" );

		$this->container->load_config( array(
			SimpleClass::class => 'NonExistentClass',
		) );
	}

	/**
	 * GIVEN an invalid abstract name (non-existent class/interface)
	 * WHEN bind_contextual() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_bind_contextual_throws_for_invalid_abstract(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( "'InvalidAbstract' must be a valid class or interface name" );

		$this->container->bind_contextual(
			'InvalidAbstract',
			array( '$param' => DB_Cache::class )
		);
	}

	/**
	 * GIVEN a contextual binding with a non-string concrete value
	 * WHEN bind_contextual() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_bind_contextual_throws_for_non_string_concrete(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'must be a valid class or interface name' );

		$this->container->bind_contextual(
			CacheInterface::class,
			array( '$cache' => 42 )
		);
	}

	/**
	 * GIVEN a contextual binding with an invalid class name
	 * WHEN bind_contextual() is called
	 * THEN a Container_Exception is thrown
	 */
	public function test_bind_contextual_throws_for_invalid_concrete_class(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'must be a valid class or interface name' );

		$this->container->bind_contextual(
			CacheInterface::class,
			array( '$cache' => 'NonExistentConcrete' )
		);
	}

	/**
	 * GIVEN load_compiled() called with no bindings key
	 * WHEN resolving classes
	 * THEN container works with empty bindings
	 */
	public function test_load_compiled_handles_missing_bindings_key(): void {
		$this->container->load_compiled( array(
			'classes' => array(
				SimpleClass::class => '/fake/path.php',
			),
		) );

		$this->assertTrue( $this->container->has( SimpleClass::class ) );
	}

	/**
	 * GIVEN a contextual binding registered via bind_contextual()
	 * WHEN has() is called with the contextual-bound interface
	 * THEN it returns true
	 */
	public function test_has_returns_true_for_contextual_binding(): void {
		$this->container->bind_contextual(
			CacheInterface::class,
			array( 'default' => DB_Cache::class )
		);

		$this->assertTrue( $this->container->has( CacheInterface::class ) );
	}

	// ========================================
	// Cached Constructor Resolution Tests
	// ========================================

	/**
	 * GIVEN a compiled cache with constructor descriptors for a no-arg class
	 * WHEN the class is resolved
	 * THEN instantiation succeeds without reflection
	 */
	public function test_cached_constructor_resolves_class_without_constructor(): void {
		$this->container->load_compiled( array(
			'classes'  => array(
				SimpleClass::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array(),
					'constructor'  => null,
				),
			),
			'bindings' => array(),
		) );

		$instance = $this->container->get( SimpleClass::class );
		$this->assertInstanceOf( SimpleClass::class, $instance );
	}

	/**
	 * GIVEN a compiled cache with constructor descriptors for a class with dependency
	 * WHEN the class is resolved
	 * THEN dependencies are injected from cache without reflection
	 */
	public function test_cached_constructor_resolves_class_with_dependency(): void {
		$this->container->load_compiled( array(
			'classes'  => array(
				SimpleClass::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array(),
					'constructor'  => null,
				),
				ClassWithDependency::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array( SimpleClass::class ),
					'constructor'  => array(
						array(
							'name'        => 'dependency',
							'type'        => SimpleClass::class,
							'builtin'     => false,
							'nullable'    => false,
							'has_default' => false,
							'default'     => null,
						),
					),
				),
			),
			'bindings' => array(),
		) );

		$instance = $this->container->get( ClassWithDependency::class );
		$this->assertInstanceOf( ClassWithDependency::class, $instance );
		$this->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
	}

	/**
	 * GIVEN a compiled cache with constructor descriptors for scalar defaults
	 * WHEN the class is resolved
	 * THEN default values are used from cache
	 */
	public function test_cached_constructor_uses_scalar_defaults(): void {
		$this->container->load_compiled( array(
			'classes'  => array(
				ClassWithDefaultValue::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array(),
					'constructor'  => array(
						array(
							'name'        => 'name',
							'type'        => 'string',
							'builtin'     => true,
							'nullable'    => false,
							'has_default' => true,
							'default'     => 'default',
						),
						array(
							'name'        => 'count',
							'type'        => 'int',
							'builtin'     => true,
							'nullable'    => false,
							'has_default' => true,
							'default'     => 10,
						),
					),
				),
			),
			'bindings' => array(),
		) );

		$instance = $this->container->get( ClassWithDefaultValue::class );
		$this->assertInstanceOf( ClassWithDefaultValue::class, $instance );
		$this->assertEquals( 'default', $instance->get_name() );
		$this->assertEquals( 10, $instance->get_count() );
	}

	/**
	 * GIVEN a compiled cache with a nullable param for an unbound interface
	 * WHEN the class is resolved
	 * THEN null is injected
	 */
	public function test_cached_constructor_resolves_nullable_to_null(): void {
		$this->container->load_compiled( array(
			'classes'  => array(
				ClassWithNullableParameter::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array( LoggerInterface::class ),
					'constructor'  => array(
						array(
							'name'        => 'optional',
							'type'        => LoggerInterface::class,
							'builtin'     => false,
							'nullable'    => true,
							'has_default' => false,
							'default'     => null,
						),
					),
				),
			),
			'bindings' => array(),
		) );

		$instance = $this->container->get( ClassWithNullableParameter::class );
		$this->assertInstanceOf( ClassWithNullableParameter::class, $instance );
		$this->assertFalse( $instance->has_dependency() );
	}

	/**
	 * GIVEN a compiled cache with contextual binding params
	 * WHEN the class is resolved
	 * THEN correct contextual bindings are applied from cache
	 */
	public function test_cached_constructor_resolves_contextual_bindings(): void {
		$this->container->load_compiled( array(
			'classes'  => array(
				DB_Cache::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array(),
					'constructor'  => null,
				),
				File_Cache::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array(),
					'constructor'  => null,
				),
				ClassWithContextualDeps::class => array(
					'path'         => '/fake/path.php',
					'mtime'        => 1234567890,
					'dependencies' => array( CacheInterface::class, CacheInterface::class ),
					'constructor'  => array(
						array(
							'name'        => 'db_cache',
							'type'        => CacheInterface::class,
							'builtin'     => false,
							'nullable'    => false,
							'has_default' => false,
							'default'     => null,
						),
						array(
							'name'        => 'file_cache',
							'type'        => CacheInterface::class,
							'builtin'     => false,
							'nullable'    => false,
							'has_default' => false,
							'default'     => null,
						),
					),
				),
			),
			'bindings' => array(
				CacheInterface::class => array(
					'$db_cache'   => DB_Cache::class,
					'$file_cache' => File_Cache::class,
				),
			),
		) );

		$instance = $this->container->get( ClassWithContextualDeps::class );
		$this->assertInstanceOf( ClassWithContextualDeps::class, $instance );
		$this->assertInstanceOf( DB_Cache::class, $instance->get_db_cache() );
		$this->assertInstanceOf( File_Cache::class, $instance->get_file_cache() );
	}

	/**
	 * GIVEN a class NOT in the compiled cache
	 * WHEN the class is resolved
	 * THEN the container falls back to reflection-based autowiring
	 */
	public function test_falls_back_to_reflection_when_no_cache(): void {
		// Load a cache that does NOT include ClassWithDependency
		$this->container->load_compiled( array(
			'classes'  => array(),
			'bindings' => array(),
		) );

		$instance = $this->container->get( ClassWithDependency::class );
		$this->assertInstanceOf( ClassWithDependency::class, $instance );
		$this->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
	}

	// ========================================
	// Edge Cases
	// ========================================

	/**
	 * GIVEN classes with circular constructor dependencies
	 * WHEN the container attempts to resolve them
	 * THEN a Circular_Dependency_Exception is thrown with a clear error message
	 */
	public function test_circular_dependency_handling(): void {
		$this->expectException( Circular_Dependency_Exception::class );
		$this->expectExceptionMessage( 'Circular dependency detected' );

		// CircularA depends on CircularB, which depends back on CircularA
		$this->container->get( CircularA::class );
	}

	/**
	 * GIVEN a service already bound in the container
	 * WHEN the binding is cleared and re-bound with a different factory
	 * THEN the new factory is used for subsequent resolutions
	 */
	public function test_can_override_binding(): void {
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass() );

		$instance1 = $this->container->get( SimpleClass::class );

		// Clear the container to remove cached singleton
		$this->container->clear();

		// Override with different factory
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass() );

		$instance2 = $this->container->get( SimpleClass::class );

		// Since we cleared and re-bound, we should get a different instance
		$this->assertNotSame( $instance1, $instance2 );
	}
}
