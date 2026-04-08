<?php
/**
 * Tests for Compile_Command
 */

use PHPUnit\Framework\TestCase;
use WPDI\Commands\Compile_Command;

/**
 * Test Compile_Command behavior
 */
class CompileCommandTest extends TestCase {
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
	 * GIVEN a valid module directory with classes
	 * WHEN compiling for the first time
	 * THEN cache should be created successfully with correct content
	 */
	public function test_compiles_cache_successfully_for_new_project(): void {
		// Create a test class file
		$this->createTestClass( 'Test_Service' );

		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		// Verify cache file was created
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file, 'Cache file should be created' );

		// Verify cache file contains the class
		$cache_content = require $cache_file;
		$this->assertIsArray( $cache_content, 'Cache should return array' );
		$this->assertArrayHasKey( 'classes', $cache_content, 'Cache should have classes section' );
		$this->assertArrayHasKey( 'Test_Service', $cache_content['classes'], 'Cache should contain discovered class' );

		// Verify success message was called
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertCount( 1, $success_calls, 'Should show success message' );
		$this->assertStringContainsString( 'Container compiled to', $success_calls[0]['args'][0] );
	}

	/**
	 * GIVEN a cache file already exists
	 * WHEN compiling
	 * THEN cache should always be recompiled
	 */
	public function test_always_recompiles_when_cache_exists(): void {
		// Create test class and initial cache
		$this->createTestClass( 'Test_Service' );
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		mkdir( dirname( $cache_file ), 0777, true );
		file_put_contents( $cache_file, '<?php return array();' );

		$original_mtime = filemtime( $cache_file );
		sleep( 1 ); // Ensure time difference

		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		// Verify cache was overwritten
		$this->assertGreaterThan( $original_mtime, filemtime( $cache_file ) );

		// Verify success message, no warning
		$success_calls = $this->getWpCliCalls( 'success' );
		$warning_calls = $this->getWpCliCalls( 'warning' );
		$this->assertCount( 1, $success_calls );
		$this->assertCount( 0, $warning_calls );
	}

	/**
	 * GIVEN an invalid directory path
	 * WHEN attempting to compile
	 * THEN should show error and exit
	 */
	public function test_shows_error_for_invalid_directory(): void {
		$command = new Compile_Command();

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
	 * GIVEN a project with no classes
	 * WHEN attempting to compile
	 * THEN should show warning about no classes
	 */
	public function test_shows_warning_when_no_classes_found(): void {
		// Create empty src directory
		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		// Verify the "no classes" message was logged
		$log_calls    = $this->getWpCliCalls( 'log' );
		$log_messages = implode( "\n", array_column( array_column( $log_calls, 'args' ), 0 ) );
		$this->assertStringContainsString( 'no classes found', $log_messages );

		// Verify no cache file was created
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		$this->assertFileDoesNotExist( $cache_file );
	}

	/**
	 * GIVEN a project with multiple classes and configuration
	 * WHEN compiling
	 * THEN should log all discovered classes, configuration, and progress messages
	 */
	public function test_logs_complete_compilation_process(): void {
		// Create multiple test classes
		$this->createTestClass( 'Service_One' );
		$this->createTestClass( 'Service_Two' );
		$this->createTestClass( 'Service_Three' );

		// Create config file with valid static class name mappings
		$config_content = <<<'PHP'
<?php
return array(
	'Logger_Interface' => 'Array_Logger',
	'Config_Interface' => 'Array_Config',
);
PHP;
		file_put_contents( $this->temp_dir . '/wpdi-config.php', $config_content );

		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array( 'dir' => $this->temp_dir )
		);

		// Get all log messages
		$log_calls    = $this->getWpCliCalls( 'log' );
		$log_messages = array_column( array_column( $log_calls, 'args' ), 0 );
		$all_logs     = implode( "\n", $log_messages );

		// Verify all discovered classes are listed
		$this->assertStringContainsString( 'Service_One', $all_logs, 'Should log Service_One' );
		$this->assertStringContainsString( 'Service_Two', $all_logs, 'Should log Service_Two' );
		$this->assertStringContainsString( 'Service_Three', $all_logs, 'Should log Service_Three' );
		$this->assertStringContainsString( 'discovered 3 classes', $all_logs, 'Should show class count' );

		// Verify config entries are listed
		$this->assertStringContainsString( 'Logger_Interface', $all_logs, 'Should list Logger_Interface' );
		$this->assertStringContainsString( 'Config_Interface', $all_logs, 'Should list Config_Interface' );
		$this->assertStringContainsString( 'found 2 manual configs', $all_logs, 'Should show config count' );
	}

	/**
	 * GIVEN a wpdi-config.php that contains a closure as a binding value
	 * WHEN compiling
	 * THEN should error with a message identifying the offending key
	 */
	public function test_rejects_closure_binding_in_config(): void {
		$this->createTestClass( 'Test_Service' );

		$config_content = <<<'PHP'
<?php
return array(
	'Logger_Interface' => function() { return new stdClass(); },
);
PHP;
		file_put_contents( $this->temp_dir . '/wpdi-config.php', $config_content );

		$command = new Compile_Command();

		$this->expectException( 'WP_CLI_Exception' );

		try {
			$command->__invoke(
				array(),
				array( 'dir' => $this->temp_dir )
			);
		} catch ( WP_CLI_Exception $e ) {
			$error_calls = $this->getWpCliCalls( 'error' );
			$this->assertCount( 1, $error_calls );
			$this->assertStringContainsString( 'Logger_Interface', $error_calls[0]['args'][0] );
			$this->assertStringContainsString( 'wpdi-config.php', $error_calls[0]['args'][0] );

			throw $e;
		}
	}

	/**
	 * GIVEN no path argument provided
	 * WHEN compiling
	 * THEN should use current working directory
	 */
	public function test_uses_current_directory_when_no_path_provided(): void {
		// Create test class in temp dir
		$this->createTestClass( 'Test_Service' );

		// Change to temp directory
		$original_cwd = getcwd();
		chdir( $this->temp_dir );

		try {
			$command = new Compile_Command();
			$command->__invoke( array(), array() );

			// Verify cache was created in current directory
			$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
			$this->assertFileExists( $cache_file, 'Cache should be created in current directory' );

			// Verify cache content
			$cache_content = require $cache_file;
			$this->assertArrayHasKey( 'Test_Service', $cache_content['classes'], 'Cache should contain discovered class' );
		} finally {
			chdir( $original_cwd );
		}
	}

	/**
	 * Get format_items call from WP_CLI tracked calls
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
	 * GIVEN a valid module directory with classes
	 * WHEN compiling with --format=json
	 * THEN should output structured JSON with classes and bindings
	 */
	public function test_json_format_outputs_structured_data(): void {
		$this->createTestClass( 'Test_Service' );

		$command = new Compile_Command();

		ob_start();
		$command->__invoke(
			array(),
			array(
				'dir'    => $this->temp_dir,
				'format' => 'json',
			)
		);
		$output = ob_get_clean();

		$data = json_decode( $output, true );
		$this->assertNotNull( $data, 'Output should be valid JSON' );
		$this->assertArrayHasKey( 'classes', $data );
		$this->assertArrayHasKey( 'bindings', $data );
		$this->assertCount( 1, $data['classes'] );
		$this->assertEquals( 'Test_Service', $data['classes'][0]['class'] );
		$this->assertEmpty( $data['bindings'] );

		// Cache should still be written.
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file );

		// No visual output should be produced.
		$logs         = $this->getWpCliCalls( 'log' );
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertEmpty( $logs );
		$this->assertEmpty( $success_calls );
	}

	/**
	 * GIVEN a module with classes and config
	 * WHEN compiling with --format=json
	 * THEN should include bindings in structured output
	 */
	public function test_json_format_includes_config_bindings(): void {
		$this->createTestClass( 'Test_Service' );

		$config_content = <<<'PHP'
<?php
return array(
	'Logger_Interface' => 'Array_Logger',
);
PHP;
		file_put_contents( $this->temp_dir . '/wpdi-config.php', $config_content );

		$command = new Compile_Command();

		ob_start();
		$command->__invoke(
			array(),
			array(
				'dir'    => $this->temp_dir,
				'format' => 'json',
			)
		);
		$output = ob_get_clean();

		$data = json_decode( $output, true );
		$this->assertCount( 1, $data['bindings'] );
		$this->assertEquals( 'Logger_Interface', $data['bindings'][0]['class'] );
		$this->assertEquals( 'Array_Logger', $data['bindings'][0]['binding'] );
	}

	/**
	 * GIVEN a valid module directory with classes
	 * WHEN compiling with --format=csv
	 * THEN should output combined rows via format_items
	 */
	public function test_csv_format_outputs_combined_rows(): void {
		$this->createTestClass( 'Test_Service' );

		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array(
				'dir'    => $this->temp_dir,
				'format' => 'csv',
			)
		);

		$format_call = $this->getFormatItemsCall();
		$this->assertNotNull( $format_call, 'format_items should be called' );
		$this->assertEquals( 'csv', $format_call['args'][0] );
		$this->assertCount( 1, $format_call['args'][1] );
		$this->assertEquals( 'classes', $format_call['args'][1][0]['section'] );

		// Cache should still be written.
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file );
	}

	/**
	 * Create a test class file and load it
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
	 * GIVEN cache directory is not writable
	 * WHEN attempting to compile
	 * THEN should show error about compilation failure
	 *
	 * Note: Uses 0555 (r-xr-xr-x) permissions which prevents write
	 * but allows directory traversal. The test uses @ to suppress
	 * PHP warnings from file_put_contents when testing the failure path.
	 */
	public function test_shows_error_when_compilation_fails(): void {
		// Create test class
		$this->createTestClass( 'Test_Service' );

		// Create cache directory but make it read-only
		// Use 0555 (r-xr-xr-x) not 0444 - need execute bit for directory access
		$cache_dir = $this->temp_dir . '/cache';
		mkdir( $cache_dir );
		chmod( $cache_dir, 0555 );

		// Test if we can actually prevent writing on this system
		$test_file   = $cache_dir . '/test-write.txt';
		$test_result = @file_put_contents( $test_file, 'test' );

		if ( false !== $test_result ) {
			// File was writable despite permissions - restore and skip test
			chmod( $cache_dir, 0777 );
			@unlink( $test_file );
			// Add assertion count to indicate we verified the precondition
			$this->addToAssertionCount( 1 );
			$this->markTestSkipped( 'Filesystem permissions cannot prevent file writes on this system' );

			return;
		}

		$command = new Compile_Command();

		// WP_CLI::error() throws exception
		$this->expectException( 'WP_CLI_Exception' );

		try {
			// Suppress file_put_contents warning since we're testing the failure path
			@$command->__invoke(
				array(),
				array( 'dir' => $this->temp_dir )
			);
		} catch ( WP_CLI_Exception $e ) {
			// Verify error was called
			$error_calls = $this->getWpCliCalls( 'error' );
			$this->assertCount( 1, $error_calls );
			$this->assertStringContainsString( 'not writable', $error_calls[0]['args'][0] );

			// Restore permissions before cleanup
			chmod( $cache_dir, 0777 );

			// Re-throw to satisfy expectException
			throw $e;
		} finally {
			// Ensure permissions are restored even if test fails
			@chmod( $cache_dir, 0777 );
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
