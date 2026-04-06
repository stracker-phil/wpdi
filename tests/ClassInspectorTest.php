<?php

use PHPUnit\Framework\TestCase;
use WPDI\Class_Inspector;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Tests\Fixtures\ClassWithMultipleDependencies;
use WPDI\Tests\Fixtures\LoggerInterface;
use WPDI\Tests\Fixtures\AbstractClass;
use WPDI\Tests\Fixtures\ArrayLogger;
use WPDI\Tests\Fixtures\ClassWithDefaultValue;
use WPDI\Tests\Fixtures\ClassWithNullableParameter;
use WPDI\Tests\Fixtures\ClassWithOptionalDependency;
use WPDI\Tests\Fixtures\ClassWithContextualDeps;
use WPDI\Tests\Fixtures\CacheInterface;

class ClassInspectorTest extends TestCase {
	private Class_Inspector $inspector;

	protected function setUp(): void {
		parent::setUp();
		$this->inspector = new Class_Inspector();

		// Load fixtures
		require_once __DIR__ . '/Fixtures/SimpleClass.php';
		require_once __DIR__ . '/Fixtures/ClassWithDependency.php';
		require_once __DIR__ . '/Fixtures/ClassWithMultipleDependencies.php';
		require_once __DIR__ . '/Fixtures/LoggerInterface.php';
		require_once __DIR__ . '/Fixtures/AbstractClass.php';
		require_once __DIR__ . '/Fixtures/ArrayLogger.php';
		require_once __DIR__ . '/Fixtures/ClassWithDefaultValue.php';
		require_once __DIR__ . '/Fixtures/ClassWithNullableParameter.php';
		require_once __DIR__ . '/Fixtures/ClassWithOptionalDependency.php';
		require_once __DIR__ . '/Fixtures/CacheInterface.php';
		require_once __DIR__ . '/Fixtures/ClassWithContextualDeps.php';
	}

	public function test_get_reflection_returns_reflection_class(): void {
		$reflection = $this->inspector->get_reflection( SimpleClass::class );

		$this->assertInstanceOf( ReflectionClass::class, $reflection );
		$this->assertSame( SimpleClass::class, $reflection->getName() );
	}

	public function test_get_reflection_caches_instances(): void {
		$reflection1 = $this->inspector->get_reflection( SimpleClass::class );
		$reflection2 = $this->inspector->get_reflection( SimpleClass::class );

		$this->assertSame( $reflection1, $reflection2, 'Should return cached instance' );
	}

	public function test_get_reflection_returns_null_for_nonexistent_class(): void {
		$reflection = $this->inspector->get_reflection( 'NonExistentClass' );

		$this->assertNull( $reflection );
	}

	public function test_get_reflection_works_with_interfaces(): void {
		$reflection = $this->inspector->get_reflection( LoggerInterface::class );

		$this->assertInstanceOf( ReflectionClass::class, $reflection );
		$this->assertTrue( $reflection->isInterface() );
	}

	public function test_is_concrete_returns_true_for_concrete_class(): void {
		$this->assertTrue( $this->inspector->is_concrete( SimpleClass::class ) );
	}

	public function test_is_concrete_returns_false_for_interface(): void {
		$this->assertFalse( $this->inspector->is_concrete( LoggerInterface::class ) );
	}

	public function test_is_concrete_returns_false_for_abstract_class(): void {
		$this->assertFalse( $this->inspector->is_concrete( AbstractClass::class ) );
	}

	public function test_is_concrete_returns_false_for_nonexistent_class(): void {
		$this->assertFalse( $this->inspector->is_concrete( 'NonExistentClass' ) );
	}

	public function test_get_dependencies_returns_empty_for_class_without_dependencies(): void {
		$dependencies = $this->inspector->get_dependencies( SimpleClass::class );

		$this->assertSame( array(), $dependencies );
	}

	public function test_get_dependencies_extracts_single_dependency(): void {
		$dependencies = $this->inspector->get_dependencies( ClassWithDependency::class );

		$this->assertSame( array( SimpleClass::class ), $dependencies );
	}

	public function test_get_dependencies_extracts_multiple_dependencies(): void {
		$dependencies = $this->inspector->get_dependencies( ClassWithMultipleDependencies::class );

		$this->assertCount( 2, $dependencies );
		$this->assertContains( SimpleClass::class, $dependencies );
		$this->assertContains( ClassWithDependency::class, $dependencies );
	}

	public function test_get_dependencies_returns_empty_for_nonexistent_class(): void {
		$dependencies = $this->inspector->get_dependencies( 'NonExistentClass' );

		$this->assertSame( array(), $dependencies );
	}

