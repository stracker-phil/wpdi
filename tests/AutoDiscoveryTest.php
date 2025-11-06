<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Auto_Discovery;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Tests\Fixtures\ArrayLogger;
use WPDI\Tests\Fixtures\LoggerInterface;
use WPDI\Tests\Fixtures\AbstractClass;
use ReflectionClass;

class AutoDiscoveryTest extends TestCase {

	private Auto_Discovery $discovery;
	private string $fixtures_path;

	protected function setUp(): void {
		$this->discovery     = new Auto_Discovery();
		$this->fixtures_path = __DIR__ . '/Fixtures';

		// Ensure fixture classes are loaded for testing
		// In real usage, classes would be autoloaded when needed
		$files = glob( $this->fixtures_path . '/*.php' );
		foreach ( $files as $file ) {
			require_once $file;
		}
	}

	// ========================================
	// Basic Discovery Tests
	// ========================================

	/**
	 * GIVEN a directory containing PHP classes
	 * WHEN Auto_Discovery scans the directory
	 * THEN it returns an array of fully-qualified class names
	 */
	public function test_discovers_classes_in_directory(): void {
		$classes = $this->discovery->discover( $this->fixtures_path );

		$this->assertIsArray( $classes );
		$this->assertNotEmpty( $classes );
	}

	/**
	 * GIVEN a directory with concrete classes, interfaces, and abstract classes
	 * WHEN Auto_Discovery scans the directory
	 * THEN only concrete instantiable classes are included in the result
	 */
	public function test_discovers_concrete_classes_only(): void {
		$classes = $this->discovery->discover( $this->fixtures_path );

		// Should include concrete classes
		$this->assertContains( SimpleClass::class, $classes );
		$this->assertContains( ClassWithDependency::class, $classes );
		$this->assertContains( ArrayLogger::class, $classes );

		// Should NOT include interfaces
		$this->assertNotContains( LoggerInterface::class, $classes );

		// Should NOT include abstract classes
		$this->assertNotContains( AbstractClass::class, $classes );
	}

	/**
	 * GIVEN discovered classes from a directory
	 * WHEN examining the returned class names
	 * THEN all are fully-qualified strings that exist and start with expected namespace
	 */
	public function test_returns_fully_qualified_class_names(): void {
		$classes = $this->discovery->discover( $this->fixtures_path );

		foreach ( $classes as $class ) {
			$this->assertIsString( $class );
			$this->assertTrue( class_exists( $class ), "Class {$class} should exist" );
			$this->assertStringStartsWith( 'WPDI\\Tests\\Fixtures\\', $class );
		}
	}

	/**
	 * GIVEN discovered classes from a directory
	 * WHEN checking their reflection metadata
	 * THEN all classes are instantiable (not abstract, not interface)
	 */
	public function test_all_discovered_classes_are_instantiable(): void {
		$classes = $this->discovery->discover( $this->fixtures_path );

		foreach ( $classes as $class ) {
			$reflection = new ReflectionClass( $class );
			$this->assertTrue(
				$reflection->isInstantiable(),
				"Class {$class} should be instantiable"
			);
		}
	}

	// ========================================
	// Edge Cases
	// ========================================

	/**
	 * GIVEN various edge case scenarios for directory scanning
	 * WHEN Auto_Discovery attempts to scan
	 * THEN it handles edge cases gracefully and returns empty array
	 *
	 * @dataProvider edge_case_scenarios_provider
	 */
	public function test_handles_edge_case_scenarios(
		callable $setup,
		callable $cleanup
	): void {
		list( $path, $temp_resources ) = $setup();

		$classes = $this->discovery->discover( $path );

		$this->assertIsArray( $classes );
		$this->assertEmpty( $classes );

		$cleanup( $temp_resources );
	}

	public function edge_case_scenarios_provider(): array {
		return array(
			'non-existent directory' => array(
				function () {
					return array( '/non/existent/path', null );
				},
				function ( $resources ) {
					// No cleanup needed
				},
			),
			'file path instead of directory' => array(
				function () {
					$file_path = __DIR__ . '/Fixtures/SimpleClass.php';
					return array( $file_path, null );
				},
				function ( $resources ) {
					// No cleanup needed
				},
			),
			'php file with no class definition' => array(
				function () {
					$temp_dir = sys_get_temp_dir() . '/wpdi_test_noclass_' . uniqid();
					mkdir( $temp_dir );
					$php_file = $temp_dir . '/functions.php';
					file_put_contents( $php_file, "<?php\nfunction some_function() {}\n" );
					return array( $temp_dir, array( 'dir' => $temp_dir, 'file' => $php_file ) );
				},
				function ( $resources ) {
					unlink( $resources['file'] );
					rmdir( $resources['dir'] );
				},
			),
			'php file with anonymous class' => array(
				function () {
					$temp_dir = sys_get_temp_dir() . '/wpdi_test_anon_' . uniqid();
					mkdir( $temp_dir );
					$php_file = $temp_dir . '/anonymous.php';
					file_put_contents( $php_file, "<?php\nnamespace Test;\n\$obj = new class {};" );
					return array( $temp_dir, array( 'dir' => $temp_dir, 'file' => $php_file ) );
				},
				function ( $resources ) {
					unlink( $resources['file'] );
					rmdir( $resources['dir'] );
				},
			),
			'php file with trait definition' => array(
				function () {
					$temp_dir = sys_get_temp_dir() . '/wpdi_test_trait_' . uniqid();
					mkdir( $temp_dir );
					$php_file = $temp_dir . '/MyTrait.php';
					file_put_contents( $php_file, "<?php\nnamespace Test;\ntrait MyTrait {}\n" );
					return array( $temp_dir, array( 'dir' => $temp_dir, 'file' => $php_file ) );
				},
				function ( $resources ) {
					unlink( $resources['file'] );
					rmdir( $resources['dir'] );
				},
			),
			'empty directory' => array(
				function () {
					$temp_dir = sys_get_temp_dir() . '/wpdi_test_empty_' . uniqid();
					mkdir( $temp_dir );
					return array( $temp_dir, $temp_dir );
				},
				function ( $resources ) {
					rmdir( $resources );
				},
			),
		);
	}

