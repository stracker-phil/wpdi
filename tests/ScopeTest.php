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

	/**
	 * GIVEN a Scope subclass is constructed
	 * WHEN the constructor runs
	 * THEN the bootstrap() method is automatically called
	 */
	public function test_scope_calls_bootstrap_on_construction(): void {
		$scope = new TestScope( __FILE__ );

		$this->assertTrue( $scope->bootstrap_called );
	}

	/**
	 * GIVEN a Scope is constructed
	 * WHEN bootstrap() runs
	 * THEN the container is initialized and can resolve services
	 */
	public function test_scope_initializes_container(): void {
		$scope = new TestScope( __FILE__ );

		// Container should be initialized and able to resolve services
		$this->assertNotNull( $scope->resolved_service );
		$this->assertInstanceOf( SimpleClass::class, $scope->resolved_service );
	}

	// ========================================
	// Service Resolution Tests
	// ========================================

	/**
	 * GIVEN a Scope with an initialized container
	 * WHEN various service operations are performed
	 * THEN services are resolved correctly with expected behavior
	 *
	 * @dataProvider service_resolution_scenarios_provider
	 */
	public function test_scope_service_resolution(
		callable $action,
		callable $assertion
	): void {
		$scope = new TestScope( __FILE__ );
		$result = $action( $scope );
		$assertion( $result, $this );
	}

	public function service_resolution_scenarios_provider(): array {
		return array(
			'can get service from scope' => array(
				function ( $scope ) {
					return $scope->public_get( SimpleClass::class );
				},
				function ( $result, $test ) {
					$test->assertInstanceOf( SimpleClass::class, $result );
				},
			),
			'scope autowires dependencies' => array(
				function ( $scope ) {
					return $scope->public_get( ClassWithDependency::class );
				},
				function ( $result, $test ) {
					$test->assertInstanceOf( ClassWithDependency::class, $result );
					$test->assertInstanceOf( SimpleClass::class, $result->get_dependency() );
				},
			),
			'scope returns singleton instances' => array(
				function ( $scope ) {
					$instance1 = $scope->public_get( SimpleClass::class );
					$instance2 = $scope->public_get( SimpleClass::class );
					return array( $instance1, $instance2 );
				},
				function ( $result, $test ) {
					$test->assertSame( $result[0], $result[1] );
				},
			),
		);
	}

	/**
	 * GIVEN a Scope instance
	 * WHEN has() is called with different service identifiers
	 * THEN it correctly reports service availability
	 *
	 * @dataProvider service_availability_provider
	 */
	public function test_can_check_service_availability(
		string $service_id,
		bool $expected_result
	): void {
		$scope = new TestScope( __FILE__ );

		$this->assertEquals( $expected_result, $scope->public_has( $service_id ) );
	}

	public function service_availability_provider(): array {
		return array(
			'existing class returns true'     => array( SimpleClass::class, true ),
			'non-existent class returns false' => array( 'NonExistentClass', false ),
		);
	}

	// ========================================
	// Base Path Tests
	// ========================================

	/**
	 * GIVEN a Scope instance
	 * WHEN get_base_path() is called
	 * THEN it returns the directory where the Scope class file is located
	 */
	public function test_get_base_path_returns_directory_of_scope_class(): void {
		$scope = new TestScope( __FILE__ );

		$base_path = $scope->public_get_base_path();

		$this->assertIsString( $base_path );
		$this->assertStringContainsString( 'tests/Fixtures', $base_path );
	}

	/**
	 * GIVEN a Scope instance
	 * WHEN the base path is retrieved
	 * THEN it points to an existing directory used for auto-discovery
	 */
	public function test_base_path_is_used_for_auto_discovery(): void {
		// Create a temporary scope in a known location
		$scope     = new TestScope( __FILE__ );
		$base_path = $scope->public_get_base_path();

		// Verify base path points to the Fixtures directory
		$this->assertDirectoryExists( $base_path );
	}

	// ========================================
	// Integration Tests
	// ========================================

	/**
	 * GIVEN multiple Scope instances are created
	 * WHEN each scope resolves the same service
	 * THEN each scope has its own independent container with separate instances
	 */
	public function test_multiple_scopes_have_independent_containers(): void {
		$scope1 = new TestScope( __FILE__ );
		$scope2 = new TestScope( __FILE__ );

		$service1 = $scope1->public_get( SimpleClass::class );
		$service2 = $scope2->public_get( SimpleClass::class );

		// Each scope should have its own container, so instances should differ
		$this->assertNotSame( $service1, $service2 );
	}

	/**
	 * GIVEN a Scope with potential config file in base path
	 * WHEN the Scope is initialized
	 * THEN configuration is loaded and container works correctly
	 */
	public function test_scope_loads_config_if_available(): void {
		// This is tested indirectly - if wpdi-config.php exists in the base path,
		// it would be loaded during initialization
		// We verify the container is initialized correctly
		$scope = new TestScope( __FILE__ );

		$this->assertTrue( $scope->bootstrap_called );
		$this->assertTrue( $scope->public_has( SimpleClass::class ) );
	}
}
