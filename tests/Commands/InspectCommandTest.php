<?php
/**
 * Tests for Inspect_Command
 */

use PHPUnit\Framework\TestCase;
use WPDI\Commands\Inspect_Command;

/**
 * Test Inspect_Command behavior
 */
class InspectCommandTest extends TestCase {
	/**
	 * Temporary directory for tests
	 */
	private string $temp_dir;

	/**
	 * Setup test environment
	 */
	protected function setUp(): void {
		parent::setUp();
		WP_CLI::reset_calls();

		$this->temp_dir = sys_get_temp_dir() . '/wpdi-inspect-test-' . uniqid();
		mkdir( $this->temp_dir );
		mkdir( $this->temp_dir . '/src' );
	}

	/**
	 * Cleanup test environment
	 */
	protected function tearDown(): void {
		parent::tearDown();

		if ( file_exists( $this->temp_dir ) ) {
			$this->recursiveDelete( $this->temp_dir );
		}
	}

	/**
	 * Get all WP_CLI calls of specific method
	 */
	private function getWpCliCalls( string $method ): array {
		$filtered = array_filter(
			WP_CLI::get_calls(),
			function ( $call ) use ( $method ) {
				return $call['method'] === $method;
			}
		);

		return array_values( $filtered );
	}

	/**
	 * Get all log messages as a single string
	 */
	private function getLogOutput(): string {
		$logs = $this->getWpCliCalls( 'log' );

		return implode( "\n", array_map( function ( $call ) {
			return $call['args'][0];
		}, $logs ) );
	}

	/**
	 * Get format_items call
	 */
	private function getFormatItemsCall(): ?array {
		foreach ( WP_CLI::get_calls() as $call ) {
			if ( 'format_items' === $call['method'] ) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * GIVEN a class that does not exist
	 * WHEN inspecting
	 * THEN should show error
	 */
	public function test_shows_error_for_nonexistent_class(): void {
		$command = new Inspect_Command();

		$this->expectException( 'WP_CLI_Exception' );

		$command->__invoke(
			array( 'Nonexistent\\Class\\Name' ),
			array( 'path' => $this->temp_dir )
		);
	}

	/**
	 * GIVEN a concrete class with no dependencies
	 * WHEN inspecting
	 * THEN should show class info and no dependencies
	 */
	public function test_inspects_simple_concrete_class(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\SimpleClass', $output );
		$this->assertStringContainsString( 'Type:         concrete', $output );
		$this->assertStringContainsString( 'Autowirable:  yes', $output );
		$this->assertStringContainsString( 'no parameters', $output );
		$this->assertStringContainsString( '(no dependencies)', $output );
	}

	/**
	 * GIVEN a concrete class with a dependency
	 * WHEN inspecting
	 * THEN should show constructor dependencies table
	 */
	public function test_inspects_class_with_dependency(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithDependency' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Constructor Dependencies:', $output );

		// Verify format_items was called with dependency info
		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call );

		$items = $format_call['args'][1];
		$this->assertCount( 1, $items );
		$this->assertEquals( '$dependency', $items[0]['parameter'] );
		$this->assertEquals( 'concrete', $items[0]['type'] );
		$this->assertEquals( 'autowiring', $items[0]['resolution'] );
	}

	/**
	 * GIVEN a class with scalar default values
	 * WHEN inspecting
	 * THEN should show scalar params with default values
	 */
	public function test_inspects_class_with_scalar_defaults(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithDefaultValue' ),
			array( 'path' => $this->temp_dir )
		);

		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call );

		$items = $format_call['args'][1];
		$this->assertCount( 2, $items );

		// First param: string $name = 'default'
		$this->assertEquals( '$name', $items[0]['parameter'] );
		$this->assertEquals( 'scalar', $items[0]['type'] );
		$this->assertEquals( 'value', $items[0]['resolution'] );
		$this->assertStringContainsString( 'default', $items[0]['detail'] );

		// Second param: int $count = 10
		$this->assertEquals( '$count', $items[1]['parameter'] );
		$this->assertEquals( 'scalar', $items[1]['type'] );
	}

	/**
	 * GIVEN a class with a nullable interface parameter
	 * WHEN inspecting
	 * THEN should show nullable info
	 */
	public function test_inspects_class_with_nullable_parameter(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithNullableParameter' ),
			array( 'path' => $this->temp_dir )
		);

		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call );

		$items = $format_call['args'][1];
		$this->assertCount( 1, $items );
		$this->assertStringContainsString( 'nullable', $items[0]['detail'] );
	}

	/**
	 * GIVEN an interface without config binding
	 * WHEN inspecting
	 * THEN should warn about missing binding
	 */
	public function test_warns_for_unbound_interface(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\LoggerInterface' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'Type:         interface', $output );
		$this->assertStringContainsString( 'Autowirable:  no', $output );

		$warnings = $this->getWpCliCalls( 'warning' );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'not resolvable', $warnings[0]['args'][0] );
	}

	/**
	 * GIVEN an abstract class
	 * WHEN inspecting
	 * THEN should warn about non-instantiability
	 */
	public function test_warns_for_abstract_class(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\AbstractClass' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'Type:         abstract', $output );

		$warnings = $this->getWpCliCalls( 'warning' );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'not instantiable', $warnings[0]['args'][0] );
	}

	/**
	 * GIVEN classes with circular dependencies
	 * WHEN inspecting
	 * THEN should detect and display circular dependency
	 */
	public function test_detects_circular_dependency_in_tree(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\CircularA' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Dependency Tree:', $output );
		$this->assertStringContainsString( '[CIRCULAR]', $output );
	}

	/**
	 * GIVEN a class with chained dependencies
	 * WHEN inspecting
	 * THEN should show full dependency tree
	 */
	public function test_shows_chained_dependency_tree(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithChainedDependency' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'ClassWithChainedDependency', $output );
		$this->assertStringContainsString( 'ClassWithDependency', $output );
		$this->assertStringContainsString( 'SimpleClass', $output );
	}

	/**
	 * GIVEN a class discovered via autodiscovery
	 * WHEN inspecting with correct path
	 * THEN should show autodiscovery as source
	 */
	public function test_shows_autodiscovery_source(): void {
		$this->createTestClass( 'Inspect_Discovered_Service' );

		$command = new Inspect_Command();

		$command->__invoke(
			array( 'Inspect_Discovered_Service' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'autodiscovery', $output );
	}

	/**
	 * GIVEN a class not in autodiscovery or config
	 * WHEN inspecting
	 * THEN should show external as source
	 */
	public function test_shows_external_source(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'external', $output );
	}

	/**
	 * GIVEN a class with multiple dependencies
	 * WHEN inspecting
	 * THEN should list all constructor parameters
	 */
	public function test_inspects_class_with_multiple_dependencies(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithMultipleDependencies' ),
			array( 'path' => $this->temp_dir )
		);

		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call );

		$items = $format_call['args'][1];
		$this->assertGreaterThanOrEqual( 2, count( $items ) );
	}

	/**
	 * Create a simple concrete test class file and load it
	 */
	private function createTestClass( string $class_name ): void {
		$file_path     = $this->temp_dir . '/src/' . $class_name . '.php';
		$class_content = <<<PHP
<?php
class {$class_name} {
	public function __construct() {}
}
PHP;
		file_put_contents( $file_path, $class_content );

		if ( ! class_exists( $class_name, false ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Recursively delete directory
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
}
