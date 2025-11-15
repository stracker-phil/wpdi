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

/**
 * @covers \WPDI\Auto_Discovery
 */
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
		$class_map = $this->discovery->discover( $this->fixtures_path );
		$classes   = array_keys( $class_map );

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
		$class_map = $this->discovery->discover( $this->fixtures_path );

		foreach ( $class_map as $class => $file_path ) {
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
		$class_map = $this->discovery->discover( $this->fixtures_path );

		foreach ( $class_map as $class => $file_path ) {
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
			'non-existent directory'            => array(
				function () {
					return array( '/non/existent/path', null );
				},
				function ( $resources ) {
					// No cleanup needed
				},
			),
			'file path instead of directory'    => array(
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
			'php file with anonymous class'     => array(
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
			'php file with trait definition'    => array(
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
			'empty directory'                   => array(
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

		// Create a temporary file for mtime to work
		$temp_file = sys_get_temp_dir() . '/wpdi_test_' . uniqid() . '.php';
		file_put_contents( $temp_file, '<?php' );

		// filter_concrete_classes now expects class => filepath mapping
		$class_map = array(
			SimpleClass::class     => $temp_file,    // Concrete - should be included
			AbstractClass::class   => $temp_file,    // Abstract - should be excluded
			LoggerInterface::class => $temp_file,    // Interface - should be excluded
			'NonExistent'          => $temp_file,    // Doesn't exist as class - skipped
			'stdClass'             => $temp_file,    // Internal class - instantiable, included
			'ArrayObject'          => $temp_file,    // SPL class - instantiable, included
			'DateTime'             => $temp_file,    // Date/time class - instantiable, included
		);

		$result         = $method->invoke( $discovery, $class_map );
		$result_classes = array_keys( $result );

		// Cleanup
		@unlink( $temp_file );

		// Should only include concrete, instantiable classes
		$this->assertContains( SimpleClass::class, $result_classes );
		$this->assertContains( 'stdClass', $result_classes );
		$this->assertContains( 'ArrayObject', $result_classes );
		$this->assertContains( 'DateTime', $result_classes );
		$this->assertNotContains( AbstractClass::class, $result_classes );
		$this->assertNotContains( LoggerInterface::class, $result_classes );
		$this->assertNotContains( 'NonExistent', $result_classes );

		// Verify metadata structure
		$this->assertArrayHasKey( 'path', $result[ SimpleClass::class ] );
		$this->assertArrayHasKey( 'mtime', $result[ SimpleClass::class ] );
		$this->assertArrayHasKey( 'dependencies', $result[ SimpleClass::class ] );

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
		$class_map = $this->discovery->discover( $this->fixtures_path );

		// All classes should have the correct namespace
		foreach ( $class_map as $class => $file_path ) {
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

		$class_map = $this->discovery->discover( $temp_dir );

		$this->assertContains( 'WPDI\\Tests\\Temp\\TestClass', array_keys( $class_map ) );

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
	// parse_file() Tests
	// ========================================

	/**
	 * GIVEN a PHP file containing a concrete class
	 * WHEN parse_file() is called
	 * THEN it returns metadata for the class with path, mtime, and dependencies
	 */
	public function test_parse_file_returns_metadata_for_concrete_class(): void {
		$reflection = new ReflectionClass( SimpleClass::class );
		$file_path  = $reflection->getFileName();

		$result = $this->discovery->parse_file( $file_path );

		$this->assertArrayHasKey( SimpleClass::class, $result );
		$this->assertArrayHasKey( 'path', $result[ SimpleClass::class ] );
		$this->assertArrayHasKey( 'mtime', $result[ SimpleClass::class ] );
		$this->assertArrayHasKey( 'dependencies', $result[ SimpleClass::class ] );
		$this->assertSame( $file_path, $result[ SimpleClass::class ]['path'] );
		$this->assertIsInt( $result[ SimpleClass::class ]['mtime'] );
		$this->assertIsArray( $result[ SimpleClass::class ]['dependencies'] );
	}

	/**
	 * GIVEN a PHP file containing a class with constructor dependencies
	 * WHEN parse_file() is called
	 * THEN it extracts the dependency class names
	 */
	public function test_parse_file_extracts_dependencies(): void {
		$reflection = new ReflectionClass( ClassWithDependency::class );
		$file_path  = $reflection->getFileName();

		$result = $this->discovery->parse_file( $file_path );

		$this->assertArrayHasKey( ClassWithDependency::class, $result );
		$this->assertContains(
			SimpleClass::class,
			$result[ ClassWithDependency::class ]['dependencies']
		);
	}

	/**
	 * GIVEN a PHP file containing no class definition
	 * WHEN parse_file() is called
	 * THEN it returns an empty array
	 */
	public function test_parse_file_returns_empty_for_no_class(): void {
		$temp_file = sys_get_temp_dir() . '/wpdi_test_functions_' . uniqid() . '.php';
		file_put_contents( $temp_file, "<?php\nfunction some_function() { return 1; }\n" );

		$result = $this->discovery->parse_file( $temp_file );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );

		unlink( $temp_file );
	}

	/**
	 * GIVEN a PHP file containing an interface
	 * WHEN parse_file() is called
	 * THEN it returns an empty array (interfaces not instantiable)
	 */
	public function test_parse_file_excludes_interfaces(): void {
		$reflection = new ReflectionClass( LoggerInterface::class );
		$file_path  = $reflection->getFileName();

		$result = $this->discovery->parse_file( $file_path );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( LoggerInterface::class, $result );
	}

	/**
	 * GIVEN a PHP file containing an abstract class
	 * WHEN parse_file() is called
	 * THEN it returns an empty array (abstract classes not instantiable)
	 */
	public function test_parse_file_excludes_abstract_classes(): void {
		$reflection = new ReflectionClass( AbstractClass::class );
		$file_path  = $reflection->getFileName();

		$result = $this->discovery->parse_file( $file_path );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( AbstractClass::class, $result );
	}

	/**
	 * GIVEN a PHP file containing multiple classes
	 * WHEN parse_file() is called
	 * THEN it returns metadata for all concrete classes in the file
	 */
	public function test_parse_file_handles_multiple_classes_in_file(): void {
		$temp_file = sys_get_temp_dir() . '/wpdi_test_multi_' . uniqid() . '.php';
		$class1    = 'MultiClass1_' . uniqid();
		$class2    = 'MultiClass2_' . uniqid();
		$content   = "<?php\nclass {$class1} {}\nclass {$class2} {}\n";
		file_put_contents( $temp_file, $content );
		require_once $temp_file;

		$result = $this->discovery->parse_file( $temp_file );

		$this->assertArrayHasKey( $class1, $result );
		$this->assertArrayHasKey( $class2, $result );
		$this->assertSame( $temp_file, $result[ $class1 ]['path'] );
		$this->assertSame( $temp_file, $result[ $class2 ]['path'] );

		unlink( $temp_file );
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
