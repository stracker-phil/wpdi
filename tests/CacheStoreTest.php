<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Cache_Store;
use WPDI\Tests\Fixtures\SimpleClass;

/**
 * @covers \WPDI\Cache_Store
 */
class CacheStoreTest extends TestCase {

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
	 * GIVEN a Cache_Store instance with a base path
	 * WHEN get_cache_file() is called
	 * THEN it returns the full cache file path
	 */
	public function test_get_cache_file_returns_full_path(): void {
		$store = new Cache_Store( $this->temp_dir );

		$this->assertEquals( $this->cache_file, $store->get_cache_file() );
	}

	/**
	 * GIVEN a Cache_Store instance with a base path
	 * WHEN exists() is called and the file doesn't exist
	 * THEN it returns false
	 */
	public function test_exists_returns_false_when_file_missing(): void {
		$store = new Cache_Store( $this->temp_dir );

		$this->assertFalse( $store->exists() );
	}

	/**
	 * GIVEN a Cache_Store instance with an existing cache file
	 * WHEN exists() is called
	 * THEN it returns true
	 */
	public function test_exists_returns_true_when_file_present(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$store = new Cache_Store( $this->temp_dir );

		$this->assertTrue( $store->exists() );
	}

	/**
	 * GIVEN a Cache_Store instance with an existing cache file
	 * WHEN get_mtime() is called
	 * THEN it returns the file modification time
	 */
	public function test_get_mtime_returns_file_modification_time(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$expected_mtime = filemtime( $this->cache_file );
		$store       = new Cache_Store( $this->temp_dir );

		$this->assertEquals( $expected_mtime, $store->get_mtime() );
	}

	/**
	 * GIVEN a Cache_Store instance with an existing cache file
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
		$store = new Cache_Store( $this->temp_dir );

		$this->assertEquals( $expected, $store->load()['classes'] );
	}

	/**
	 * GIVEN a Cache_Store instance with an existing cache file
	 * WHEN delete() is called
	 * THEN the cache file is removed
	 */
	public function test_delete_removes_cache_file(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return array();' );
		$store = new Cache_Store( $this->temp_dir );

		$store->delete();

		$this->assertFileDoesNotExist( $this->cache_file );
	}

