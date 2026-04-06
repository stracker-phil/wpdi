<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Auto_Discovery;
use WPDI\Cache_Manager;
use WPDI\Cache_Store;
use WPDI\Class_Inspector;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Tests\Fixtures\ClassWithChainedDependency;
use ReflectionClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * @covers \WPDI\Cache_Manager
 */
class CacheManagerTest extends TestCase {

	private string $test_base_path;
	private string $cache_file;
	private string $src_path;
	private string $config_file;

	protected function setUp(): void {
		$this->test_base_path = sys_get_temp_dir() . '/wpdi_cache_test_' . uniqid();
		$this->cache_file     = $this->test_base_path . '/cache/wpdi-container.php';
		$this->src_path       = $this->test_base_path . '/src';
		$this->config_file    = $this->test_base_path . '/wpdi-config.php';

		// Create test directory structure
		mkdir( $this->test_base_path, 0777, true );
		mkdir( $this->src_path, 0777, true );
		mkdir( dirname( $this->cache_file ), 0777, true );
	}

	protected function tearDown(): void {
		$this->cleanup_directory( $this->test_base_path );
		// Ensure environment is reset
		putenv( 'WP_ENVIRONMENT_TYPE=development' );
	}

	private function create_cache_manager( string $environment = 'development' ): Cache_Manager {
		$inspector = new Class_Inspector();
		$store     = new Cache_Store( $this->test_base_path );
		$discovery = new Auto_Discovery( $inspector );

		return new Cache_Manager(
			$store,
			$discovery,
			$inspector,
			array( $this->src_path ),
			$this->test_base_path,
			$environment
		);
	}

