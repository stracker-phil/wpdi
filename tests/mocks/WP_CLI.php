<?php
/**
 * Mock WP_CLI for testing
 *
 * This mock class replaces the real WP_CLI class during testing, allowing us to:
 * - Track which WP_CLI methods were called (error, success, warning, log)
 * - Verify command behavior without actually exiting the test process
 * - Inspect the messages passed to each method
 *
 * Usage in tests:
 *   WP_CLI::reset_calls();           // Clear tracked calls (call in setUp)
 *   $command->__invoke(...);          // Execute command
 *   $calls = WP_CLI::get_calls();    // Get all tracked method calls
 */

/**
 * Mock WP_CLI class with method call tracking
 */
class WP_CLI {
	/**
	 * Tracks all method calls made to WP_CLI
	 *
	 * @var array Array of arrays with 'method' and 'args' keys
	 */
	private static array $calls = array();

	/**
	 * Mock error method - throws exception to simulate exit behavior
	 *
	 * In real WP_CLI, error() calls exit(1) to terminate execution.
	 * We throw an exception instead to stop execution without killing the test process.
	 *
	 * @param string $message Error message.
	 * @throws WP_CLI_Exception To simulate exit behavior.
	 */
	public static function error( string $message ): void {
		self::$calls[] = array(
			'method' => 'error',
			'args'   => array( $message ),
		);
		throw new WP_CLI_Exception( $message );
	}

	/**
	 * Mock success method - logs success message
	 *
	 * @param string $message Success message.
	 */
	public static function success( string $message ): void {
		self::$calls[] = array(
			'method' => 'success',
			'args'   => array( $message ),
		);
	}

	/**
	 * Mock warning method - logs warning message
	 *
	 * @param string $message Warning message.
	 */
	public static function warning( string $message ): void {
		self::$calls[] = array(
			'method' => 'warning',
			'args'   => array( $message ),
		);
	}

	/**
	 * Mock log method - logs informational message
	 *
	 * @param string $message Log message.
	 */
	public static function log( string $message ): void {
		self::$calls[] = array(
			'method' => 'log',
			'args'   => array( $message ),
		);
	}

	/**
	 * Get all tracked method calls
	 *
	 * @return array Array of method calls with 'method' and 'args' keys.
	 */
	public static function get_calls(): array {
		return self::$calls;
	}

	/**
	 * Reset tracked calls - call this in setUp() to clear between tests
	 */
	public static function reset_calls(): void {
		self::$calls = array();
	}

	/**
	 * Track a method call (used by other mock classes)
	 *
	 * Allows other mock classes (like WP_CLI\Utils) to register their calls
	 * in the same tracking array for unified test verification.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 */
	public static function track_call( string $method, array $args ): void {
		self::$calls[] = array(
			'method' => $method,
			'args'   => $args,
		);
	}

	/**
	 * Mock add_command method - does nothing in tests
	 *
	 * @param string $name Command name.
	 * @param mixed  $callable Command handler.
	 */
	public static function add_command( string $name, $callable ): void {
		// No-op in tests
	}
}

/**
 * Exception thrown by WP_CLI::error() to simulate exit behavior
 */
class WP_CLI_Exception extends \Exception {}
