<?php
/**
 * Tests for Clear_Command
 */

use PHPUnit\Framework\TestCase;
use WPDI\Commands\Clear_Command;

/**
 * Test Clear_Command behavior
 */
class ClearCommandTest extends TestCase {
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
	}

	/**
	 * Cleanup test environment
	 */
	protected function tearDown(): void {
		parent::tearDown();

		// Cleanup temporary files
		if ( file_exists( $this->temp_dir ) ) {
			// Make sure directory is writable before cleanup
			chmod( $this->temp_dir, 0777 );
			if ( is_dir( $this->temp_dir . '/cache' ) ) {
				chmod( $this->temp_dir . '/cache', 0777 );
			}
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
	 * GIVEN a cache file exists
	 * WHEN clearing cache
	 * THEN should delete file and show success
	 */
	public function test_clears_cache_file_successfully(): void {
		// Create cache file
		$cache_dir  = $this->temp_dir . '/cache';
		$cache_file = $cache_dir . '/wpdi-container.php';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );

		$this->assertFileExists( $cache_file );

		$command = new Clear_Command();
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);

		// Verify cache file was deleted
		$this->assertFileDoesNotExist( $cache_file );

		// Verify success message
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertGreaterThanOrEqual( 1, count( $success_calls ) );
		$this->assertStringContainsString( 'Cache cleared', $success_calls[0]['args'][0] );
	}

	/**
	 * GIVEN cache file exists but cannot be deleted
	 * WHEN clearing cache
	 * THEN should show error
	 *
	 * Note: Uses 0555 (r-xr-xr-x) permissions which prevents write/delete
	 * but allows directory traversal (required for file_exists() to work).
	 */
	public function test_shows_error_when_cache_deletion_fails(): void {
		// Create cache file
		$cache_dir  = $this->temp_dir . '/cache';
		$cache_file = $cache_dir . '/wpdi-container.php';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );

		// Make directory read-only to prevent file deletion
		// Use 0555 (r-xr-xr-x) not 0444 - need execute bit for file_exists()
		chmod( $cache_dir, 0555 );

		// Test if we can actually prevent deletion on this system
		$test_result = @unlink( $cache_file );

		if ( $test_result || ! file_exists( $cache_file ) ) {
			// File was deletable despite permissions - restore and skip test
			chmod( $cache_dir, 0777 );
			// Add assertion count to indicate we verified the precondition
			$this->addToAssertionCount( 1 );
			$this->markTestSkipped( 'Filesystem permissions cannot prevent file deletion on this system' );
		}

		// File still exists and couldn't be deleted - this system supports the test
		$command = new Clear_Command();

		// WP_CLI::error() throws exception
		$this->expectException( 'WP_CLI_Exception' );

		try {
			$command->__invoke(
				array(),
				array( 'path' => $this->temp_dir )
			);
		} catch ( WP_CLI_Exception $e ) {
			// Verify error was called
			$error_calls = $this->getWpCliCalls( 'error' );
			$this->assertCount( 1, $error_calls );
			$this->assertStringContainsString( 'Failed to delete', $error_calls[0]['args'][0] );

			// Re-throw to satisfy expectException
			throw $e;
		}
	}

	/**
	 * GIVEN no cache file exists
	 * WHEN clearing cache
	 * THEN should show log message
	 */
	public function test_shows_log_message_when_no_cache_exists(): void {
		$command = new Clear_Command();
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);

		// Verify log message
		$log_calls = $this->getWpCliCalls( 'log' );
		$this->assertCount( 1, $log_calls );
		$this->assertStringContainsString( 'No cache file found', $log_calls[0]['args'][0] );
	}

	/**
	 * GIVEN cache file exists in otherwise empty directory
	 * WHEN clearing cache
	 * THEN should remove both file and empty directory
	 */
	public function test_removes_empty_cache_directory(): void {
		// Create cache file
		$cache_dir  = $this->temp_dir . '/cache';
		$cache_file = $cache_dir . '/wpdi-container.php';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );

		$command = new Clear_Command();
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);

		// Verify cache directory was removed
		$this->assertDirectoryDoesNotExist( $cache_dir );

		// Verify success messages
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertCount( 2, $success_calls );
		$this->assertStringContainsString( 'Cache cleared', $success_calls[0]['args'][0] );
		$this->assertStringContainsString( 'Removed empty cache directory', $success_calls[1]['args'][0] );
	}

	/**
	 * GIVEN cache directory contains other files
	 * WHEN clearing cache
	 * THEN should remove cache file but keep directory
	 */
	public function test_keeps_cache_directory_with_other_files(): void {
		// Create cache file and another file
		$cache_dir   = $this->temp_dir . '/cache';
		$cache_file  = $cache_dir . '/wpdi-container.php';
		$other_file  = $cache_dir . '/other-file.txt';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );
		file_put_contents( $other_file, 'other content' );

		$command = new Clear_Command();
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);

		// Verify cache file was deleted but directory still exists
		$this->assertFileDoesNotExist( $cache_file );
		$this->assertDirectoryExists( $cache_dir );
		$this->assertFileExists( $other_file );

		// Should only have one success message (cache cleared, not directory removed)
		$success_calls = $this->getWpCliCalls( 'success' );
		$this->assertCount( 1, $success_calls );
		$this->assertStringContainsString( 'Cache cleared', $success_calls[0]['args'][0] );
	}

	/**
	 * GIVEN no path argument provided
	 * WHEN clearing cache
	 * THEN should use current working directory
	 */
	public function test_uses_current_directory_when_no_path_provided(): void {
		// Create cache file in temp dir
		$cache_dir  = $this->temp_dir . '/cache';
		$cache_file = $cache_dir . '/wpdi-container.php';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );

		// Change to temp directory
		$original_cwd = getcwd();
		chdir( $this->temp_dir );

		try {
			$command = new Clear_Command();
			$command->__invoke( array(), array() );

			// Verify cache was cleared from current directory
			$this->assertFileDoesNotExist( $cache_file );

			// Verify success message
			$success_calls = $this->getWpCliCalls( 'success' );
			$this->assertGreaterThanOrEqual( 1, count( $success_calls ) );
		} finally {
			chdir( $original_cwd );
		}
	}

	/**
	 * GIVEN multiple cache clearing operations
	 * WHEN clearing cache repeatedly
	 * THEN should handle gracefully
	 */
	public function test_handles_repeated_clear_operations(): void {
		// Create cache file
		$cache_dir  = $this->temp_dir . '/cache';
		$cache_file = $cache_dir . '/wpdi-container.php';
		mkdir( $cache_dir );
		file_put_contents( $cache_file, '<?php return array();' );

		$command = new Clear_Command();

		// First clear - should succeed
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);
		$this->assertFileDoesNotExist( $cache_file );

		// Reset calls
		WP_CLI::reset_calls();

		// Second clear - should show "no cache file found"
		$command->__invoke(
			array(),
			array( 'path' => $this->temp_dir )
		);
		$log_calls = $this->getWpCliCalls( 'log' );
		$this->assertCount( 1, $log_calls );
		$this->assertStringContainsString( 'No cache file found', $log_calls[0]['args'][0] );
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
