<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Compiler;
use WPDI\Tests\Fixtures\SimpleClass;

/**
 * @covers \WPDI\Compiler
 */
class CompilerTest extends TestCase {

	private Compiler $compiler;
	private string $temp_dir;

	protected function setUp(): void {
		$this->compiler = new Compiler();
		$this->temp_dir = sys_get_temp_dir() . '/wpdi_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );
	}

	protected function tearDown(): void {
		// Cleanup temporary files recursively
		if ( is_dir( $this->temp_dir ) ) {
			$this->recursiveRemoveDirectory( $this->temp_dir );
		}
	}

	private function recursiveRemoveDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->recursiveRemoveDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	// ========================================
	// Basic Compilation Tests
	// ========================================

	/**
	 * GIVEN a list of class names to compile
	 * WHEN compile() is called with a target file path
	 * THEN a valid cache file is created at the specified location
	 */
	public function test_compile_creates_cache_file(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array(
			SimpleClass::class,
			'WPDI\Tests\Fixtures\ClassWithDependency',
		);

		$result = $this->compiler->compile( $classes, $cache_file );

		$this->assertTrue( $result );
		$this->assertFileExists( $cache_file );
	}

	/**
	 * GIVEN a compiled cache file
	 * WHEN examining its contents
	 * THEN it contains valid PHP code that returns an array of class names
	 */
	public function test_compiled_file_contains_valid_php(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array(
			SimpleClass::class,
		);

		$this->compiler->compile( $classes, $cache_file );

		// File should be valid PHP
		$content = file_get_contents( $cache_file );
		$this->assertStringStartsWith( '<?php', $content );
		$this->assertStringContainsString( 'return', $content );
	}

	/**
	 * GIVEN a compiled cache file exists
	 * WHEN the file is required in PHP
	 * THEN it returns the exact array of class names that was compiled
	 */
	public function test_compiled_file_can_be_required(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array(
			SimpleClass::class,
			'WPDI\Tests\Fixtures\ClassWithDependency',
		);

		$this->compiler->compile( $classes, $cache_file );

		// Should be able to require the file and get an array of class names
		$compiled = require $cache_file;

		$this->assertIsArray( $compiled );
		$this->assertCount( 2, $compiled );
		$this->assertContains( SimpleClass::class, $compiled );
		$this->assertContains( 'WPDI\Tests\Fixtures\ClassWithDependency', $compiled );
	}

	/**
	 * GIVEN a compiled cache file
	 * WHEN loaded and inspected
	 * THEN it contains only class names (not closures or complex data structures)
	 */
	public function test_compiled_cache_is_simple_array(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array(
			SimpleClass::class,
			'WPDI\Tests\Fixtures\ArrayLogger',
		);

		$this->compiler->compile( $classes, $cache_file );
		$compiled = require $cache_file;

		// Cache should be a simple indexed array of class names
		$this->assertIsArray( $compiled );
		$this->assertEquals( $classes, $compiled );
	}

	// ========================================
	// Cache Directory Tests
	// ========================================

	/**
	 * GIVEN a cache file path with non-existent parent directories
	 * WHEN compile() is called
	 * THEN the necessary directories are created automatically
	 */
	public function test_compile_creates_cache_directory_if_missing(): void {
		$nested_dir = $this->temp_dir . '/cache/nested';
		$cache_file = $nested_dir . '/cache.php';

		$classes = array( SimpleClass::class );

		$result = $this->compiler->compile( $classes, $cache_file );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $nested_dir );
		$this->assertFileExists( $cache_file );
	}

	// ========================================
	// Multiple Bindings Tests
	// ========================================

	/**
	 * GIVEN multiple class names to compile
	 * WHEN the compiler processes them
	 * THEN all classes are included in the cache in the correct order
	 *
	 * @dataProvider multiple_classes_provider
	 */
	public function test_compile_handles_multiple_classes(
		array $classes,
		int $expected_count
	): void {
		$cache_file = $this->temp_dir . '/cache.php';

		$this->compiler->compile( $classes, $cache_file );
		$compiled = require $cache_file;

		$this->assertCount( $expected_count, $compiled );
		$this->assertEquals( $classes, $compiled );
	}

	public function multiple_classes_provider(): array {
		return array(
			'three classes'    => array(
				array(
					SimpleClass::class,
					'WPDI\Tests\Fixtures\ArrayLogger',
					'WPDI\Tests\Fixtures\ClassWithDependency',
				),
				3,
			),
			'ordered classes'  => array(
				array( 'ClassA', 'ClassB', 'ClassC' ),
				3,
			),
			'empty class list' => array(
				array(),
				0,
			),
		);
	}

	// ========================================
	// Content Tests
	// ========================================

	/**
	 * GIVEN a compiled cache file
	 * WHEN examining its header comments
	 * THEN it contains metadata about generation time and class count
	 */
	public function test_compiled_file_contains_header_comment(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array( SimpleClass::class );

		$this->compiler->compile( $classes, $cache_file );
		$content = file_get_contents( $cache_file );

		$this->assertStringContainsString( 'Auto-generated WPDI cache', $content );
		$this->assertStringContainsString( 'do not edit', $content );
		$this->assertStringContainsString( 'Generated:', $content );
		$this->assertStringContainsString( 'Contains: 1 discovered classes', $content );
	}

	/**
	 * GIVEN a compiled cache file
	 * WHEN examining its contents
	 * THEN it includes a timestamp indicating when it was generated
	 */
	public function test_compiled_file_includes_timestamp(): void {
		$cache_file = $this->temp_dir . '/cache.php';
		$classes    = array( SimpleClass::class );

		$this->compiler->compile( $classes, $cache_file );
		$content = file_get_contents( $cache_file );

		// Should contain a date in some format
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}/', $content );
	}

	// ========================================
	// Error Handling Tests
	// ========================================

	/**
	 * GIVEN file system write permissions are denied
	 * WHEN compile() attempts to write the cache file
	 * THEN it returns false to indicate failure
	 */
	public function test_compile_returns_false_on_write_failure(): void {
		// Create a read-only directory to test write failure
		$readonly_dir = $this->temp_dir . '/readonly';
		mkdir( $readonly_dir );

		// Create a file and make it read-only
		$cache_file = $readonly_dir . '/cache.php';
		file_put_contents( $cache_file, '<?php // placeholder' );
		chmod( $cache_file, 0444 ); // Make file read-only

		$classes = array( SimpleClass::class );

		// Suppress the expected warning from file_put_contents
		$result = @$this->compiler->compile( $classes, $cache_file );

		// Clean up - restore permissions before deletion
		chmod( $cache_file, 0644 );

		$this->assertFalse( $result, 'Compile should return false when file_put_contents fails' );
	}
}
