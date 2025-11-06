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
			array( 'path' => $this->temp_dir )
		);

		// Verify cache file was created
		$cache_file = $this->temp_dir . '/cache/wpdi-container.php';
		$this->assertFileExists( $cache_file, 'Cache file should be created' );

		// Verify cache file contains the class
		$cache_content = require $cache_file;
		$this->assertIsArray( $cache_content, 'Cache should return array' );
		$this->assertContains( 'Test_Service', $cache_content, 'Cache should contain discovered class' );

		// Verify success message was called
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertCount( 1, $success_calls, 'Should show success message' );
		$this->assertStringContainsString( 'compiled successfully', $success_calls[0]['args'][0] );
	}

	/**
	 * GIVEN a cache file already exists
	 * WHEN compiling without force flag
	 * THEN should show warning and not overwrite
	 */
	public function test_shows_warning_when_cache_exists_without_force(): void {
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
			array( 'path' => $this->temp_dir )
		);

		// Verify cache was not modified
		$this->assertEquals( $original_mtime, filemtime( $cache_file ) );

		// Verify warning was shown
		$warning_calls = $this->getWpCliCalls( 'warning' );
		$this->assertCount( 1, $warning_calls );
		$this->assertStringContainsString( 'already exists', $warning_calls[0]['args'][0] );
	}

	/**
	 * GIVEN a cache file already exists
	 * WHEN compiling with force flag
	 * THEN cache should be recompiled
	 */
	public function test_recompiles_cache_when_force_flag_set(): void {
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
			array(
				'path'  => $this->temp_dir,
				'force' => true,
			)
		);

		// Verify cache was modified
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
				array( 'path' => '/nonexistent/path' )
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
			array( 'path' => $this->temp_dir )
		);

		// Verify warning was shown
		$warning_calls = $this->getWpCliCalls( 'warning' );
		$this->assertCount( 1, $warning_calls );
		$this->assertStringContainsString( 'No classes found', $warning_calls[0]['args'][0] );

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

		// Create config file
		$config_content = <<<'PHP'
<?php
return array(
	'Logger_Interface' => function() { return new ArrayLogger(); },
	'Config_Interface' => function() { return new ArrayConfig(); },
);
PHP;
		file_put_contents( $this->temp_dir . '/wpdi-config.php', $config_content );

		$command = new Compile_Command();
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);

		// Get all log messages
		$log_calls    = $this->getWpCliCalls( 'log' );
		$log_messages = array_column( array_column( $log_calls, 'args' ), 0 );
		$all_logs     = implode( "\n", $log_messages );

		// Verify all discovered classes are listed
		$this->assertStringContainsString( 'Service_One', $all_logs, 'Should log Service_One' );
		$this->assertStringContainsString( 'Service_Two', $all_logs, 'Should log Service_Two' );
		$this->assertStringContainsString( 'Service_Three', $all_logs, 'Should log Service_Three' );
		$this->assertStringContainsString( 'Found 3 classes', $all_logs, 'Should show class count' );

		// Verify configuration loading
		$this->assertStringContainsString( 'Loading configuration from wpdi-config.php', $all_logs, 'Should log config loading' );
		$this->assertStringContainsString( 'Manual configurations: 2', $all_logs, 'Should show config count' );
		$this->assertStringContainsString( 'Logger_Interface', $all_logs, 'Should list Logger_Interface' );
		$this->assertStringContainsString( 'Config_Interface', $all_logs, 'Should list Config_Interface' );

		// Verify progress messages appear in order
		$this->assertStringContainsString( 'Discovering classes', $log_messages[0], 'First message should be discovery' );
		$this->assertStringContainsString( 'Found', $log_messages[1], 'Second message should show count' );
		$this->assertStringContainsString( 'Compiling container cache', $all_logs, 'Should show compilation step' );
		$this->assertStringContainsString( 'Total discovered classes', $all_logs, 'Should show summary' );
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
			$this->assertContains( 'Test_Service', $cache_content, 'Cache should contain discovered class' );
		} finally {
			chdir( $original_cwd );
		}
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
