<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Tests\Fixtures\TestScope;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;

class ScopeTest extends TestCase {

	// ========================================
	// Initialization Tests
	// ========================================

	public function test_scope_calls_bootstrap_on_construction(): void {
		$scope = new TestScope();

		$this->assertTrue( $scope->bootstrap_called );
	}

	public function test_scope_initializes_container(): void {
		$scope = new TestScope();

		// Container should be initialized and able to resolve services
		$this->assertNotNull( $scope->resolved_service );
		$this->assertInstanceOf( SimpleClass::class, $scope->resolved_service );
	}

	// ========================================
	// Service Resolution Tests
	// ========================================

	public function test_can_get_service_from_scope(): void {
		$scope = new TestScope();

		$service = $scope->public_get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $service );
	}

	public function test_can_check_if_service_exists(): void {
		$scope = new TestScope();

		$this->assertTrue( $scope->public_has( SimpleClass::class ) );
		$this->assertFalse( $scope->public_has( 'NonExistentClass' ) );
	}

	public function test_scope_autowires_dependencies(): void {
		$scope = new TestScope();

		$service = $scope->public_get( ClassWithDependency::class );

		$this->assertInstanceOf( ClassWithDependency::class, $service );
		$this->assertInstanceOf( SimpleClass::class, $service->get_dependency() );
	}

	public function test_scope_returns_singleton_instances(): void {
		$scope = new TestScope();

		$instance1 = $scope->public_get( SimpleClass::class );
		$instance2 = $scope->public_get( SimpleClass::class );

		$this->assertSame( $instance1, $instance2 );
	}

	// ========================================
	// Base Path Tests
	// ========================================

	public function test_get_base_path_returns_directory_of_scope_class(): void {
		$scope = new TestScope();

		$base_path = $scope->public_get_base_path();

		$this->assertIsString( $base_path );
		$this->assertStringContainsString( 'tests/Fixtures', $base_path );
	}

	public function test_base_path_is_used_for_auto_discovery(): void {
		// Create a temporary scope in a known location
		$scope     = new TestScope();
		$base_path = $scope->public_get_base_path();

		// Verify base path points to the Fixtures directory
		$this->assertDirectoryExists( $base_path );
	}

	// ========================================
	// Integration Tests
	// ========================================

	public function test_multiple_scopes_have_independent_containers(): void {
		$scope1 = new TestScope();
		$scope2 = new TestScope();

		$service1 = $scope1->public_get( SimpleClass::class );
		$service2 = $scope2->public_get( SimpleClass::class );

		// Each scope should have its own container, so instances should differ
		$this->assertNotSame( $service1, $service2 );
	}

	public function test_scope_loads_config_if_available(): void {
		// This is tested indirectly - if wpdi-config.php exists in the base path,
		// it would be loaded during initialization
		// We verify the container is initialized correctly
		$scope = new TestScope();

		$this->assertTrue( $scope->bootstrap_called );
		$this->assertTrue( $scope->public_has( SimpleClass::class ) );
	}
}
