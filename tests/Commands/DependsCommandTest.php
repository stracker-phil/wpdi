<?php
/**
 * Tests for Depends_Command
 */

use PHPUnit\Framework\TestCase;
use WPDI\Commands\Depends_Command;

/**
 * Test Depends_Command behavior
 */
class DependsCommandTest extends TestCase {
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

		$this->temp_dir = sys_get_temp_dir() . '/wpdi-depends-test-' . uniqid();
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
	 * WHEN finding dependents
	 * THEN should show error
	 */
	public function test_shows_error_for_nonexistent_class(): void {
		$command = new Depends_Command();

		$this->expectException( 'WP_CLI_Exception' );

		$command->__invoke(
			array( 'Nonexistent\\Class\\Name' ),
			array( 'dir' => $this->temp_dir )
		);
	}

	/**
	 * GIVEN a target class with no dependents in the scan paths
	 * WHEN finding dependents
	 * THEN should show "no dependents found" message
	 */
	public function test_shows_no_dependents_when_nothing_found(): void {
		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'SimpleClass', $output );
		$this->assertStringContainsString( 'no dependents found', $output );
	}

	/**
	 * GIVEN one class that depends on the target
	 * WHEN finding dependents
	 * THEN should list that class with its parameter name
	 */
	public function test_finds_single_dependent(): void {
		$dep_class = 'Dep_Consumer_' . uniqid();
		$this->createClassWithDependency( $dep_class, 'WPDI\\Tests\\Fixtures\\SimpleClass', 'dep' );

		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( $dep_class, $output );
		$this->assertStringContainsString( '$dep', $output );
		$this->assertStringContainsString( 'class', $output );
		$this->assertStringNotContainsString( 'no dependents found', $output );
	}

	/**
	 * GIVEN multiple classes that depend on the target
	 * WHEN finding dependents
	 * THEN should list all of them
	 */
	public function test_finds_multiple_dependents(): void {
		$uid          = uniqid();
		$first_class  = 'Multi_Consumer_A_' . $uid;
		$second_class = 'Multi_Consumer_B_' . $uid;
		$this->createClassWithDependency( $first_class, 'WPDI\\Tests\\Fixtures\\SimpleClass', 'service' );
		$this->createClassWithDependency( $second_class, 'WPDI\\Tests\\Fixtures\\SimpleClass', 'simple' );

		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( $first_class, $output );
		$this->assertStringContainsString( $second_class, $output );
		$this->assertStringContainsString( '$service', $output );
		$this->assertStringContainsString( '$simple', $output );
	}

	/**
	 * GIVEN a class that depends on a target interface
	 * WHEN finding dependents using the interface as target
	 * THEN should list the dependent class with its parameter name
	 */
	public function test_finds_dependents_of_interface(): void {
		$dep_class = 'Interface_Consumer_' . uniqid();
		$this->createClassWithDependency( $dep_class, 'WPDI\\Tests\\Fixtures\\LoggerInterface', 'logger' );

		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\LoggerInterface' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( $dep_class, $output );
		$this->assertStringContainsString( '$logger', $output );
	}

	/**
	 * GIVEN a class with a short name resolvable via dependency scanning
	 * WHEN finding dependents using that short name
	 * THEN should resolve the short name and find dependents
	 */
	public function test_resolves_short_class_name_as_target(): void {
		$uid        = uniqid();
		$dep_class  = 'Short_Name_Consumer_' . $uid;
		$dep_target = 'Short_Name_Target_' . $uid;

		// Create target class.
		$target_file = $this->temp_dir . '/src/' . $dep_target . '.php';
		file_put_contents( $target_file, "<?php\nclass {$dep_target} {}" );
		if ( ! class_exists( $dep_target, false ) ) {
			require_once $target_file;
		}

		// Create consumer that depends on target.
		$this->createClassWithDependency( $dep_class, $dep_target, 'target' );

		$command = new Depends_Command();

		$command->__invoke(
			array( $dep_target ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( $dep_target, $output );
		$this->assertStringContainsString( $dep_class, $output );
	}

	/**
	 * GIVEN a short name that matches a dependency (interface) in the scan paths
	 * WHEN finding dependents using that short name
	 * THEN should resolve to the interface FQCN and find dependents
	 */
	public function test_resolves_short_interface_name_as_target(): void {
		$dep_class = 'Short_Interface_Consumer_' . uniqid();
		$this->createClassWithDependency( $dep_class, 'WPDI\\Tests\\Fixtures\\LoggerInterface', 'logger' );

		$command = new Depends_Command();

		$command->__invoke(
			array( 'LoggerInterface' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'LoggerInterface', $output );
		$this->assertStringContainsString( $dep_class, $output );
	}

	/**
	 * GIVEN a short name that matches two different FQCNs
	 * WHEN finding dependents
	 * THEN should list candidates and error
	 */
	public function test_errors_on_ambiguous_short_name(): void {
		$uid          = uniqid();
		$shared_short = 'Ambiguous_Dep_Target_' . $uid;
		$consumer_a   = 'Ambiguous_Consumer_A_' . $uid;
		$consumer_b   = 'Ambiguous_Consumer_B_' . $uid;

		// Create two distinct classes with the same short name in different namespaces.
		$ns_a   = 'Ns_A_' . $uid;
		$ns_b   = 'Ns_B_' . $uid;
		$fqcn_a = $ns_a . '\\' . $shared_short;
		$fqcn_b = $ns_b . '\\' . $shared_short;

		$dir_a = $this->temp_dir . '/src/A_' . $uid;
		$dir_b = $this->temp_dir . '/src/B_' . $uid;
		mkdir( $dir_a );
		mkdir( $dir_b );

		$file_a = $dir_a . '/' . $shared_short . '.php';
		$file_b = $dir_b . '/' . $shared_short . '.php';
		file_put_contents( $file_a, "<?php\nnamespace {$ns_a};\nclass {$shared_short} {}" );
		file_put_contents( $file_b, "<?php\nnamespace {$ns_b};\nclass {$shared_short} {}" );
		if ( ! class_exists( $fqcn_a, false ) ) {
			require_once $file_a;
		}
		if ( ! class_exists( $fqcn_b, false ) ) {
			require_once $file_b;
		}

		// Create a consumer for each so both FQCNs appear as dependencies.
		$this->createClassWithDependency( $consumer_a, $fqcn_a, 'dep' );
		$this->createClassWithDependency( $consumer_b, $fqcn_b, 'dep' );

		$command = new Depends_Command();

		$this->expectException( 'WP_CLI_Exception' );

		$command->__invoke(
			array( $shared_short ),
			array( 'dir' => $this->temp_dir )
		);
	}

	/**
	 * GIVEN a class that does not depend on the target
	 * WHEN finding dependents
	 * THEN should not appear in the results
	 */
	public function test_does_not_include_unrelated_classes(): void {
		$uid       = uniqid();
		$related   = 'Related_Consumer_' . $uid;
		$unrelated = 'Unrelated_Class_' . $uid;

		$this->createClassWithDependency( $related, 'WPDI\\Tests\\Fixtures\\SimpleClass', 'dep' );
		$this->createStandaloneClass( $unrelated );

		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( $related, $output );
		$this->assertStringNotContainsString( $unrelated, $output );
	}

	/**
	 * GIVEN a header is expected
	 * WHEN finding dependents
	 * THEN should include the target class short name in the header
	 */
	public function test_header_shows_target_short_name(): void {
		$command = new Depends_Command();

		$command->__invoke(
			array( 'WPDI\\Tests\\Fixtures\\SimpleClass' ),
			array( 'dir' => $this->temp_dir )
		);

		$output = $this->getLogOutput();

		$this->assertStringContainsString( 'Dependents of SimpleClass', $output );
		$this->assertStringContainsString( 'WPDI\\Tests\\Fixtures', $output );
	}

	/**
	 * Create a class file with a single typed constructor dependency and load it
	 *
	 * @param string $class_name  Short class name (no namespace).
	 * @param string $dep_fqcn    FQCN of the dependency.
	 * @param string $param_name  Constructor parameter name.
	 */
	private function createClassWithDependency( string $class_name, string $dep_fqcn, string $param_name ): void {
		$file_path = $this->temp_dir . '/src/' . $class_name . '.php';
		$content   = <<<PHP
<?php
class {$class_name} {
	public function __construct( \\{$dep_fqcn} \${$param_name} ) {}
}
PHP;
		file_put_contents( $file_path, $content );

		if ( ! class_exists( $class_name, false ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Create a standalone class file with no dependencies and load it
	 *
	 * @param string $class_name Short class name.
	 */
	private function createStandaloneClass( string $class_name ): void {
		$file_path = $this->temp_dir . '/src/' . $class_name . '.php';
		file_put_contents( $file_path, "<?php\nclass {$class_name} {}" );

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
