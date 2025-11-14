<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
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
	 * THEN singleton services return the same instance and non-singletons return different instances
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
			'singleton returns same instance'         => array( true, true ),
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
			'class without constructor' => array(
				SimpleClass::class,
				null,
			),
			'class with single dependency' => array(
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
			'class with optional dependency' => array(
				ClassWithOptionalDependency::class,
				function ( $instance, $test ) {
					$test->assertTrue( $instance->has_dependency() );
					$test->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
				},
			),
			'class with default values' => array(
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
			'explicitly bound service' => array(
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
			'non-existent class' => array(
				null,
				'NonExistentClass',
				false,
			),
			'already resolved instance' => array(
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
			LoggerInterface::class => fn() => new ArrayLogger(),
			SimpleClass::class     => fn() => new SimpleClass(),
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
		// load_compiled expects class => filepath mapping
		$class_map = array(
			SimpleClass::class        => '/fake/path/SimpleClass.php',
			ClassWithDependency::class => '/fake/path/ClassWithDependency.php',
		);

		$this->container->load_compiled( $class_map );

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

		// Load compiled classes including SimpleClass (expects class => filepath mapping)
		$this->container->load_compiled( array( SimpleClass::class => '/fake/path/SimpleClass.php' ) );

		// Should still return the custom instance, not autowired
		$retrieved = $this->container->get( SimpleClass::class );
		$this->assertSame( $customInstance, $retrieved );
	}

	/**
	 * GIVEN a production environment with a cached container file
	 * WHEN the container is initialized
	 * THEN it loads services from cache instead of performing discovery
	 */
	public function test_initialize_loads_cache_in_production_environment(): void {
		// Create a temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_prod_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/cache', 0777, true );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a cache file
		$cache_file    = $temp_dir . '/cache/wpdi-container.php';
		$cache_content = "<?php\nreturn array(\n    '" . SimpleClass::class . "',\n);";
		file_put_contents( $cache_file, $cache_content );

		// Set environment to production
		putenv( 'WP_ENVIRONMENT_TYPE=production' );

		try {
			$this->container->initialize( $temp_dir );

			// Should have loaded SimpleClass from cache (line 123)
			$this->assertTrue( $this->container->has( SimpleClass::class ) );
			$instance = $this->container->get( SimpleClass::class );
			$this->assertInstanceOf( SimpleClass::class, $instance );
		} finally {
			// Restore environment to development
			putenv( 'WP_ENVIRONMENT_TYPE=development' );

			// Cleanup
			unlink( $cache_file );
			rmdir( $temp_dir . '/cache' );
			rmdir( $temp_dir . '/src' );
			rmdir( $temp_dir );
		}
	}

	/**
	 * GIVEN a wpdi-config.php file exists in the base directory
	 * WHEN the container is initialized
	 * THEN configuration bindings are loaded and available
	 */
	public function test_initialize_with_config_file(): void {
		// Create a temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_config_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a config file that binds an interface
		$config_file    = $temp_dir . '/wpdi-config.php';
		$config_content = "<?php\nreturn array(\n    '" . LoggerInterface::class . "' => function() {\n        return new " . ArrayLogger::class . "();\n    },\n);";
		file_put_contents( $config_file, $config_content );

		// Create a fake scope file (initialize expects __FILE__ path)
		$scope_file = $temp_dir . '/test-scope.php';
		file_put_contents( $scope_file, "<?php\n// Fake scope file for testing" );

		$this->container->initialize( $scope_file );

		// Should have loaded the interface binding from config
		$this->assertTrue( $this->container->has( LoggerInterface::class ) );
		$logger = $this->container->get( LoggerInterface::class );
		$this->assertInstanceOf( ArrayLogger::class, $logger );

		// Cleanup
		unlink( $config_file );
		unlink( $scope_file );
		rmdir( $temp_dir . '/src' );
		// Cache directory might have been created
		if ( is_dir( $temp_dir . '/cache' ) ) {
			$cache_file = $temp_dir . '/cache/wpdi-container.php';
			if ( file_exists( $cache_file ) ) {
				unlink( $cache_file );
			}
			rmdir( $temp_dir . '/cache' );
		}
		rmdir( $temp_dir );
	}

	/**
	 * GIVEN a directory with discoverable PHP classes in src/
	 * WHEN the container is initialized
	 * THEN classes are discovered, bound, and a cache file is created
	 */
	public function test_initialize_discovers_and_binds_classes(): void {
		// Use a temporary directory with an /src subdirectory
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

		// Create a fresh container to ensure no pre-existing bindings
		$container = new Container();

		// Create a fake scope file (initialize expects __FILE__ path)
		$scope_file = $temp_dir . '/test-scope.php';
		file_put_contents( $scope_file, "<?php\n// Fake scope file for testing" );

		// Initialize - this should discover and bind DiscoverableTestClass (line 130)
		$container->initialize( $scope_file );

		// DiscoverableTestClass should have been discovered and bound (line 130)
		$this->assertTrue( $container->has( 'WPDI\Tests\Discovery\DiscoverableTestClass' ) );

		// Should be able to get it (autowired via the bind in line 130)
		$instance = $container->get( 'WPDI\Tests\Discovery\DiscoverableTestClass' );
		$this->assertInstanceOf( 'WPDI\Tests\Discovery\DiscoverableTestClass', $instance );
		$this->assertEquals( 'I was discovered!', $instance->get_message() );

		// Cache file should be created (lines 136-137)
		$cache_file = $temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file );

		// Cleanup
		unlink( $cache_file );
		unlink( $dest_class );
		unlink( $scope_file );
		rmdir( $temp_dir . '/cache' );
		rmdir( $temp_dir . '/src' );
		rmdir( $temp_dir );
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
			'invalid class name in bind' => array(
				Container_Exception::class,
				"'InvalidClass' must be a valid class or interface name",
				function ( $container ) {
					$container->bind( 'InvalidClass' );
				},
			),
			'invalid class name in get' => array(
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
			'interface without binding' => array(
				Not_Found_Exception::class,
				'Service ' . LoggerInterface::class . ' not found',
				function ( $container ) {
					$container->get( LoggerInterface::class );
				},
			),
			'unresolvable primitive parameter' => array(
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
