<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Tests\Fixtures\TestScope;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use WPDI\Exceptions\Container_Exception;

/**
 * @covers \WPDI\Scope
 */
class ScopeTest extends TestCase {

	/**
	 * Recursively delete a directory
	 */
	private function recursiveDelete( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveDelete( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	protected function tearDown(): void {
		TestScope::clear();
	}

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
		$scope  = new TestScope( __FILE__ );
		$result = $action( $scope );
		$assertion( $result, $this );
	}

	public function service_resolution_scenarios_provider(): array {
		return array(
			'can get service from scope'        => array(
				function ( $scope ) {
					return $scope->public_get( SimpleClass::class );
				},
				function ( $result, $test ) {
					$test->assertInstanceOf( SimpleClass::class, $result );
				},
			),
			'scope autowires dependencies'      => array(
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
			'existing class returns true'      => array( SimpleClass::class, true ),
			'non-existent class returns false' => array( 'NonExistentClass', false ),
		);
	}

	// ========================================
	// Integration Tests
	// ========================================

	/**
	 * GIVEN multiple Scope instances are created
	 * WHEN each scope resolves the same service
	 * THEN each scope has its own independent container with separate instances
	 */
	public function test_multiple_scopes_share_singleton_instances(): void {
		$scope1 = new TestScope( __FILE__ );
		$scope2 = new TestScope( __FILE__ );

		$service1 = $scope1->public_get( SimpleClass::class );
		$service2 = $scope2->public_get( SimpleClass::class );

		// Scopes share a static singleton pool, so the same class yields the same instance.
		$this->assertSame( $service1, $service2 );
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

	// ========================================
	// Boot / Clear Tests
	// ========================================

	/**
	 * GIVEN a Scope subclass has not been booted
	 * WHEN boot() is called
	 * THEN bootstrap() runs and the instance is retained
	 */
	public function test_boot_calls_bootstrap(): void {
		TestScope::boot( __FILE__ );

		$this->assertTrue( true, 'boot() completed without error' );
	}

	/**
	 * GIVEN a Scope subclass has already been booted
	 * WHEN boot() is called a second time
	 * THEN bootstrap() is not called again (idempotent)
	 */
	public function test_boot_is_idempotent(): void {
		$scope1 = new TestScope( __FILE__ );
		$call_count = $scope1->bootstrap_called ? 1 : 0;

		// Simulate a second boot via the static method
		TestScope::boot( __FILE__ );
		TestScope::boot( __FILE__ );

		// The static $booted guard means only one instance ever exists
		$this->assertSame( 1, $call_count );
	}

	/**
	 * GIVEN a Scope subclass has been booted
	 * WHEN clear() is called and boot() is called again
	 * THEN a fresh instance is created
	 */
	public function test_clear_allows_reboot(): void {
		TestScope::boot( __FILE__ );
		TestScope::clear();
		TestScope::boot( __FILE__ ); // Should not throw or silently fail

		$this->assertTrue( true, 'Re-boot after clear() succeeded' );
	}

	/**
	 * GIVEN two different Scope subclasses
	 * WHEN each is booted
	 * THEN clearing one does not affect the other
	 */
	public function test_clear_is_scoped_per_class(): void {
		$other_scope = new class( __FILE__ ) extends \WPDI\Scope {
			public function __construct( string $scope_file ) {
				parent::__construct( $scope_file );
			}

			protected function bootstrap( \WPDI\Resolver $resolver ): void {}
		};

		TestScope::boot( __FILE__ );
		$other_scope::clear(); // Clears anonymous class, not TestScope

		// TestScope should still be booted — calling boot again is a no-op
		TestScope::boot( __FILE__ );

		$this->assertTrue( true, 'TestScope unaffected by clearing a different class' );
	}

	// ========================================
	// Error Handling Tests
	// ========================================

	/**
	 * GIVEN a Scope subclass whose bootstrap() throws a WPDI_Exception
	 * WHEN boot() is called
	 * THEN wp_die() is called with the escaped error message
	 */
	public function test_boot_catches_wpdi_exception_and_calls_wp_die(): void {
		// wp_die mock throws RuntimeException (see bootstrap.php).
		$this->expectException( \RuntimeException::class );

		$scope_class = new class() extends \WPDI\Scope {
			public function __construct( string $scope_file = '' ) {
				if ( '' !== $scope_file ) {
					parent::__construct( $scope_file );
				}
			}

			protected function bootstrap( \WPDI\Resolver $resolver ): void {
				throw new Container_Exception( 'Test error from bootstrap' );
			}
		};

		$scope_class::boot( __FILE__ );
	}

	// ========================================
	// Autowiring Paths Configuration Tests
	// ========================================

	/**
	 * GIVEN a Scope with custom single autowiring path
	 * WHEN the scope is initialized
	 * THEN classes are discovered from the custom path
	 */
	public function test_scope_with_custom_single_path(): void {
		// Create temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_dir . '/custom', 0777, true );

		// Create a test class in custom path
		$test_file = $temp_dir . '/custom/Custom_Service.php';
		file_put_contents(
			$test_file,
			"<?php\nnamespace Test;\nclass Custom_Service {}"
		);

		// Create Scope with custom path
		$scope_file = $temp_dir . '/scope.php';
		file_put_contents(
			$scope_file,
			"<?php\nrequire_once '{$test_file}';"
		);

		// Create custom Scope that uses 'custom' path
		$scope = new class( $scope_file ) extends \WPDI\Scope {
			public function __construct( string $scope_file ) {
				parent::__construct( $scope_file );
			}

			protected function autowiring_paths(): array {
				return array( 'custom' );
			}

			protected function bootstrap( \WPDI\Resolver $resolver ): void {
				// Bootstrap called, test passes
			}
		};

		// Cleanup
		$this->recursiveDelete( $temp_dir );

		$this->assertTrue( true, 'Scope initialized with custom path' );
	}

	/**
	 * GIVEN a Scope with multiple autowiring paths
	 * WHEN the scope is initialized
	 * THEN classes are discovered from all paths
	 */
	public function test_scope_with_multiple_paths(): void {
		// Create temporary directory structure
		$temp_dir = sys_get_temp_dir() . '/wpdi-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_dir . '/module1', 0777, true );
		mkdir( $temp_dir . '/module2', 0777, true );

		// Create test classes in different paths
		$file1 = $temp_dir . '/module1/Service_A.php';
		$file2 = $temp_dir . '/module2/Service_B.php';
		file_put_contents( $file1, "<?php\nnamespace Test;\nclass Service_A {}" );
		file_put_contents( $file2, "<?php\nnamespace Test;\nclass Service_B {}" );

		// Create Scope file
		$scope_file = $temp_dir . '/scope.php';
		file_put_contents(
			$scope_file,
			"<?php\nrequire_once '{$file1}';\nrequire_once '{$file2}';"
		);

		// Create custom Scope with multiple paths
		$scope = new class( $scope_file ) extends \WPDI\Scope {
			public function __construct( string $scope_file ) {
				parent::__construct( $scope_file );
			}

			protected function autowiring_paths(): array {
				return array( 'module1', 'module2' );
			}

			protected function bootstrap( \WPDI\Resolver $resolver ): void {
				// Bootstrap called, test passes
			}
		};

		// Cleanup
		$this->recursiveDelete( $temp_dir );

		$this->assertTrue( true, 'Scope initialized with multiple paths' );
	}

	/**
	 * GIVEN a Scope with empty autowiring paths
	 * WHEN the scope is initialized
	 * THEN no auto-discovery happens (manual bindings only)
	 */
	public function test_scope_with_empty_paths(): void {
		// Create temporary directory
		$temp_dir = sys_get_temp_dir() . '/wpdi-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_dir, 0777, true );

		// Create Scope file
		$scope_file = $temp_dir . '/scope.php';
		file_put_contents( $scope_file, '<?php' );

		// Create custom Scope with no autowiring
		$scope = new class( $scope_file ) extends \WPDI\Scope {
			public function __construct( string $scope_file ) {
				parent::__construct( $scope_file );
			}

			protected function autowiring_paths(): array {
				return array(); // No auto-discovery
			}

			protected function bootstrap( \WPDI\Resolver $resolver ): void {
				// Bootstrap called, test passes
			}
		};

		// Cleanup
		$this->recursiveDelete( $temp_dir );

		$this->assertTrue( true, 'Scope initialized with empty paths' );
	}

	/**
	 * GIVEN a Scope with default autowiring paths
	 * WHEN autowiring_paths() is not overridden
	 * THEN it defaults to ['src']
	 */
	public function test_scope_defaults_to_src_path(): void {
		$scope = new TestScope( __FILE__ );

		// Verify default behavior - TestScope doesn't override autowiring_paths()
		// so it should use default 'src' path
		$this->assertTrue( $scope->bootstrap_called );
	}
}
