<?php
/**
 * Mock WP_CLI\Utils for testing
 *
 * This mock provides the WP_CLI\Utils\format_items() function used by
 * the Discover_Command to display discovered classes in different formats.
 *
 * The mock tracks calls to format_items() by registering them with the
 * main WP_CLI mock class, allowing tests to verify:
 * - Which format was requested (table, json, csv, yaml)
 * - What items were passed for formatting
 * - Which fields were included in the output
 *
 * Usage in tests:
 *   $command->__invoke(...);                    // Execute command
 *   $calls = WP_CLI::get_calls();               // Get all tracked calls
 *   $format_call = find_call('format_items');   // Find format_items call
 *   assert($format_call['args'][0] === 'json'); // Verify format
 */

namespace WP_CLI\Utils;

use WP_CLI;

/**
 * Mock format_items function
 *
 * Simulates WP_CLI\Utils\format_items() by tracking the call parameters
 * through the WP_CLI mock class for test verification.
 *
 * @param string $format Output format (table, json, csv, yaml).
 * @param array  $items  Items to format.
 * @param array  $fields Fields to display.
 */
function format_items( string $format, array $items, array $fields ): void {
	// Track the call in WP_CLI mock for test verification
	WP_CLI::track_call( 'format_items', array( $format, $items, $fields ) );
}
