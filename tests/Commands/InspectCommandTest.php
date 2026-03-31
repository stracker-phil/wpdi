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
	 * THEN should show Path header and single-row tree
	 */
	public function test_inspects_simple_concrete_class(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Path:', $output );
		$this->assertStringContainsString( 'SimpleClass', $output );
		$this->assertStringContainsString( 'class', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures', $output );
		// No tree children.
		$this->assertStringNotContainsString( "\xE2\x94\x9C", $output );
		$this->assertStringNotContainsString( "\xE2\x94\x94", $output );
	}

	/**
	 * GIVEN a concrete class with a dependency
	 * WHEN inspecting
	 * THEN should show parameter name and FQCN in aligned columns
	 */
	public function test_inspects_class_with_dependency(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithDependency' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( '$dependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\SimpleClass', $output );
		$this->assertStringContainsString( "\xE2\x94\x94\xE2\x94\x80\xE2\x94\x80", $output );
	}

	/**
	 * GIVEN a class with only scalar parameters
	 * WHEN inspecting
	 * THEN should show single-row tree with no children
	 */
	public function test_inspects_class_with_scalar_defaults(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithDefaultValue' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'ClassWithDefaultValue', $output );
		$this->assertStringNotContainsString( "\xE2\x94\x9C", $output );
		$this->assertStringNotContainsString( "\xE2\x94\x94", $output );
	}

	/**
	 * GIVEN a class with a nullable interface parameter
	 * WHEN inspecting
	 * THEN should show the parameter and interface FQCN in the tree
	 */
	public function test_inspects_class_with_nullable_parameter(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithNullableParameter' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( '$optional', $output );
		$this->assertStringContainsString( 'interface', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\LoggerInterface', $output );
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
		$this->assertStringContainsString( 'interface', $output );

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
		$this->assertStringContainsString( 'abstract', $output );

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

		$this->assertStringContainsString( '[CIRCULAR]', $output );
		$this->assertStringContainsString( '$dependency', $output );
	}

	/**
	 * GIVEN a class with chained dependencies
	 * WHEN inspecting
	 * THEN should show full dependency tree with param names at all levels
	 */
	public function test_shows_chained_dependency_tree(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithChainedDependency' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'ClassWithChainedDependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\ClassWithDependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\SimpleClass', $output );
		// Param names appear at all levels.
		$this->assertStringContainsString( '$dependency', $output );
	}

	/**
	 * GIVEN a class discovered via autodiscovery
	 * WHEN inspecting with correct path
	 * THEN should show relative file path
	 */
	public function test_shows_relative_file_path(): void {
		$this->createTestClass( 'Inspect_Discovered_Service' );

		$command = new Inspect_Command();

		$command->__invoke(
			array( 'Inspect_Discovered_Service' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'Path: src/Inspect_Discovered_Service.php', $output );
	}

	/**
	 * GIVEN a class outside the module path
	 * WHEN inspecting
	 * THEN should show full file path
	 */
	public function test_shows_full_path_for_external_class(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();
		$this->assertStringContainsString( 'Path:', $output );
		$this->assertStringContainsString( 'SimpleClass.php', $output );
	}

	/**
	 * GIVEN a class with multiple dependencies
	 * WHEN inspecting
	 * THEN should show all params with box-drawing characters
	 */
	public function test_inspects_class_with_multiple_dependencies(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithMultipleDependencies' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( '$first', $output );
		$this->assertStringContainsString( '$second', $output );
		$this->assertStringContainsString( "\xE2\x94\x9C\xE2\x94\x80\xE2\x94\x80", $output );
		$this->assertStringContainsString( "\xE2\x94\x94\xE2\x94\x80\xE2\x94\x80", $output );
	}

	/**
	 * GIVEN a class with multiple levels of dependencies
	 * WHEN inspecting with --depth=1
	 * THEN should only show direct dependencies
	 */
	public function test_depth_limits_tree(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithChainedDependency' ),
			array(
				'path'  => $this->temp_dir,
				'depth' => '1',
			)
		);

		$output = $this->getLogOutput();

		// Root + direct dependency shown.
		$this->assertStringContainsString( 'ClassWithChainedDependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\ClassWithDependency', $output );
		// Transitive dependency NOT shown.
		$this->assertStringNotContainsString( 'WPDI\\Tests\\Fixtures\\SimpleClass', $output );
	}

	/**
	 * GIVEN a class with dependencies
	 * WHEN inspecting with --depth=0 (unlimited)
	 * THEN should show the full tree
	 */
	public function test_depth_zero_shows_full_tree(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithChainedDependency' ),
			array(
				'path'  => $this->temp_dir,
				'depth' => '0',
			)
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'ClassWithChainedDependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\ClassWithDependency', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures\\SimpleClass', $output );
	}

	/**
	 * GIVEN a class with dependencies
	 * WHEN inspecting
	 * THEN tree rows should have aligned columns with type information
	 */
	public function test_tree_rows_contain_type_column(): void {
		$command = new Inspect_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\ClassWithNullableParameter' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		// Root row has type.
		$this->assertStringContainsString( 'class', $output );
		// Dependency row has type.
		$this->assertStringContainsString( 'interface', $output );
	}

	/**
	 * GIVEN a short class name that matches one autodiscovered class
	 * WHEN inspecting
	 * THEN should resolve to the FQCN and display the tree
	 */
	public function test_resolves_short_class_name(): void {
		$this->createNamespacedTestClass( 'App\\Services', 'MyService', 'Services' );

		$command = new Inspect_Command();

		$command->__invoke(
			array( 'MyService' ),
			array( 'path' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Path:', $output );
		$this->assertStringContainsString( 'MyService', $output );
		$this->assertStringContainsString( 'App\\Services', $output );
	}

	/**
	 * GIVEN a short class name that matches multiple autodiscovered classes
	 * WHEN inspecting
	 * THEN should list matches and error
	 */
	public function test_errors_on_ambiguous_short_name(): void {
		$this->createNamespacedTestClass( 'App\\Services', 'Ambiguous_Service', 'Services' );
		$this->createNamespacedTestClass( 'App\\Other', 'Ambiguous_Service', 'Other' );

		$command = new Inspect_Command();

		$this->expectException( 'WP_CLI_Exception' );

		$command->__invoke(
			array( 'Ambiguous_Service' ),
			array( 'path' => $this->temp_dir )
		);
	}

	/**
	 * GIVEN a short name that matches no autodiscovered class
	 * WHEN inspecting
	 * THEN should show error
	 */
	public function test_errors_on_unresolvable_short_name(): void {
		$command = new Inspect_Command();

		$this->expectException( 'WP_CLI_Exception' );

		$command->__invoke(
			array( 'NonexistentShortName' ),
			array( 'path' => $this->temp_dir )
		);
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
	 * Create a namespaced test class file in a subdirectory and load it
	 */
	private function createNamespacedTestClass( string $namespace, string $class_name, string $subdir ): void {
		$dir = $this->temp_dir . '/src/' . $subdir;

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$file_path     = $dir . '/' . $class_name . '.php';
		$fqcn          = $namespace . '\\' . $class_name;
		$class_content = <<<PHP
<?php
namespace {$namespace};

class {$class_name} {
	public function __construct() {}
}
PHP;
		file_put_contents( $file_path, $class_content );

		if ( ! class_exists( $fqcn, false ) ) {
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