	/**
	 * GIVEN a Cache_Store instance with a non-existent cache file
	 * WHEN delete() is called
	 * THEN it silently does nothing (no error)
	 */
	public function test_delete_handles_missing_file_gracefully(): void {
		$store = new Cache_Store( $this->temp_dir );

		// Should not throw any errors
		$store->delete();

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
		$store  = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $store->write( $class_map );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->cache_file );
	}

	/**
	 * GIVEN a compiled cache file
	 * WHEN examining its contents
	 * THEN it contains valid PHP code that returns an array
	 */
	public function test_written_file_contains_valid_php(): void {
		$store  = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$store->write( $class_map );

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
		$store  = new Cache_Store( $this->temp_dir );
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

		$store->write( $class_map );
		$compiled = require $this->cache_file;

		$this->assertIsArray( $compiled );
		$this->assertArrayHasKey( 'classes', $compiled );
		$classes = $compiled['classes'];
		$this->assertCount( 2, $classes );
		$this->assertArrayHasKey( SimpleClass::class, $classes );
		$this->assertArrayHasKey( 'WPDI\Tests\Fixtures\ClassWithDependency', $classes );
		$this->assertEquals( $class_map, $classes );
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
		$store = new Cache_Store( $this->temp_dir );

		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $store->write( $class_map );

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
		$store = new Cache_Store( $this->temp_dir );

		$store->write( $class_map );
		$compiled = require $this->cache_file;

		$this->assertCount( $expected_count, $compiled['classes'] );
		$this->assertEquals( $class_map, $compiled['classes'] );
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
		$store  = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$store->write( $class_map );
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
		$store  = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$store->write( $class_map );
		$content = file_get_contents( $this->cache_file );

		// Should contain a date in some format
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}/', $content );
	}

	// ========================================
	// Load Edge Case Tests
	// ========================================

	/**
	 * GIVEN a Cache_Store instance with no cache file
	 * WHEN load() is called directly
	 * THEN it returns empty structure
	 */
	public function test_load_returns_empty_when_file_missing(): void {
		$store = new Cache_Store( $this->temp_dir );

		$result = $store->load();

		$this->assertSame( array(), $result['classes'] );
		$this->assertSame( array(), $result['bindings'] );
	}

	/**
	 * GIVEN a cache file that returns a non-array value
	 * WHEN load() is called
	 * THEN it returns empty structure
	 */
	public function test_load_returns_empty_when_file_returns_non_array(): void {
		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return "not an array";' );
		$store = new Cache_Store( $this->temp_dir );

		$result = $store->load();

		$this->assertSame( array(), $result['classes'] );
		$this->assertSame( array(), $result['bindings'] );
	}

	/**
	 * GIVEN a cache file in the new structured format (with 'classes' key)
	 * WHEN load() is called
	 * THEN it returns the structured data with both classes and bindings
	 */
	public function test_load_returns_structured_data_with_classes_key(): void {
		$classes  = array(
			'TestClass' => array(
				'path'         => '/path/to/TestClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);
		$bindings = array( 'SomeInterface' => 'SomeConcrete' );

		$data = array(
			'classes'  => $classes,
			'bindings' => $bindings,
		);

		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return ' . var_export( $data, true ) . ';' );
		$store = new Cache_Store( $this->temp_dir );

		$result = $store->load();

		$this->assertEquals( $classes, $result['classes'] );
		$this->assertEquals( $bindings, $result['bindings'] );
	}

	/**
	 * GIVEN a cache file in new format without 'bindings' key
	 * WHEN load() is called
	 * THEN bindings defaults to empty array
	 */
	public function test_load_defaults_bindings_to_empty_array(): void {
		$classes = array(
			'TestClass' => array(
				'path'         => '/path/to/TestClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$data = array( 'classes' => $classes );

		mkdir( dirname( $this->cache_file ), 0777, true );
		file_put_contents( $this->cache_file, '<?php return ' . var_export( $data, true ) . ';' );
		$store = new Cache_Store( $this->temp_dir );

		$result = $store->load();

		$this->assertEquals( $classes, $result['classes'] );
		$this->assertSame( array(), $result['bindings'] );
	}

	/**
	 * GIVEN a class map and interface bindings
	 * WHEN write() is called with both
	 * THEN the header includes the bindings count
	 */
	public function test_write_includes_bindings_count_in_header(): void {
		$store     = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);
		$bindings = array( 'SomeInterface' => 'SomeConcrete' );

		$store->write( $class_map, $bindings );
		$content = file_get_contents( $this->cache_file );

		$this->assertStringContainsString( '1 interface bindings', $content );
	}

	/**
	 * GIVEN a class map with constructor descriptors
	 * WHEN written and loaded back
	 * THEN constructor data survives the roundtrip
	 */
	public function test_write_roundtrips_constructor_metadata(): void {
		$store     = new Cache_Store( $this->temp_dir );
		$class_map = array(
			'TestClass' => array(
				'path'         => '/path/to/TestClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array( 'DepClass' ),
				'constructor'  => array(
					array(
						'name'        => 'dep',
						'type'        => 'DepClass',
						'builtin'     => false,
						'nullable'    => false,
						'has_default' => false,
						'default'     => null,
					),
					array(
						'name'        => 'name',
						'type'        => 'string',
						'builtin'     => true,
						'nullable'    => false,
						'has_default' => true,
						'default'     => 'hello',
					),
				),
			),
			'NoCtorClass' => array(
				'path'         => '/path/to/NoCtorClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
				'constructor'  => null,
			),
		);

		$store->write( $class_map );
		$compiled = require $this->cache_file;

		$this->assertEquals( $class_map, $compiled['classes'] );
	}

	/**
	 * GIVEN a class map and bindings written to cache
	 * WHEN the cache file is required
	 * THEN both classes and bindings are returned correctly
	 */
	public function test_write_roundtrips_bindings(): void {
		$store     = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);
		$bindings = array( 'SomeInterface' => 'SomeConcrete' );

		$store->write( $class_map, $bindings );
		$compiled = require $this->cache_file;

		$this->assertEquals( $class_map, $compiled['classes'] );
		$this->assertEquals( $bindings, $compiled['bindings'] );
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

		$store  = new Cache_Store( $this->temp_dir );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $store->write( $class_map );

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

		$store  = new Cache_Store( $readonly_base );
		$class_map = array(
			SimpleClass::class => array(
				'path'         => '/path/to/SimpleClass.php',
				'mtime'        => 1234567890,
				'dependencies' => array(),
			),
		);

		$result = $store->write( $class_map );

		// Clean up - restore permissions
		chmod( $readonly_base, 0755 );

		$this->assertFalse( $result );
	}
}
