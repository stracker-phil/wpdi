<?php
/**
 * WP-CLI command for compiling WPDI container cache.
 *
 * @package WPDI\Commands
 */

namespace WPDI\Commands;

use WPDI\Auto_Discovery;
use WPDI\Cache_Store;

/**
 * Compile WPDI container for production performance
 */
class Compile_Command extends Command {
	/**
	 * Compile WPDI container cache
	 *
	 * @subcommand compile
	 * @synopsis [--dir=<dir>] [--autowiring-paths=<paths>] [--format=<format>]
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--format=<format>]
	 * : Output format: table (default), ascii, json, yaml, csv
	 *
	 * ## EXAMPLES
	 *
	 *     wp di compile
	 *     wp di compile --dir=/path/to/module
	 *     wp di compile --autowiring-paths=src,modules/auth/src
	 */
	public function __invoke( $args, $assoc_args ) {
		$path             = $assoc_args['dir'] ?? getcwd();
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );
		$this->parse_format_flag( $assoc_args );

		if ( ! is_dir( $path ) ) {
			$this->error( "Directory does not exist: {$path}" );
		}

		$store = new Cache_Store( $path );

		// Check cache directory is writable before doing any work.
		if ( ! $store->ensure_dir() ) {
			$this->error( "Cache directory is not writable: {$path}/cache\nEnsure the directory exists and has write permissions." );
		}

		$discovery      = new Auto_Discovery();
		$classes        = array();
		$all_class_rows = array();

		// Discover from each autowiring path and render a table per source.
		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				$this->warning( "Autowiring path does not exist: {$full_path}" );
				continue;
			}

			$discovered = $discovery->discover( $full_path );
			$classes    = array_merge( $classes, $discovered );

			$rows = array();
			foreach ( $discovered as $class => $metadata ) {
				$rows[] = array(
					'type'  => $this->format_type_label( $this->inspector->get_type( $class ) ),
					'class' => $class,
				);
			}
			$all_class_rows = array_merge( $all_class_rows, $rows );

			if ( ! $this->is_data_format() ) {
				$this->table(
					$rows,
					array( 'type', 'class' ),
					array(
						'type'  => 'type_label',
						'class' => 'class_name',
					),
					"/$autowiring_path"
				);
			}
		}

		if ( ! $this->is_data_format() ) {
			$this->results_found( $classes, 'discovered 1 class', 'discovered %d classes', 'no classes found to compile' );
		}
		if ( empty( $classes ) ) {
			return;
		}
		if ( ! $this->is_data_format() ) {
			$this->log( '' );
		}

		// Check for manual configuration.
		$config_file     = $path . '/wpdi-config.php';
		$config          = array();
		$manual_configs  = array();
		$all_config_rows = array();
		if ( file_exists( $config_file ) ) {
			$config         = require $config_file;
			$manual_configs = array_keys( $config );

			// Validate: only string class names and contextual arrays are allowed.
			foreach ( $config as $interface => $value ) {
				if ( is_string( $value ) ) {
					continue;
				}
				if ( is_array( $value ) ) {
					foreach ( $value as $param => $concrete ) {
						if ( ! is_string( $concrete ) ) {
							$this->error( "Invalid binding for '{$interface}[\"{$param}\"]' in wpdi-config.php: only class name strings are allowed." );
						}
					}
					continue;
				}
				$type = gettype( $value );
				$this->error( "Invalid binding for '{$interface}' in wpdi-config.php: expected a class name string, got {$type}." );
			}

			$rows       = array();
			$separators = array();

			foreach ( $config as $interface => $value ) {
				$interface_type =
					$this->format_type_label( $this->inspector->get_type( $interface ) );

				if ( is_array( $value ) ) {
					// Contextual binding: one row per param entry.
					$first = true;
					foreach ( $value as $param => $concrete ) {
						if ( $first && count( $rows ) > 0 ) {
							$separators[] = count( $rows );
						}
						$rows[] = array(
							'type'         => $interface_type,
							'class'        => $interface,
							'param'        => $param,
							'binding_type' => $this->format_type_label( $this->inspector->get_type( $concrete ) ),
							'binding'      => $concrete,
						);
						$first  = false;
					}
				} else {
					// Simple binding: single row.
					if ( count( $rows ) > 0 ) {
						$separators[] = count( $rows );
					}
					$rows[] = array(
						'type'         => $interface_type,
						'class'        => $interface,
						'param'        => 'default',
						'binding_type' => $this->format_type_label( $this->inspector->get_type( $value ) ),
						'binding'      => $value,
					);
				}
			}

			$all_config_rows = $rows;

			if ( ! $this->is_data_format() ) {
				$this->table(
					$rows,
					array( 'type', 'class', 'param', 'binding' ),
					array(
						'type'    => 'type_label',
						'class'   => 'class_name',
						'param'   => 'param',
						'binding' => 'class_binding',
					),
					'/wpdi-config.php',
					$separators
				);
			}
		}
		if ( ! $this->is_data_format() ) {
			$this->results_found( $manual_configs, 'found 1 manual config', 'found %d manual configs', '' );
			$this->log( '' );
		}

		if ( $store->write( $classes, $config ) ) {
			if ( $this->is_data_format() ) {
				$this->output_compile_data( $all_class_rows, $all_config_rows );
			} else {
				$this->success( 'Container compiled to: ' . $store->get_cache_file() );
			}
		} else {
			$this->error( 'Failed to compile container cache' );
		}
	}

	/**
	 * Output compiled data in machine-readable format.
	 *
	 * @param array $class_rows  Discovered class rows.
	 * @param array $config_rows Config binding rows.
	 */
	private function output_compile_data( array $class_rows, array $config_rows ): void {
		if ( 'json' === $this->format ) {
			echo wp_json_encode(
				array(
					'classes'  => $class_rows,
					'bindings' => $config_rows,
				)
			) . "\n";

			return;
		}

		// csv/yaml: combined flat rows with section discriminator.
		$combined = array();

		foreach ( $class_rows as $row ) {
			$combined[] = array(
				'section'      => 'classes',
				'type'         => $row['type'],
				'class'        => $row['class'],
				'param'        => '',
				'binding_type' => '',
				'binding'      => '',
			);
		}

		foreach ( $config_rows as $row ) {
			$combined[] = array(
				'section'      => 'bindings',
				'type'         => $row['type'],
				'class'        => $row['class'],
				'param'        => $row['param'],
				'binding_type' => $row['binding_type'],
				'binding'      => $row['binding'],
			);
		}

		$this->format_items( $combined, array( 'section', 'type', 'class', 'param', 'binding_type', 'binding' ) );
	}

}
