<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Compiler;
use WPDI\Tests\Fixtures\SimpleClass;

/**
 * @covers \WPDI\Compiler
 */
class CompilerTest extends TestCase {

	private string $temp_dir;
	private string $cache_file;

	protected function setUp(): void {
		$this->temp_dir   = sys_get_temp_dir() . '/wpdi_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );
		$this->cache_file = $this->temp_dir . '/cache/wpdi-container.php';
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
	// Basic File Operations Tests
	// ========================================

	/**
	 * GIVEN a Compiler instance with a base path
	 * WHEN get_cache_file() is called
	 * THEN it returns the full cache file path
	 */
	public function test_get_cache_file_returns_full_path(): void {
		$compiler = new Compiler( $this->temp_dir );

		$this->assertEquals( $this->cache_file, $compiler->get_cache_file() );
	}

	/**
	 * GIVEN a Compiler instance with a base path
	 * WHEN exists() is called and the file doesn't exist
	 * THEN it returns false
	 */
	public function test_exists_returns_false_when_file_missing(): void {
		$compiler = new Compiler( $this->temp_dir );

		$this->assertFalse( $compiler->exists() );
	}

	/**
	 * GIVEN a Compiler instance with an existing cache file
	 * WHEN exists() is called
	 * THEN it returns true
	 */
	public function test_exists_returns_true_when_file_present(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$compiler = new Compiler( $this->temp_dir );

		$this->assertTrue( $compiler->exists() );
	}

	/**
	 * GIVEN a Compiler instance with an existing cache file
	 * WHEN get_mtime() is called
	 * THEN it returns the file modification time
	 */
	public function test_get_mtime_returns_file_modification_time(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$expected_mtime = filemtime( $this->cache_file );
		$compiler       = new Compiler( $this->temp_dir );

		$this->assertEquals( $expected_mtime, $compiler->get_mtime() );
	}

	/**
	 * GIVEN a Compiler instance with an existing cache file
	 * WHEN load() is called
	 * THEN it returns the array from the cache file
	 */
	public function test_load_returns_cached_array(): void {
		$expected = array(
			'TestClass' => array(
				'path'         => '/path/to/TestClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return ' . var_export( $expected, true ) . ';' );
		$compiler = new Compiler( $this->temp_dir );

		$this->assertEquals( $expected, $compiler->load() );
	}

	/**
	 * GIVEN a Compiler instance with an existing cache file
	 * WHEN delete() is called
	 * THEN the cache file is removed
	 */
	public function test_delete_removes_cache_file(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$compiler = new Compiler( $this->temp_dir );

		$compiler->delete();

		$this->assertFileDoesNotExist( $this->cache_file );
	}

	/**
	 * GIVEN a Compiler instance with a non-existent cache file
	 * WHEN delete() is called
	 * THEN it silently does nothing (no error)
	 */
	public function test_delete_handles_missing_file_gracefully(): void {
		$compiler = new Compiler( $this->temp_dir );

		// Should not throw any errors
		$compiler->delete();

		$this->assertFileDoesNotExist( $this->cache_file );
	}

	// ========================================
	// Write Operation Tests
	// ========================================

	/**
	 * GIVEN a class map to write
	 * WHEN write() is called
	 * THEN a valid cache file is created at the specified location
	 */
	public function test_write_creates_cache_file(): void {
		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $compiler->write( $class_map );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->cache_file );
	}

	/**
	 * GIVEN a compiled cache file
	 * WHEN examining its contents
	 * THEN it contains valid PHP code that returns an array
	 */
	public function test_written_file_contains_valid_php(): void {
		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$compiler->write( $class_map );

		$content = file_get_contents( $this->cache_file );
		$this->assertStringStartsWith( '<?php', $content );
		$this->assertStringContainsString( 'return', $content );
	}

	/**
	 * GIVEN a compiled cache file exists
	 * WHEN the file is required in PHP
	 * THEN it returns the exact class map that was written
	 */
	public function test_written_file_can_be_required(): void {
		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class                            => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
			'WPDI\Tests\Fixtures\ClassWithDependency' => array(
				'path'         => '/path/to/ClassWithDependency.php',
				'mtime'        => 1234567891,
				'dependencies' => array( 'SomeDependency' ),
			),
		);

		$compiler->write( $class_map );
		$compiled = require $this->cache_file;

		$this->assertIsArray( $compiled );
		$this->assertCount( 2, $compiled );
		$this->assertArrayHasKey( SimpleClass::class, $compiled );
		$this->assertArrayHasKey( 'WPDI\Tests\Fixtures\ClassWithDependency', $compiled );
		$this->assertEquals( $class_map, $compiled );
	}

