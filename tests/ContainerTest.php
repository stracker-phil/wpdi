<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Container;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
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
use WPDI\Tests\Fixtures\CircularB;

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
        $this->container->bind(SimpleClass::class);

        $instance = $this->container->get(SimpleClass::class);

        $this->assertInstanceOf(SimpleClass::class, $instance);
        $this->assertEquals('Hello from SimpleClass', $instance->get_message());
    }

    public function test_can_bind_with_custom_factory(): void {
        $this->container->bind(SimpleClass::class, function() {
            return new SimpleClass();
        });

        $instance = $this->container->get(SimpleClass::class);

        $this->assertInstanceOf(SimpleClass::class, $instance);
    }

    public function test_singleton_returns_same_instance(): void {
        $this->container->bind(SimpleClass::class, null, true);

        $instance1 = $this->container->get(SimpleClass::class);
        $instance2 = $this->container->get(SimpleClass::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_non_singleton_returns_different_instances(): void {
        $this->container->bind(SimpleClass::class, function() {
            return new SimpleClass();
        }, false);

        $instance1 = $this->container->get(SimpleClass::class);
        $instance2 = $this->container->get(SimpleClass::class);

        $this->assertNotSame($instance1, $instance2);
        $this->assertInstanceOf(SimpleClass::class, $instance1);
        $this->assertInstanceOf(SimpleClass::class, $instance2);
    }

    // ========================================
    // Autowiring Tests
    // ========================================

    public function test_autowires_class_without_constructor(): void {
        $instance = $this->container->get(SimpleClass::class);

        $this->assertInstanceOf(SimpleClass::class, $instance);
    }

    public function test_autowires_class_with_dependency(): void {
        $instance = $this->container->get(ClassWithDependency::class);

        $this->assertInstanceOf(ClassWithDependency::class, $instance);
        $this->assertInstanceOf(SimpleClass::class, $instance->get_dependency());
    }

    public function test_autowires_class_with_multiple_dependencies(): void {
        $instance = $this->container->get(ClassWithMultipleDependencies::class);

        $this->assertInstanceOf(ClassWithMultipleDependencies::class, $instance);
        $this->assertInstanceOf(SimpleClass::class, $instance->get_first());
        $this->assertInstanceOf(ClassWithDependency::class, $instance->get_second());
    }

    public function test_autowires_shared_dependencies_as_singletons(): void {
        $instance1 = $this->container->get(ClassWithDependency::class);
        $instance2 = $this->container->get(ClassWithMultipleDependencies::class);

        // The SimpleClass dependency should be the same instance
        $this->assertSame(
            $instance1->get_dependency(),
            $instance2->get_first()
        );
    }

    public function test_autowires_class_with_optional_dependency(): void {
        $instance = $this->container->get(ClassWithOptionalDependency::class);

        $this->assertInstanceOf(ClassWithOptionalDependency::class, $instance);
        // Optional dependency should still be resolved
        $this->assertTrue($instance->has_dependency());
        $this->assertInstanceOf(SimpleClass::class, $instance->get_dependency());
    }

    public function test_autowires_class_with_default_values(): void {
        $instance = $this->container->get(ClassWithDefaultValue::class);

        $this->assertInstanceOf(ClassWithDefaultValue::class, $instance);
        $this->assertEquals('default', $instance->get_name());
        $this->assertEquals(10, $instance->get_count());
    }

    // ========================================
    // Interface Binding Tests
    // ========================================

    public function test_can_bind_interface_to_implementation(): void {
        $this->container->bind(LoggerInterface::class, function() {
            return new ArrayLogger();
        });

        $logger = $this->container->get(LoggerInterface::class);

        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertInstanceOf(ArrayLogger::class, $logger);
    }

    public function test_resolves_interface_dependencies(): void {
        $this->container->bind(LoggerInterface::class, function() {
            return new ArrayLogger();
        });

        $instance = $this->container->get(ClassWithInterface::class);

        $this->assertInstanceOf(ClassWithInterface::class, $instance);
        $this->assertInstanceOf(LoggerInterface::class, $instance->get_logger());

        $instance->do_something();
        $logs = $instance->get_logger()->get_logs();

        $this->assertCount(1, $logs);
        $this->assertEquals('Something was done', $logs[0]);
    }

    // ========================================
    // PSR-11 Compliance Tests
    // ========================================

    public function test_has_returns_true_for_bound_service(): void {
        $this->container->bind(SimpleClass::class);

        $this->assertTrue($this->container->has(SimpleClass::class));
    }

    public function test_has_returns_true_for_existing_class(): void {
        $this->assertTrue($this->container->has(SimpleClass::class));
    }

    public function test_has_returns_false_for_non_existent_class(): void {
        $this->assertFalse($this->container->has('NonExistentClass'));
    }

    public function test_has_returns_true_for_resolved_instance(): void {
        $this->container->get(SimpleClass::class);

        $this->assertTrue($this->container->has(SimpleClass::class));
    }

    // ========================================
    // Configuration Tests
    // ========================================

    public function test_can_load_config_array(): void {
        $config = array(
            LoggerInterface::class => function() {
                return new ArrayLogger();
            },
            SimpleClass::class => function() {
                return new SimpleClass();
            },
        );

        $this->container->load_config($config);

        $this->assertTrue($this->container->has(LoggerInterface::class));
        $this->assertTrue($this->container->has(SimpleClass::class));

        $logger = $this->container->get(LoggerInterface::class);
        $this->assertInstanceOf(ArrayLogger::class, $logger);
    }

    // ========================================
    // Exception Tests
    // ========================================

    public function test_throws_exception_for_invalid_abstract_in_bind(): void {
        $this->expectException(Container_Exception::class);
        $this->expectExceptionMessage("'InvalidClass' must be a valid class or interface name");

        $this->container->bind('InvalidClass');
    }

    // Note: test_throws_exception_for_non_callable_factory removed
    // The ?callable type hint makes it impossible to pass non-callable values,
    // so there's no code path to test. PHP's type system handles this.

    public function test_throws_exception_for_invalid_class_in_get(): void {
        $this->expectException(Not_Found_Exception::class);
        $this->expectExceptionMessage("'invalid-string' must be a valid class or interface name");

        $this->container->get('invalid-string');
    }

    public function test_throws_exception_for_abstract_class(): void {
        $this->expectException(Container_Exception::class);
        $this->expectExceptionMessage('is not instantiable');

        $this->container->get(AbstractClass::class);
    }

    public function test_throws_exception_for_unbound_interface(): void {
        $this->expectException(Container_Exception::class);

        $this->container->get(ClassWithInterface::class);
    }

    public function test_throws_exception_when_cannot_resolve_parameter(): void {
        // Create a class that requires a primitive type we can't resolve
        $this->expectException(Container_Exception::class);
        $this->expectExceptionMessage('Cannot resolve parameter');

        // We'll test with a class requiring a string parameter
        $class = new class('test') {
            public function __construct(string $required) {}
        };

        $this->container->get(get_class($class));
    }

    // ========================================
    // Utility Method Tests
    // ========================================

    public function test_get_registered_returns_bound_services(): void {
        $this->container->bind(SimpleClass::class);
        $this->container->bind(LoggerInterface::class, function() {
            return new ArrayLogger();
        });

        $registered = $this->container->get_registered();

        $this->assertContains(SimpleClass::class, $registered);
        $this->assertContains(LoggerInterface::class, $registered);
        $this->assertCount(2, $registered);
    }

    public function test_clear_removes_all_bindings_and_instances(): void {
        $this->container->bind(SimpleClass::class);
        $this->container->get(SimpleClass::class);

        $this->assertTrue($this->container->has(SimpleClass::class));

        $this->container->clear();

        $registered = $this->container->get_registered();
        $this->assertEmpty($registered);

        // Should still be able to autowire after clear
        $this->assertTrue($this->container->has(SimpleClass::class));
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function test_circular_dependency_handling(): void {
        $this->expectException(Container_Exception::class);
        $this->expectExceptionMessage('Circular dependency detected');

        // CircularA depends on CircularB, which depends back on CircularA
        $this->container->get(CircularA::class);
    }

    public function test_can_override_binding(): void {
        $this->container->bind(SimpleClass::class, function() {
            $obj = new SimpleClass();
            return $obj;
        });

        $instance1 = $this->container->get(SimpleClass::class);

        // Clear the container to remove cached singleton
        $this->container->clear();

        // Override with different factory
        $this->container->bind(SimpleClass::class, function() {
            return new SimpleClass();
        });

        $instance2 = $this->container->get(SimpleClass::class);

        // Since we cleared and re-bound, we should get a different instance
        $this->assertNotSame($instance1, $instance2);
    }
}