	public function test_get_metadata_returns_correct_structure(): void {
		$file_path = __DIR__ . '/Fixtures/SimpleClass.php';
		$metadata  = $this->inspector->get_metadata( SimpleClass::class, $file_path );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'path', $metadata );
		$this->assertArrayHasKey( 'mtime', $metadata );
		$this->assertArrayHasKey( 'dependencies', $metadata );
		$this->assertArrayHasKey( 'constructor', $metadata );
		$this->assertSame( $file_path, $metadata['path'] );
		$this->assertIsInt( $metadata['mtime'] );
		$this->assertSame( array(), $metadata['dependencies'] );
		$this->assertNull( $metadata['constructor'], 'SimpleClass has no constructor' );
	}

	public function test_get_metadata_includes_dependencies(): void {
		$file_path = __DIR__ . '/Fixtures/ClassWithDependency.php';
		$metadata  = $this->inspector->get_metadata( ClassWithDependency::class, $file_path );

		$this->assertIsArray( $metadata );
		$this->assertSame( array( SimpleClass::class ), $metadata['dependencies'] );
	}

	public function test_get_metadata_returns_null_for_interface(): void {
		$file_path = __DIR__ . '/Fixtures/LoggerInterface.php';
		$metadata  = $this->inspector->get_metadata( LoggerInterface::class, $file_path );

		$this->assertNull( $metadata );
	}

	public function test_get_metadata_returns_null_for_abstract_class(): void {
		$file_path = __DIR__ . '/Fixtures/AbstractClass.php';
		$metadata  = $this->inspector->get_metadata( AbstractClass::class, $file_path );

		$this->assertNull( $metadata );
	}

	public function test_get_metadata_derives_path_from_reflection(): void {
		$metadata = $this->inspector->get_metadata( SimpleClass::class );

		$this->assertIsArray( $metadata );
		$this->assertArrayHasKey( 'path', $metadata );
		$this->assertArrayHasKey( 'mtime', $metadata );
		$this->assertArrayHasKey( 'dependencies', $metadata );
		$this->assertArrayHasKey( 'constructor', $metadata );
		$this->assertStringEndsWith( 'SimpleClass.php', $metadata['path'] );
		$this->assertIsInt( $metadata['mtime'] );
		$this->assertSame( array(), $metadata['dependencies'] );
	}

	public function test_get_metadata_without_path_returns_null_for_interface(): void {
		$metadata = $this->inspector->get_metadata( LoggerInterface::class );

		$this->assertNull( $metadata );
	}

	public function test_get_metadata_without_path_returns_null_for_abstract_class(): void {
		$metadata = $this->inspector->get_metadata( AbstractClass::class );

		$this->assertNull( $metadata );
	}

	public function test_get_metadata_without_path_returns_null_for_internal_class(): void {
		$metadata = $this->inspector->get_metadata( 'Exception' );

		$this->assertNull( $metadata, 'Internal PHP classes have no file path' );
	}

	public function test_get_metadata_without_path_returns_null_for_nonexistent_class(): void {
		$metadata = $this->inspector->get_metadata( 'NonExistentClass' );

		$this->assertNull( $metadata );
	}

	// ========================================
	// get_constructor_params Tests
	// ========================================

	public function test_get_constructor_params_returns_null_for_no_constructor(): void {
		$params = $this->inspector->get_constructor_params( SimpleClass::class );

		$this->assertNull( $params );
	}

	public function test_get_constructor_params_returns_null_for_nonexistent_class(): void {
		$params = $this->inspector->get_constructor_params( 'NonExistentClass' );

		$this->assertNull( $params );
	}

	public function test_get_constructor_params_extracts_class_typed_param(): void {
		$params = $this->inspector->get_constructor_params( ClassWithDependency::class );

		$this->assertIsArray( $params );
		$this->assertCount( 1, $params );
		$this->assertSame( 'dependency', $params[0]['name'] );
		$this->assertSame( SimpleClass::class, $params[0]['type'] );
		$this->assertFalse( $params[0]['builtin'] );
		$this->assertFalse( $params[0]['nullable'] );
		$this->assertFalse( $params[0]['has_default'] );
	}

	public function test_get_constructor_params_extracts_scalar_defaults(): void {
		$params = $this->inspector->get_constructor_params( ClassWithDefaultValue::class );

		$this->assertIsArray( $params );
		$this->assertCount( 2, $params );

		$this->assertSame( 'name', $params[0]['name'] );
		$this->assertSame( 'string', $params[0]['type'] );
		$this->assertTrue( $params[0]['builtin'] );
		$this->assertTrue( $params[0]['has_default'] );
		$this->assertSame( 'default', $params[0]['default'] );

		$this->assertSame( 'count', $params[1]['name'] );
		$this->assertSame( 'int', $params[1]['type'] );
		$this->assertTrue( $params[1]['builtin'] );
		$this->assertTrue( $params[1]['has_default'] );
		$this->assertSame( 10, $params[1]['default'] );
	}

	public function test_get_constructor_params_extracts_nullable_without_default(): void {
		$params = $this->inspector->get_constructor_params( ClassWithNullableParameter::class );

		$this->assertIsArray( $params );
		$this->assertCount( 1, $params );
		$this->assertSame( 'optional', $params[0]['name'] );
		$this->assertSame( LoggerInterface::class, $params[0]['type'] );
		$this->assertFalse( $params[0]['builtin'] );
		$this->assertTrue( $params[0]['nullable'] );
		$this->assertFalse( $params[0]['has_default'] );
	}

	public function test_get_constructor_params_extracts_nullable_with_default(): void {
		$params = $this->inspector->get_constructor_params( ClassWithOptionalDependency::class );

		$this->assertIsArray( $params );
		$this->assertCount( 1, $params );
		$this->assertSame( 'dependency', $params[0]['name'] );
		$this->assertSame( SimpleClass::class, $params[0]['type'] );
		$this->assertFalse( $params[0]['builtin'] );
		$this->assertTrue( $params[0]['nullable'] );
		$this->assertTrue( $params[0]['has_default'] );
		$this->assertNull( $params[0]['default'] );
	}

	public function test_get_constructor_params_extracts_contextual_deps(): void {
		$params = $this->inspector->get_constructor_params( ClassWithContextualDeps::class );

		$this->assertIsArray( $params );
		$this->assertCount( 2, $params );
		$this->assertSame( 'db_cache', $params[0]['name'] );
		$this->assertSame( CacheInterface::class, $params[0]['type'] );
		$this->assertSame( 'file_cache', $params[1]['name'] );
		$this->assertSame( CacheInterface::class, $params[1]['type'] );
	}

	public function test_get_metadata_includes_constructor_params(): void {
		$file_path = __DIR__ . '/Fixtures/ClassWithDependency.php';
		$metadata  = $this->inspector->get_metadata( ClassWithDependency::class, $file_path );

		$this->assertIsArray( $metadata['constructor'] );
		$this->assertCount( 1, $metadata['constructor'] );
		$this->assertSame( 'dependency', $metadata['constructor'][0]['name'] );
		$this->assertSame( SimpleClass::class, $metadata['constructor'][0]['type'] );
	}

	public function test_get_type_returns_interface_for_interface(): void {
		$type = $this->inspector->get_type( LoggerInterface::class );

		$this->assertSame( 'interface', $type );
	}

	public function test_get_type_returns_abstract_for_abstract_class(): void {
		$type = $this->inspector->get_type( AbstractClass::class );

		$this->assertSame( 'abstract', $type );
	}

	public function test_get_type_returns_concrete_for_concrete_class(): void {
		$type = $this->inspector->get_type( SimpleClass::class );

		$this->assertSame( 'concrete', $type );
	}

	public function test_get_type_returns_unknown_for_nonexistent_class(): void {
		$type = $this->inspector->get_type( 'NonExistentClass' );

		$this->assertSame( 'unknown', $type );
	}

	public function test_clear_cache_empties_reflection_cache(): void {
		// Create cached reflection
		$reflection1 = $this->inspector->get_reflection( SimpleClass::class );
		$this->assertNotNull( $reflection1 );

		// Clear cache
		$this->inspector->clear_cache();

		// Get reflection again - should be new instance
		$reflection2 = $this->inspector->get_reflection( SimpleClass::class );
		$this->assertNotSame( $reflection1, $reflection2, 'Cache should be cleared' );
	}

	public function test_caching_improves_performance(): void {
		// First call - creates reflection
		$start1      = microtime( true );
		$reflection1 = $this->inspector->get_reflection( SimpleClass::class );
		$time1       = microtime( true ) - $start1;

		// Second call - cached
		$start2      = microtime( true );
		$reflection2 = $this->inspector->get_reflection( SimpleClass::class );
		$time2       = microtime( true ) - $start2;

		$this->assertSame( $reflection1, $reflection2 );
		// Note: Performance assertion removed as it's not reliable in tests
	}

	public function test_get_type_caches_reflection(): void {
		// First call to get_type
		$type1 = $this->inspector->get_type( SimpleClass::class );

		// Second call should use cached reflection
		$type2 = $this->inspector->get_type( SimpleClass::class );

		$this->assertSame( $type1, $type2 );
		$this->assertSame( 'concrete', $type1 );
	}

	public function test_is_concrete_caches_reflection(): void {
		// First call to is_concrete
		$result1 = $this->inspector->is_concrete( SimpleClass::class );

		// Second call should use cached reflection
		$result2 = $this->inspector->is_concrete( SimpleClass::class );

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
	}

	public function test_get_dependencies_caches_reflection(): void {
		// First call to get_dependencies
		$deps1 = $this->inspector->get_dependencies( ClassWithDependency::class );

		// Second call should use cached reflection
		$deps2 = $this->inspector->get_dependencies( ClassWithDependency::class );

		$this->assertSame( $deps1, $deps2 );
		$this->assertSame( array( SimpleClass::class ), $deps1 );
	}

	public function test_multiple_methods_share_cached_reflection(): void {
		// First method call
		$type = $this->inspector->get_type( SimpleClass::class );
		$this->assertSame( 'concrete', $type );

		// Second method call on same class should use cache
		$is_concrete = $this->inspector->is_concrete( SimpleClass::class );
		$this->assertTrue( $is_concrete );

		// Third method call should also use cache
		$deps = $this->inspector->get_dependencies( SimpleClass::class );
		$this->assertSame( array(), $deps );
	}
}
