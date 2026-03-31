<?php
/**
 * Tests for List_Command
 */

use PHPUnit\Framework\TestCase;
use WPDI\Commands\List_Command;

/**
 * Test List_Command behavior
 */
class ListCommandTest extends TestCase {
	/**
	 * Temporary directory for tests
	 */
	private string $temp_dir;

	/**
	 * Setup test environment
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset WP_CLI mock calls from previous tests
		WP_CLI::reset_calls();

		// Create temporary directory
		$this->temp_dir = sys_get_temp_dir() . '/wpdi-test-' . uniqid();
		mkdir( $this->temp_dir );
		mkdir( $this->temp_dir . '/src' );
	}

	/**
	 * Cleanup test environment
	 */
	protected function tearDown(): void {
		parent::tearDown();

		// Cleanup temporary files
		if ( file_exists( $this->temp_dir ) ) {
			$this->recursiveDelete( $this->temp_dir );
		}
	}

	/**
	 * Get all WP_CLI calls of specific method
	 *
	 * Filters WP_CLI tracked calls by method name and re-indexes the array.
	 *
	 * @param string $method Method name to filter (error, success, warning, log).
	 * @return array Re-indexed array of matching calls.
	 */
	private function getWpCliCalls( string $method ): array {
		$filtered = array_filter(
			WP_CLI::get_calls(),
			function ( $call ) use ( $method ) {
				return $call['method'] === $method;
			}
		);

		// Re-index array since array_filter preserves keys
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
	 * Get format_items call from WP_CLI tracked calls
	 *
	 * Helper to extract the format_items call which contains format, items, and fields.
	 *
	 * @return array|null Format call array with 'method' and 'args' keys, or null if not found.
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
	 * Data provider for output format tests
	 *
	 * @return array Test cases with format name and expected classes count.
	 */
	public function outputFormatProvider(): array {
		return array(
			'json format' => array( 'json', 'json', 1 ),
			'csv format'  => array( 'csv', 'csv', 1 ),
			'yaml format' => array( 'yaml', 'yaml', 1 ),
		);
	}

	/**
	 * GIVEN classes to list
	 * WHEN using non-table output formats
	 * THEN should delegate to format_items with correct arguments
	 *
	 * @dataProvider outputFormatProvider
	 */
	public function test_supports_multiple_output_formats( string $expected_format, ?string $format_arg, int $class_count ): void {
		$this->createTestClass( 'Test_Service' );

		$command = new List_Command();
		$args    = array( 'dir' => $this->temp_dir );
		if ( null !== $format_arg ) {
			$args['format'] = $format_arg;
		}

		$command->__invoke( array(), $args );

		// Verify format_items was called with correct format
		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call, 'format_items should be called' );
		$this->assertEquals( $expected_format, $format_call['args'][0], 'Output format should match' );
		$this->assertCount( $class_count, $format_call['args'][1], 'Should list expected number of classes' );
		$this->assertEquals( array(
			'class',
			'type',
			'autowirable',
			'source',
		), $format_call['args'][2], 'Should include all required fields' );
	}

	/**
	 * GIVEN classes to list
	 * WHEN using default table format
	 * THEN should render a colored table via WP_CLI::log
	 */
	public function test_table_format_renders_colored_output(): void {
		$this->createTestClass( 'Test_Service_One' );
		$this->createTestClass( 'Test_Service_Two' );

		$command = new List_Command();
		$command->__invoke( array(), array( 'dir' => $this->temp_dir ) );

		// Table format does not use format_items.
		$format_call = $this->getFormatItemsCall();
		$this->assertNull( $format_call, 'Table format should not call format_items' );

		$output = $this->getLogOutput();

		// Should contain box-drawing table structure.
		$this->assertStringContainsString( "\xE2\x94\x8C", $output );
		$this->assertStringContainsString( "\xE2\x94\x82", $output );
		$this->assertStringContainsString( 'class', $output );
		$this->assertStringContainsString( 'Test_Service_One', $output );
		$this->assertStringContainsString( 'Test_Service_Two', $output );
	}

	/**
	 * GIVEN an invalid directory path
	 * WHEN attempting to list
	 * THEN should show error and exit
	 */
	public function test_shows_error_for_invalid_directory(): void {
		$command = new List_Command();

		// WP_CLI::error() throws exception to simulate exit
		$this->expectException( 'WP_CLI_Exception' );

		try {
			$command->__invoke(
				array(),
				array( 'dir' => '/nonexistent/path' )
			);
		} catch ( WP_CLI_Exception $e ) {
			// Verify error was called before exception
			$error_calls = $this->getWpCliCalls( 'error' );
			$this->assertCount( 1, $error_calls );
			$this->assertStringContainsString( 'does not exist', $error_calls[0]['args'][0] );

			// Re-throw to satisfy expectException
			throw $e;
		}
	}

	/**
	 * GIVEN a directory with no classes
	 * WHEN listing
	 * THEN should show log message
	 */
	public function test_shows_log_message_when_no_classes_found(): void {
		$command = new List_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		// Verify log was called
		$log_calls = $this->getWpCliCalls( 'log' );
		$this->assertCount( 1, $log_calls );
		$this->assertStringContainsString( 'No services found', $log_calls[0]['args'][0] );
	}

