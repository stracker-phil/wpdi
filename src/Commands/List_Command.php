<?php
/**
 * WP-CLI command for listing WPDI services.
 *
 * @package WPDI\Commands
 */

namespace WPDI\Commands;

/**
 * List services without compiling
 */
class List_Command extends Command {

	/**
	 * List all injectable services
	 *
	 * @subcommand list
	 * @synopsis [--dir=<dir>] [--autowiring-paths=<paths>] [--filter=<filter>] [--format=<format>]
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--filter=<filter>]
	 * : Only show services whose fully-qualified class name contains this substring
	 *
	 * [--format=<format>]
	 * : Output format: table (default), ascii, json, yaml, csv
	 *
	 * ## EXAMPLES
	 *
	 *     wp di list
	 *     wp di list --dir=/path/to/module --format=json
	 *     wp di list --filter=Services
	 *     wp di list --autowiring-paths=src,modules/auth/src
	 */
	public function __invoke( $args, $assoc_args ) {
		$path             = $assoc_args['dir'] ?? getcwd();
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );
		$this->parse_format_flag( $assoc_args );

		if ( ! is_dir( $path ) ) {
			$this->error( "Directory does not exist: {$path}" );
		}

		$this->load_module_cache( $path, $autowiring_paths );

		$classes = $this->cache_data['classes'];
		$config  = $this->cache_data['bindings'];
		$output  = array();
		$listed  = array();

		// Collect non-concrete dependencies (interfaces, abstracts) from discovered classes.
		$dep_classes = array();
		foreach ( $classes as $class => $metadata ) {
			foreach ( $metadata['dependencies'] as $dep ) {
				if ( ! isset( $classes[ $dep ] ) && ! isset( $dep_classes[ $dep ] ) ) {
					$dep_classes[ $dep ] = true;
				}
			}
		}

		// 1. Concrete classes (all entries in classes section are concrete).
		foreach ( $classes as $class => $metadata ) {
			$output[]         = array(
				'class'   => $class,
				'type'    => 'class',
				'binding' => '',
				'mapped'  => '',
			);
			$listed[ $class ] = true;
		}

		// 2. Config entries (expanded for contextual bindings).
		foreach ( $config as $class => $binding ) {
			$listed[ $class ] = true;
			$type             = $this->format_type_label( $this->inspector->get_type( $class ) );

			if ( is_array( $binding ) ) {
				foreach ( $binding as $param => $concrete ) {
					$output[] = array(
						'class'   => $class,
						'type'    => $type,
						'binding' => 'default' === $param ? 'default' : $param,
						'mapped'  => $this->get_short_class_name( $concrete ),
					);
				}
			} else {
				$output[] = array(
					'class'   => $class,
					'type'    => $type,
					'binding' => 'default',
					'mapped'  => is_string( $binding ) ? $this->get_short_class_name( $binding ) : '',
				);
			}
		}

		// 3. Dependency interfaces/abstracts not in config (unbound).
		foreach ( $dep_classes as $dep => $_ ) {
			if ( isset( $listed[ $dep ] ) ) {
				continue;
			}

			$output[] = array(
				'class'   => $dep,
				'type'    => $this->format_type_label( $this->inspector->get_type( $dep ) ),
				'binding' => 'no config',
				'mapped'  => '',
			);
		}

		// Apply --filter substring match against class name.
		$filter = $assoc_args['filter'] ?? '';
		if ( $filter ) {
			$output = array_filter(
				$output,
				static fn( array $item ): bool => false !== strpos( $item['class'], $filter )
			);
			$output = array_values( $output );
		}

		if ( $this->is_data_format() ) {
			$this->format_items( $output, array( 'class', 'type', 'binding', 'mapped' ) );
		} else {
			$this->result_meta( array( 'filter' => $filter ) );

			if ( $output ) {
				$this->table(
					$output,
					array( 'class', 'type', 'binding', 'mapped' ),
					array(
						'class'   => 'class_name',
						'type'    => 'type_label',
						'binding' => 'binding_label',
						'mapped'  => 'mapped_class',
					)
				);
			}

			$this->results_found( $output, '1 entry', '%d entries', 'no results' );
		}
	}

}
