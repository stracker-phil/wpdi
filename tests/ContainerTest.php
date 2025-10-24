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

	public function test_can_bind_and_resolve_simple_class(): void {
		$this->container->bind( SimpleClass::class );

		$instance = $this->container->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
		$this->assertEquals( 'Hello from SimpleClass', $instance->get_message() );
	}

	public function test_can_bind_with_custom_factory(): void {
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass() );

		$instance = $this->container->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
	}

	public function test_singleton_returns_same_instance(): void {
		$this->container->bind( SimpleClass::class, null, true );

		$instance1 = $this->container->get( SimpleClass::class );
		$instance2 = $this->container->get( SimpleClass::class );

		$this->assertSame( $instance1, $instance2 );
	}

	public function test_non_singleton_returns_different_instances(): void {
		$this->container->bind( SimpleClass::class, fn() => new SimpleClass(), false );

		$instance1 = $this->container->get( SimpleClass::class );
		$instance2 = $this->container->get( SimpleClass::class );

		$this->assertNotSame( $instance1, $instance2 );
		$this->assertInstanceOf( SimpleClass::class, $instance1 );
		$this->assertInstanceOf( SimpleClass::class, $instance2 );
	}

	// ========================================
	// Autowiring Tests
	// ========================================

	public function test_autowires_class_without_constructor(): void {
		$instance = $this->container->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
	}

	public function test_autowires_class_with_dependency(): void {
		$instance = $this->container->get( ClassWithDependency::class );

		$this->assertInstanceOf( ClassWithDependency::class, $instance );
		$this->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
	}

	public function test_autowires_class_with_multiple_dependencies(): void {
		$instance = $this->container->get( ClassWithMultipleDependencies::class );

		$this->assertInstanceOf( ClassWithMultipleDependencies::class, $instance );
		$this->assertInstanceOf( SimpleClass::class, $instance->get_first() );
		$this->assertInstanceOf( ClassWithDependency::class, $instance->get_second() );
	}

	public function test_autowires_shared_dependencies_as_singletons(): void {
		$instance1 = $this->container->get( ClassWithDependency::class );
		$instance2 = $this->container->get( ClassWithMultipleDependencies::class );

		// The SimpleClass dependency should be the same instance
		$this->assertSame(
			$instance1->get_dependency(),
			$instance2->get_first()
		);
	}

	public function test_autowires_class_with_optional_dependency(): void {
		$instance = $this->container->get( ClassWithOptionalDependency::class );

		$this->assertInstanceOf( ClassWithOptionalDependency::class, $instance );
		// Optional dependency should still be resolved
		$this->assertTrue( $instance->has_dependency() );
		$this->assertInstanceOf( SimpleClass::class, $instance->get_dependency() );
	}

	public function test_autowires_class_with_default_values(): void {
		$instance = $this->container->get( ClassWithDefaultValue::class );

		$this->assertInstanceOf( ClassWithDefaultValue::class, $instance );
		$this->assertEquals( 'default', $instance->get_name() );
		$this->assertEquals( 10, $instance->get_count() );
	}

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

	public function test_can_bind_interface_to_implementation(): void {
		$this->container->bind( LoggerInterface::class, fn() => new ArrayLogger() );

		$logger = $this->container->get( LoggerInterface::class );

		$this->assertInstanceOf( LoggerInterface::class, $logger );
		$this->assertInstanceOf( ArrayLogger::class, $logger );
	}

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

	public function test_has_returns_true_for_bound_service(): void {
		$this->container->bind( SimpleClass::class );

		$this->assertTrue( $this->container->has( SimpleClass::class ) );
	}

	public function test_has_returns_true_for_existing_class(): void {
		$this->assertTrue( $this->container->has( SimpleClass::class ) );
	}

	public function test_has_returns_false_for_non_existent_class(): void {
		$this->assertFalse( $this->container->has( 'NonExistentClass' ) );
	}

	public function test_has_returns_true_for_resolved_instance(): void {
		$this->container->get( SimpleClass::class );

		$this->assertTrue( $this->container->has( SimpleClass::class ) );
	}

	// ========================================
	// Configuration Tests
	// ========================================

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

	public function test_can_load_compiled_classes(): void {
		$classes = array(
			SimpleClass::class,
			ClassWithDependency::class,
		);

		$this->container->load_compiled( $classes );

		// Classes should be registered
		$this->assertTrue( $this->container->has( SimpleClass::class ) );
		$this->assertTrue( $this->container->has( ClassWithDependency::class ) );

		// Should be able to resolve them
		$simple = $this->container->get( SimpleClass::class );
		$this->assertInstanceOf( SimpleClass::class, $simple );

		$withDep = $this->container->get( ClassWithDependency::class );
		$this->assertInstanceOf( ClassWithDependency::class, $withDep );
	}

	public function test_load_compiled_does_not_override_existing_bindings(): void {
		// Bind with custom factory first
		$customInstance = new SimpleClass();
		$this->container->bind( SimpleClass::class, fn() => $customInstance );

		// Load compiled classes including SimpleClass
		$this->container->load_compiled( array( SimpleClass::class ) );

		// Should still return the custom instance, not autowired
		$retrieved = $this->container->get( SimpleClass::class );
		$this->assertSame( $customInstance, $retrieved );
	}

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

	public function test_initialize_with_config_file(): void {
		// Create a temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_config_' . uniqid();
		mkdir( $temp_dir );
		mkdir( $temp_dir . '/src', 0777, true );

		// Create a config file that binds an interface
		$config_file    = $temp_dir . '/wpdi-config.php';
		$config_content = "<?php\nreturn array(\n    '" . LoggerInterface::class . "' => function() {\n        return new " . ArrayLogger::class . "();\n    },\n);";
		file_put_contents( $config_file, $config_content );

		$this->container->initialize( $temp_dir );

		// Should have loaded the interface binding from config
		$this->assertTrue( $this->container->has( LoggerInterface::class ) );
		$logger = $this->container->get( LoggerInterface::class );
		$this->assertInstanceOf( ArrayLogger::class, $logger );

		// Cleanup
		unlink( $config_file );
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

		// Initialize - this should discover and bind DiscoverableTestClass (line 130)
		$container->initialize( $temp_dir );

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
		rmdir( $temp_dir . '/cache' );
		rmdir( $temp_dir . '/src' );
		rmdir( $temp_dir );
	}

	// ========================================
	// Exception Tests
	// ========================================

	public function test_throws_exception_for_invalid_abstract_in_bind(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( "'InvalidClass' must be a valid class or interface name" );

		$this->container->bind( 'InvalidClass' );
	}

	// Note: test_throws_exception_for_non_callable_factory removed
	// The ?callable type hint makes it impossible to pass non-callable values,
	// so there's no code path to test. PHP's type system handles this.

	public function test_throws_exception_for_invalid_class_in_get(): void {
		$this->expectException( Not_Found_Exception::class );
		$this->expectExceptionMessage( "'invalid-string' must be a valid class or interface name" );

		$this->container->get( 'invalid-string' );
	}

	public function test_throws_exception_for_abstract_class(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'is not instantiable' );

		$this->container->get( AbstractClass::class );
	}

	public function test_throws_exception_for_unbound_interface(): void {
		$this->expectException( Container_Exception::class );

		$this->container->get( ClassWithInterface::class );
	}

	public function test_throws_not_found_exception_for_interface_without_binding(): void {
		$this->expectException( Not_Found_Exception::class );
		$this->expectExceptionMessage( 'Service ' . LoggerInterface::class . ' not found' );

		// Try to get an interface that has no binding and can't be autowired
		$this->container->get( LoggerInterface::class );
	}

	public function test_throws_exception_when_cannot_resolve_parameter(): void {
		// Create a class that requires a primitive type we can't resolve
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'Cannot resolve parameter' );

		// We'll test with a class requiring a string parameter
		$class = new class( 'test' ) {
			public function __construct( string $required ) {
			}
		};

		$this->container->get( get_class( $class ) );
	}

	// ========================================
	// Utility Method Tests
	// ========================================

	public function test_get_registered_returns_bound_services(): void {
		$this->container->bind( SimpleClass::class );
		$this->container->bind( LoggerInterface::class, fn() => new ArrayLogger() );

		$registered = $this->container->get_registered();

		$this->assertContains( SimpleClass::class, $registered );
		$this->assertContains( LoggerInterface::class, $registered );
		$this->assertCount( 2, $registered );
	}

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

	public function test_circular_dependency_handling(): void {
		$this->expectException( Circular_Dependency_Exception::class );
		$this->expectExceptionMessage( 'Circular dependency detected' );

		// CircularA depends on CircularB, which depends back on CircularA
		$this->container->get( CircularA::class );
	}

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