	/**
	 * GIVEN the filter_concrete_classes method is called with various class types
	 * WHEN filtering for instantiable classes
	 * THEN only concrete classes are included, interfaces and abstract classes excluded
	 * AND exception handling gracefully skips problematic classes
	 */
	public function test_filter_concrete_classes_handles_various_class_types(): void {
		// Test the filter with a comprehensive mix of class types
		// This covers both normal filtering and the defensive exception handler (lines 121-123)
		$discovery  = new Auto_Discovery();
		$reflection = new ReflectionClass( $discovery );
		$method     = $reflection->getMethod( 'filter_concrete_classes' );
		$method->setAccessible( true );

		$classes = array(
			SimpleClass::class,    // Concrete - should be included
			AbstractClass::class,  // Abstract - should be excluded
			LoggerInterface::class, // Interface - should be excluded
			'NonExistent',         // Doesn't exist - skipped
			'stdClass',            // Internal class - instantiable, included
			'ArrayObject',         // SPL class - instantiable, included
			'DateTime',            // Date/time class - instantiable, included
		);

		$result = $method->invoke( $discovery, $classes );

		// Should only include concrete, instantiable classes
		$this->assertContains( SimpleClass::class, $result );
		$this->assertContains( 'stdClass', $result );
		$this->assertContains( 'ArrayObject', $result );
		$this->assertContains( 'DateTime', $result );
		$this->assertNotContains( AbstractClass::class, $result );
		$this->assertNotContains( LoggerInterface::class, $result );
		$this->assertNotContains( 'NonExistent', $result );

		// The exception handler (lines 121-123) is defensive code for rare edge cases
		// that are nearly impossible to trigger without PHP extensions or runtime corruption
		$this->assertGreaterThan( 0, count( $result ) );
	}

	// ========================================
	// Namespace Tests
	// ========================================

	/**
	 * GIVEN a directory with namespaced PHP classes
	 * WHEN classes are discovered
	 * THEN all returned class names are fully-qualified with correct namespace
	 */
	public function test_correctly_handles_namespaced_classes(): void {
		$classes = $this->discovery->discover( $this->fixtures_path );

		// All classes should have the correct namespace
		foreach ( $classes as $class ) {
			$this->assertStringStartsWith( 'WPDI\\Tests\\Fixtures\\', $class );
		}
	}

	/**
	 * GIVEN a directory with nested subdirectories containing PHP classes
	 * WHEN discovery scans recursively
	 * THEN classes in nested directories are discovered with correct namespaces
	 */
	public function test_discovers_classes_in_nested_directories(): void {
		// Create a temporary nested structure
		$temp_dir   = sys_get_temp_dir() . '/wpdi_test_nested_' . uniqid();
		$nested_dir = $temp_dir . '/nested';
		mkdir( $nested_dir, 0777, true );

		// Create a test file in nested directory
		$test_file = $nested_dir . '/TestClass.php';
		file_put_contents( $test_file, '<?php
namespace WPDI\\Tests\\Temp;
class TestClass {
    public function test() { return "test"; }
}
' );

		// Load the class
		require_once $test_file;

		$classes = $this->discovery->discover( $temp_dir );

		$this->assertContains( 'WPDI\\Tests\\Temp\\TestClass', $classes );

		// Cleanup
		unlink( $test_file );
		rmdir( $nested_dir );
		rmdir( $temp_dir );
	}

	/**
	 * GIVEN a directory with classes both with and without namespaces
	 * WHEN discovery scans the directory
	 * THEN global namespace classes are also discovered
	 */
	public function test_discovers_classes_without_namespace(): void {
		// Create a temporary directory with a class without namespace
		$temp_dir = sys_get_temp_dir() . '/wpdi_test_no_ns_' . uniqid();
		mkdir( $temp_dir );

		$class_file = $temp_dir . '/GlobalClass.php';
		file_put_contents( $class_file, '<?php
class GlobalClass_' . uniqid() . ' {
    public function test() {}
}
' );

		require_once $class_file;

		$classes = $this->discovery->discover( $temp_dir );

		// Global classes should still be discovered
		$this->assertNotEmpty( $classes );

		// Cleanup
		unlink( $class_file );
		rmdir( $temp_dir );
	}

	// ========================================
	// Performance Tests
	// ========================================

	/**
	 * GIVEN a small directory with multiple PHP files
	 * WHEN discovery scans the directory
	 * THEN the operation completes in reasonable time (under 1 second)
	 */
	public function test_discovery_is_reasonably_fast(): void {
		$start = microtime( true );

		$this->discovery->discover( $this->fixtures_path );

		$duration = microtime( true ) - $start;

		// Discovery should complete in under 1 second for small directory
		$this->assertLessThan( 1.0, $duration, 'Discovery took too long' );
	}
}
