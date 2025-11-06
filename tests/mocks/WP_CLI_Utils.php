<?php
/**
 * Mock WP_CLI\Utils for testing
 */

namespace WP_CLI;

/**
 * Mock Utils class
 */
class Utils {
	/**
	 * Mock format_items method
	 *
	 * @param string $format Output format.
	 * @param array  $items  Items to format.
	 * @param array  $fields Fields to display.
	 */
	public static function format_items( string $format, array $items, array $fields ): void {
		// Simple mock - just track the call
		\WP_CLI::log( "format_items called with format: {$format}" );
	}
}