	// ========================================
	// Cache Directory Tests
	// ========================================

	/**
	 * GIVEN a base path with non-existent cache directory
	 * WHEN write() is called
	 * THEN the cache directory is created automatically
	 */
	public function test_write_creates_cache_directory_if_missing(): void {
		$compiler = new Compiler( $this->temp_dir );

		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $compiler->write( $class_map );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( dirname( $this->cache_file ) );
		$this->assertFileExists( $this->cache_file );
	}

	// ========================================
	// Multiple Classes Tests
	// ========================================

	/**
	 * GIVEN multiple classes in the class map
	 * WHEN the compiler writes them
	 * THEN all classes are included in the cache
	 *
	 * @dataProvider multiple_classes_provider
	 */
	public function test_write_handles_multiple_classes(
		array $class_map,
		int $expected_count
	): void {
		$compiler = new Compiler( $this->temp_dir );

		$compiler->write( $class_map );
		$compiled = require $this->cache_file;

		$this->assertCount( $expected_count, $compiled );
		$this->assertEquals( $class_map, $compiled );
	}

	public function multiple_classes_provider(): array {
		return array(
			'three classes'    => array(
				array(
					SimpleClass::class                       => array(
						'path'         => '/path/to/SimpleClass.php',
						'mtime'        => 1234567890,
						'dependencies' => array(),
					),
					'WPDI\Tests\Fixtures\ArrayLogger'        => array(
						'path'         => '/path/to/ArrayLogger.php',
						'mtime'        => 1234567891,
						'dependencies' => array(),
					),
					'WPDI\Tests\Fixtures\ClassWithDependency' => array(
						'path'         => '/path/to/ClassWithDependency.php',
						'mtime'        => 1234567892,
						'dependencies' => array( 'SomeDep' ),
					),
				),
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
	public function test_written_file_contains_header_comment(): void {
		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$compiler->write( $class_map );
		$content = file_get_contents( $this->cache_file );

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
	public function test_written_file_includes_timestamp(): void {
		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$compiler->write( $class_map );
		$content = file_get_contents( $this->cache_file );

		// Should contain a date in some format
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}/', $content );
	}

	// ========================================
	// Error Handling Tests
	// ========================================

	/**
	 * GIVEN file system write permissions are denied
	 * WHEN write() attempts to write the cache file
	 * THEN it returns false to indicate failure
	 */
	public function test_write_returns_false_on_write_failure(): void {
		// Create cache directory and file, then make file read-only
		$cache_dir = $this->temp_dir . '/cache';
		mkdir( $cache_dir );

		$cache_file = $cache_dir . '/wpdi-container.php';
		file_put_contents( $cache_file, '<?php // placeholder' );
		chmod( $cache_file, 0444 ); // Make file read-only

		$compiler  = new Compiler( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $compiler->write( $class_map );

		// Clean up - restore permissions before deletion
		chmod( $cache_file, 0644 );

		$this->assertFalse( $result, 'Write should return false when file_put_contents fails' );
	}

	/**
	 * GIVEN a directory that doesn't exist and can't be created
	 * WHEN write() is called
	 * THEN it returns false without throwing errors
	 */
	public function test_write_returns_false_when_directory_not_writable(): void {
		// Create a read-only base directory
		$readonly_base = $this->temp_dir . '/readonly_base';
		mkdir( $readonly_base );
		chmod( $readonly_base, 0555 ); // Read + execute only

		$compiler  = new Compiler( $readonly_base );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $compiler->write( $class_map );

		// Clean up - restore permissions
		chmod( $readonly_base, 0755 );

		$this->assertFalse( $result );
	}
}