	/**
	 * GIVEN concrete, interface, and abstract classes
	 * WHEN listing
	 * THEN should identify class types and autowirability correctly
	 */
	public function test_identifies_class_metadata_correctly(): void {
		// Create different class types
		$this->createConcreteClass( 'Concrete_Service' );
		$this->createInterface( 'Service_Interface' );
		$this->createAbstractClass( 'Abstract_Service' );

		$command = new List_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		// Only concrete class should be listed (Auto_Discovery filters out interfaces/abstracts)
		$this->assertStringContainsString( 'Concrete_Service', $output, 'Should contain concrete class' );
		$this->assertStringContainsString( 'concrete', $output, 'Class type should be concrete' );
		$this->assertStringContainsString( 'yes', $output, 'Concrete class should be autowirable' );
		$this->assertStringNotContainsString( 'Service_Interface', $output, 'Should not list interfaces' );
		$this->assertStringNotContainsString( 'Abstract_Service', $output, 'Should not list abstract classes' );
	}

	/**
	 * GIVEN no path argument provided
	 * WHEN listing
	 * THEN should use current working directory
	 */
	public function test_uses_current_directory_when_no_path_provided(): void {
		// Create test class in temp dir
		$this->createTestClass( 'Test_Service' );

		// Change to temp directory
		$original_cwd = getcwd();
		chdir( $this->temp_dir );

		try {
			$command = new List_Command();
			$command->__invoke( array(), array() );

			$output = $this->getLogOutput();
			$this->assertStringContainsString( 'Test_Service', $output, 'Should find the test class' );
		} finally {
			chdir( $original_cwd );
		}
	}

	/**
	 * GIVEN classes in src/ and entries in wpdi-config.php
	 * WHEN listing
	 * THEN should show both with correct source values
	 */
	public function test_includes_config_entries_with_source_column(): void {
		// Create a discovered class
		$this->createTestClass( 'My_Service' );

		// Create wpdi-config.php with interface binding
		$config_content = <<<'PHP'
<?php
return array(
	'Logger_Interface' => fn( $r ) => new stdClass(),
);
PHP;
		file_put_contents( $this->temp_dir . '/wpdi-config.php', $config_content );

		$command = new List_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		// Should contain both discovered and configured services
		$this->assertStringContainsString( 'My_Service', $output, 'Should include discovered class' );
		$this->assertStringContainsString( 'Logger_Interface', $output, 'Should include configured service' );
		$this->assertStringContainsString( 'src', $output, 'Should show src source' );
		$this->assertStringContainsString( 'config', $output, 'Should show config source' );
	}

	/**
	 * GIVEN multiple classes
	 * WHEN listing with --filter matching one class
	 * THEN should only show matching classes
	 */
	public function test_filter_limits_output_to_matching_classes(): void {
		$this->createTestClass( 'Alpha_Service' );
		$this->createTestClass( 'Beta_Handler' );

		$command = new List_Command();
		$command->__invoke(
			array(),
			array(
				'dir'    => $this->temp_dir,
				'filter' => 'Alpha',
			)
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Alpha_Service', $output );
		$this->assertStringNotContainsString( 'Beta_Handler', $output );
	}

	/**
	 * GIVEN multiple classes
	 * WHEN listing with --filter matching nothing
	 * THEN should show "No services found" message
	 */
	public function test_filter_with_no_matches_shows_log_message(): void {
		$this->createTestClass( 'Some_Service' );

		$command = new List_Command();
		$command->__invoke(
			array(),
			array(
				'dir'    => $this->temp_dir,
				'filter' => 'Nonexistent',
			)
		);

		$log_calls = $this->getWpCliCalls( 'log' );
		$this->assertCount( 1, $log_calls );
		$this->assertStringContainsString( 'No services found', $log_calls[0]['args'][0] );
	}

	/**
	 * Create a simple concrete test class file and load it
	 *
	 * Creates a PHP file with a simple class definition and requires it
	 * so that class_exists() returns true for Auto_Discovery filtering.
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

		// Load the class so class_exists() returns true
		// Check first to avoid redeclaration errors in tests
		if ( ! class_exists( $class_name, false ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Create a concrete class for testing class type detection
	 */
	private function createConcreteClass( string $class_name ): void {
		$this->createTestClass( $class_name );
	}

	/**
	 * Create an interface for testing class type detection
	 */
	private function createInterface( string $interface_name ): void {
		$file_path     = $this->temp_dir . '/src/' . $interface_name . '.php';
		$class_content = <<<PHP
<?php
interface {$interface_name} {
	public function do_something(): void;
}
PHP;
		file_put_contents( $file_path, $class_content );

		// Load the interface
		if ( ! interface_exists( $interface_name, false ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Create an abstract class for testing class type detection
	 */
	private function createAbstractClass( string $class_name ): void {
		$file_path     = $this->temp_dir . '/src/' . $class_name . '.php';
		$class_content = <<<PHP
<?php
abstract class {$class_name} {
	abstract public function do_something(): void;
}
PHP;
		file_put_contents( $file_path, $class_content );

		// Load the abstract class
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