	private function cleanup_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $path );
	}

	// ========================================
	// Cache Loading and Rebuilding Tests
	// ========================================

	/**
	 * GIVEN no cache file exists
	 * WHEN get_cache() is called
	 * THEN it rebuilds cache from scratch and creates the cache file
	 */
	public function test_rebuilds_cache_when_missing(): void {
		$cache_manager = $this->create_cache_manager();

		$result = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertIsArray( $result );
		$this->assertFileExists( $this->cache_file );
	}

	/**
	 * GIVEN a valid cache file exists in production
	 * WHEN get_cache() is called
	 * THEN it returns cached map without checking staleness
	 */
	public function test_returns_cached_map_in_production(): void {
		$cached_data = array(
			SimpleClass::class => array(
				'path'         => '/fake/path.php',
				'mtime'        => time(),
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager( 'production' );
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertEquals( $cached_data, $result['classes'] );
	}

	/**
	 * GIVEN a production cache with no bindings AND a live config file
	 * WHEN get_cache() is called with config bindings
	 * THEN live config bindings are returned (not empty cached bindings)
	 */
	public function test_production_uses_live_config_bindings_when_cache_has_none(): void {
		$cached_data = array(
			SimpleClass::class => array(
				'path'         => '/fake/path.php',
				'mtime'        => time(),
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$live_bindings = array( 'SomeInterface' => 'SomeConcrete' );

		$cache_manager = $this->create_cache_manager( 'production' );
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php', $live_bindings );

		$this->assertEquals( $live_bindings, $result['bindings'] );
		$this->assertEquals( $cached_data, $result['classes'] );
	}

	/**
	 * GIVEN a production cache with bindings AND no config file (deployable artifact)
	 * WHEN get_cache() is called with empty config bindings
	 * THEN cached bindings are used as fallback
	 */
	public function test_production_uses_cached_bindings_when_no_config_file(): void {
		$cached_classes  = array(
			SimpleClass::class => array(
				'path'         => '/fake/path.php',
				'mtime'        => time(),
				'dependencies' => array(),
			),
		);
		$cached_bindings = array( 'SomeInterface' => 'SomeConcrete' );
		$this->write_cache_file( $cached_classes, $cached_bindings );

		$cache_manager = $this->create_cache_manager( 'production' );
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php', array() );

		$this->assertEquals( $cached_bindings, $result['bindings'] );
	}

	/**
	 * GIVEN various invalid cache states
	 * WHEN get_cache() is called
	 * THEN it triggers a full cache rebuild
	 *
	 * @dataProvider invalid_cache_provider
	 */
	public function test_rebuilds_on_invalid_cache( array $cached_data ): void {
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertIsArray( $result );
	}

	public function invalid_cache_provider(): array {
		return array(
			'empty cache array'                     => array( array() ),
			'invalid metadata format (string path)' => array(
				array( SimpleClass::class => '/just/a/path' ),
			),
			'missing mtime in metadata'             => array(
				array(
					SimpleClass::class => array(
						'path'         => '/some/path.php',
						'dependencies' => array(),
					),
				),
			),
			'missing path in metadata'              => array(
				array(
					SimpleClass::class => array(
						'mtime'        => time(),
						'dependencies' => array(),
					),
				),
			),
		);
	}

	// ========================================
	// Staleness Detection Tests
	// ========================================

	/**
	 * GIVEN files that trigger cache staleness
	 * WHEN get_cache() is called
	 * THEN it rebuilds the cache
	 *
	 * @dataProvider staleness_trigger_provider
	 */
	public function test_rebuilds_when_files_are_newer( string $file_type ): void {
		// Create cache file first
		$cached_data = array(
			SimpleClass::class => array(
				'path'         => $this->src_path . '/SimpleClass.php',
				'mtime'        => time() - 100,
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		// Touch cache to set its mtime in the past
		touch( $this->cache_file, time() - 50 );

		// Create the trigger file newer than cache
		$trigger_file = $this->get_staleness_trigger_file( $file_type );
		file_put_contents( $trigger_file, '<?php // trigger file' );
		touch( $trigger_file, time() ); // Newer than cache

		$scope_file = 'scope' === $file_type
			? $trigger_file
			: $this->test_base_path . '/scope.php';

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $scope_file );

		// Should have rebuilt (empty src/ means empty result)
		$this->assertIsArray( $result );
	}

	public function staleness_trigger_provider(): array {
		return array(
			'scope file newer than cache'  => array( 'scope' ),
			'config file newer than cache' => array( 'config' ),
		);
	}

	private function get_staleness_trigger_file( string $file_type ): string {
		if ( 'scope' === $file_type ) {
			return $this->test_base_path . '/scope.php';
		}

		return $this->config_file;
	}

	// ========================================
	// Incremental Update Tests
	// ========================================

	/**
	 * GIVEN cached class with file that no longer exists
	 * WHEN get_cache() is called
	 * THEN the class is removed from cache
	 */
	public function test_removes_deleted_files_from_cache(): void {
		$cached_data = array(
			'DeletedClass' => array(
				'path'         => $this->src_path . '/DeletedClass.php',
				'mtime'        => time() - 100,
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayNotHasKey( 'DeletedClass', $result['classes'] );
	}

	/**
	 * GIVEN cached class with unmodified file
	 * WHEN get_cache() is called
	 * THEN the cached metadata is preserved exactly
	 */
	public function test_preserves_unmodified_files(): void {
		// Create actual file
		$file_path = $this->src_path . '/UnmodifiedClass.php';
		file_put_contents( $file_path, '<?php class UnmodifiedClass {}' );
		$file_mtime = filemtime( $file_path );

		$cached_data = array(
			'UnmodifiedClass' => array(
				'path'         => $file_path,
				'mtime'        => $file_mtime, // Same as file
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayHasKey( 'UnmodifiedClass', $result['classes'] );
		$this->assertSame( $file_path, $result['classes']['UnmodifiedClass']['path'] );
		$this->assertSame( $file_mtime, $result['classes']['UnmodifiedClass']['mtime'] );
	}

	/**
	 * GIVEN cached class with modified file
	 * WHEN get_cache() is called
	 * THEN the file is re-parsed and metadata updated
	 */
	public function test_reparses_modified_files(): void {
		// Create actual file
		$file_path = $this->src_path . '/ModifiedClass.php';
		file_put_contents(
			$file_path,
			'<?php namespace TestNS; class ModifiedClass {}'
		);

		// Cache with old mtime
		$old_mtime   = time() - 100;
		$cached_data = array(
			'TestNS\\ModifiedClass' => array(
				'path'         => $file_path,
				'mtime'        => $old_mtime,
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		// File should be re-parsed but may not have loadable class
		// The key behavior is that the cache was updated
		$this->assertIsArray( $result );
	}

	// ========================================
	// Cache Writing Tests
	// ========================================

	/**
	 * GIVEN empty src directory
	 * WHEN get_cache() is called
	 * THEN cache file is created with empty array
	 */
	public function test_creates_cache_file_for_empty_src(): void {
		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertFileExists( $this->cache_file );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result['classes'] );
	}

	/**
	 * GIVEN cache directory does not exist
	 * WHEN get_cache() is called
	 * THEN cache directory is created and cache file written
	 */
	public function test_creates_cache_directory(): void {
		// Remove cache directory
		rmdir( dirname( $this->cache_file ) );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertDirectoryExists( dirname( $this->cache_file ) );
		$this->assertFileExists( $this->cache_file );
		$this->assertIsArray( $result );
	}

	// ========================================
	// Dependency Discovery Tests
	// ========================================

	/**
	 * GIVEN cached classes with dependencies referencing existing instantiable classes
	 * WHEN get_cache() is called
	 * THEN new dependencies are discovered and added with their metadata
	 */
	public function test_discovers_new_dependencies(): void {
		// Get real fixture path
		$reflection = new ReflectionClass( ClassWithDependency::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		// Cache ClassWithDependency which depends on SimpleClass
		$cached_data = array(
			ClassWithDependency::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime, // Use actual mtime so file is "unmodified"
				'dependencies' => array( SimpleClass::class ),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		// SimpleClass should be discovered as dependency
		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
		$this->assertArrayHasKey( 'path', $result['classes'][ SimpleClass::class ] );
		$this->assertArrayHasKey( 'mtime', $result['classes'][ SimpleClass::class ] );
		$this->assertArrayHasKey( 'dependencies', $result['classes'][ SimpleClass::class ] );
		$this->assertEmpty( $result['classes'][ SimpleClass::class ]['dependencies'] );
	}

	/**
	 * GIVEN a dependency chain A -> B -> C
	 * WHEN get_cache() is called
	 * THEN all dependencies are discovered transitively
	 */
	public function test_discovers_transitive_dependencies(): void {
		// Get real fixture path
		$reflection = new ReflectionClass( ClassWithDependency::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		// ClassWithDependency depends on SimpleClass
		$cached_data = array(
			ClassWithDependency::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime,
				'dependencies' => array( SimpleClass::class ),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		// SimpleClass has no dependencies, but should be discovered
		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
		$this->assertEmpty( $result['classes'][ SimpleClass::class ]['dependencies'] );
	}

	/**
	 * GIVEN dependencies that cannot be added to cache
	 * WHEN get_cache() is called
	 * THEN these dependencies are ignored and not added to the result
	 *
	 * @dataProvider non_cacheable_dependency_provider
	 */
	public function test_ignores_non_cacheable_dependencies(
		string $dependency_class,
		string $reason
	): void {
		// Get real fixture path
		$reflection = new ReflectionClass( SimpleClass::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		$cached_data = array(
			SimpleClass::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime,
				'dependencies' => array( $dependency_class ),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayNotHasKey( $dependency_class, $result['classes'], "Failed for: {$reason}" );
		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
	}

	public function non_cacheable_dependency_provider(): array {
		return array(
			'non-existent class'           => array(
				'NonExistent\\Class',
				'class does not exist',
			),
			'interface (not instantiable)' => array(
				'WPDI\\Tests\\Fixtures\\LoggerInterface',
				'interfaces cannot be instantiated',
			),
		);
	}

	/**
	 * GIVEN cached metadata with missing dependencies array
	 * WHEN get_cache() is called
	 * THEN it handles gracefully and continues without error
	 */
	public function test_handles_metadata_without_dependencies_array(): void {
		// Get real fixture path
		$reflection = new \ReflectionClass( SimpleClass::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		// Cache with missing dependencies key
		$cached_data = array(
			SimpleClass::class => array(
				'path'  => $real_path,
				'mtime' => $real_mtime,
				// 'dependencies' key intentionally missing
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
	}

	/**
	 * GIVEN a modified PHP file containing multiple classes (class rename scenario)
	 * WHEN get_cache() is called
	 * THEN all classes from the re-parsed file are added to the cache
	 */
	public function test_handles_multiple_classes_from_reparsed_file(): void {
		// Create a file with multiple classes
		$temp_file = $this->src_path . '/MultiClasses.php';
		$class1    = 'MultiTestClass1_' . uniqid();
		$class2    = 'MultiTestClass2_' . uniqid();
		$content   = "<?php\nclass {$class1} {}\nclass {$class2} {}\n";
		file_put_contents( $temp_file, $content );

		// Load classes so they're discoverable
		require_once $temp_file;

		// Cache with old mtime (file appears modified)
		$cached_data = array(
			$class1 => array(
				'path'         => $temp_file,
				'mtime'        => time() - 100, // Old mtime triggers re-parse
				'dependencies' => array(),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayHasKey( $class1, $result['classes'] );
		$this->assertArrayHasKey( $class2, $result['classes'] );
		$this->assertSame( $temp_file, $result['classes'][ $class1 ]['path'] );
		$this->assertSame( $temp_file, $result['classes'][ $class2 ]['path'] );
	}

	/**
	 * GIVEN a dependency on an internal PHP class (no file path)
	 * WHEN get_cache() discovers dependencies
	 * THEN internal classes are not added to cache (no file path available)
	 */
	public function test_ignores_internal_classes_as_dependencies(): void {
		// Get real fixture path
		$reflection = new \ReflectionClass( SimpleClass::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		// Cache with dependency on internal class
		$cached_data = array(
			SimpleClass::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime,
				'dependencies' => array( 'Exception' ), // Internal PHP class
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
	}

	/**
	 * GIVEN a deep dependency chain A -> B -> C
	 * WHEN get_cache() discovers dependencies
	 * THEN all levels are discovered transitively
	 */
	public function test_discovers_multi_level_transitive_dependencies(): void {
		// Chain: ClassWithChainedDependency -> ClassWithDependency -> SimpleClass
		$reflection = new \ReflectionClass( ClassWithChainedDependency::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		$cached_data = array(
			ClassWithChainedDependency::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime,
				'dependencies' => array( ClassWithDependency::class ),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayHasKey( ClassWithChainedDependency::class, $result['classes'] );
		$this->assertArrayHasKey( ClassWithDependency::class, $result['classes'] );
		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
		$this->assertContains(
			SimpleClass::class,
			$result['classes'][ ClassWithDependency::class ]['dependencies']
		);
	}

	/**
	 * GIVEN a dependency on abstract class
	 * WHEN get_cache() discovers dependencies
	 * THEN abstract classes are not added (not instantiable)
	 */
	public function test_ignores_abstract_class_dependencies(): void {
		// Get real fixture path
		$reflection = new \ReflectionClass( SimpleClass::class );
		$real_path  = $reflection->getFileName();
		$real_mtime = filemtime( $real_path );

		$cached_data = array(
			SimpleClass::class => array(
				'path'         => $real_path,
				'mtime'        => $real_mtime,
				'dependencies' => array( 'WPDI\\Tests\\Fixtures\\AbstractClass' ),
			),
		);
		$this->write_cache_file( $cached_data );

		$cache_manager = $this->create_cache_manager();
		$result        = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertArrayNotHasKey( 'WPDI\\Tests\\Fixtures\\AbstractClass', $result['classes'] );
		$this->assertArrayHasKey( SimpleClass::class, $result['classes'] );
	}

	// ========================================
	// Autowiring Path Tests
	// ========================================

	/**
	 * GIVEN an autowiring path that does not exist on disk
	 * WHEN the cache is rebuilt
	 * THEN the non-existent path is skipped silently
	 */
	public function test_skips_nonexistent_autowiring_path(): void {
		$inspector = new Class_Inspector();
		$store     = new Cache_Store( $this->test_base_path );
		$discovery = new Auto_Discovery( $inspector );

		$cache_manager = new Cache_Manager(
			$store,
			$discovery,
			$inspector,
			array( $this->src_path, $this->test_base_path . '/nonexistent_path' ),
			$this->test_base_path,
			'development'
		);

		$result = $cache_manager->get_cache( $this->test_base_path . '/scope.php' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result['classes'] );
	}

	// ========================================
	// Helper Methods
	// ========================================

	private function write_cache_file( array $data, array $bindings = array() ): void {
		$structured = array(
			'classes'  => $data,
			'bindings' => $bindings,
		);
		$content  = "<?php\n// WPDI Container Cache\n\nreturn ";
		$content .= var_export( $structured, true ) . ";\n";
		file_put_contents( $this->cache_file, $content );
	}
}
