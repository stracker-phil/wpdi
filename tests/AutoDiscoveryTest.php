<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Auto_Discovery;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Tests\Fixtures\ArrayLogger;
use WPDI\Tests\Fixtures\LoggerInterface;
use WPDI\Tests\Fixtures\AbstractClass;

class AutoDiscoveryTest extends TestCase {

    private Auto_Discovery $discovery;
    private string $fixtures_path;

    protected function setUp(): void {
        $this->discovery = new Auto_Discovery();
        $this->fixtures_path = __DIR__ . '/Fixtures';

        // Ensure fixture classes are loaded for testing
        // In real usage, classes would be autoloaded when needed
        $files = glob($this->fixtures_path . '/*.php');
        foreach ($files as $file) {
            require_once $file;
        }
    }

    // ========================================
    // Basic Discovery Tests
    // ========================================

    public function test_discovers_classes_in_directory(): void {
        $classes = $this->discovery->discover($this->fixtures_path);

        $this->assertIsArray($classes);
        $this->assertNotEmpty($classes);
    }

    public function test_discovers_concrete_classes_only(): void {
        $classes = $this->discovery->discover($this->fixtures_path);

        // Should include concrete classes
        $this->assertContains(SimpleClass::class, $classes);
        $this->assertContains(ClassWithDependency::class, $classes);
        $this->assertContains(ArrayLogger::class, $classes);

        // Should NOT include interfaces
        $this->assertNotContains(LoggerInterface::class, $classes);

        // Should NOT include abstract classes
        $this->assertNotContains(AbstractClass::class, $classes);
    }

    public function test_returns_fully_qualified_class_names(): void {
        $classes = $this->discovery->discover($this->fixtures_path);

        foreach ($classes as $class) {
            $this->assertIsString($class);
            $this->assertTrue(class_exists($class), "Class {$class} should exist");
            $this->assertStringStartsWith('WPDI\\Tests\\Fixtures\\', $class);
        }
    }

    public function test_all_discovered_classes_are_instantiable(): void {
        $classes = $this->discovery->discover($this->fixtures_path);

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue(
                $reflection->isInstantiable(),
                "Class {$class} should be instantiable"
            );
        }
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function test_returns_empty_array_for_non_existent_directory(): void {
        $classes = $this->discovery->discover('/non/existent/path');

        $this->assertIsArray($classes);
        $this->assertEmpty($classes);
    }

    public function test_returns_empty_array_for_file_instead_of_directory(): void {
        $file_path = $this->fixtures_path . '/SimpleClass.php';

        $classes = $this->discovery->discover($file_path);

        $this->assertIsArray($classes);
        $this->assertEmpty($classes);
    }

    public function test_handles_empty_directory(): void {
        // Create a temporary empty directory
        $temp_dir = sys_get_temp_dir() . '/wpdi_test_empty_' . uniqid();
        mkdir($temp_dir);

        $classes = $this->discovery->discover($temp_dir);

        $this->assertIsArray($classes);
        $this->assertEmpty($classes);

        // Cleanup
        rmdir($temp_dir);
    }

    // ========================================
    // Namespace Tests
    // ========================================

    public function test_correctly_handles_namespaced_classes(): void {
        $classes = $this->discovery->discover($this->fixtures_path);

        // All classes should have the correct namespace
        foreach ($classes as $class) {
            $this->assertStringStartsWith('WPDI\\Tests\\Fixtures\\', $class);
        }
    }

    public function test_discovers_classes_in_nested_directories(): void {
        // Create a temporary nested structure
        $temp_dir = sys_get_temp_dir() . '/wpdi_test_nested_' . uniqid();
        $nested_dir = $temp_dir . '/nested';
        mkdir($nested_dir, 0777, true);

        // Create a test file in nested directory
        $test_file = $nested_dir . '/TestClass.php';
        file_put_contents($test_file, '<?php
namespace WPDI\\Tests\\Temp;
class TestClass {
    public function test() { return "test"; }
}
');

        // Load the class
        require_once $test_file;

        $classes = $this->discovery->discover($temp_dir);

        $this->assertContains('WPDI\\Tests\\Temp\\TestClass', $classes);

        // Cleanup
        unlink($test_file);
        rmdir($nested_dir);
        rmdir($temp_dir);
    }

    // ========================================
    // Filter Tests
    // ========================================

    public function test_filters_out_traits(): void {
        // Create a temporary directory with a trait
        $temp_dir = sys_get_temp_dir() . '/wpdi_test_trait_' . uniqid();
        mkdir($temp_dir);

        $trait_file = $temp_dir . '/TestTrait.php';
        file_put_contents($trait_file, '<?php
namespace WPDI\\Tests\\Temp;
trait TestTrait {
    public function test() {}
}
');

        require_once $trait_file;

        $classes = $this->discovery->discover($temp_dir);

        // Traits should not be discovered
        $this->assertNotContains('WPDI\\Tests\\Temp\\TestTrait', $classes);

        // Cleanup
        unlink($trait_file);
        rmdir($temp_dir);
    }

    public function test_filters_classes_without_namespace(): void {
        // Create a temporary directory with a class without namespace
        $temp_dir = sys_get_temp_dir() . '/wpdi_test_no_ns_' . uniqid();
        mkdir($temp_dir);

        $class_file = $temp_dir . '/GlobalClass.php';
        file_put_contents($class_file, '<?php
class GlobalClass_' . uniqid() . ' {
    public function test() {}
}
');

        require_once $class_file;

        $classes = $this->discovery->discover($temp_dir);

        // Global classes should still be discovered
        $this->assertNotEmpty($classes);

        // Cleanup
        unlink($class_file);
        rmdir($temp_dir);
    }

    // ========================================
    // Performance Tests
    // ========================================

    public function test_discovery_is_reasonably_fast(): void {
        $start = microtime(true);

        $this->discovery->discover($this->fixtures_path);

        $duration = microtime(true) - $start;

        // Discovery should complete in under 1 second for small directory
        $this->assertLessThan(1.0, $duration, 'Discovery took too long');
    }
}
