<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Exceptions\WPDI_Exception;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Exception;

class ExceptionsTest extends TestCase {

	// ========================================
	// Container_Exception Tests
	// ========================================

	/**
	 * GIVEN a Container_Exception instance
	 * WHEN examining its type hierarchy
	 * THEN it extends Exception and implements PSR-11 ContainerExceptionInterface
	 */
	public function test_container_exception_implements_correct_interfaces(): void {
		$exception = new Container_Exception( 'Test message' );

		$this->assertInstanceOf( Exception::class, $exception );
		$this->assertInstanceOf( ContainerExceptionInterface::class, $exception );
	}

	/**
	 * GIVEN Container_Exception is thrown
	 * WHEN caught by different exception types
	 * THEN it can be caught as both Container_Exception and PSR-11 interface
	 */
	public function test_container_exception_can_be_thrown_and_caught(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'Test error message' );

		throw new Container_Exception( 'Test error message' );
	}

	/**
	 * GIVEN Container_Exception thrown
	 * WHEN caught as PSR-11 ContainerExceptionInterface
	 * THEN the catch block executes correctly
	 */
	public function test_container_exception_can_be_caught_as_psr11_interface(): void {
		try {
			throw new Container_Exception( 'Test message' );
		} catch ( ContainerExceptionInterface $e ) {
			$this->assertInstanceOf( Container_Exception::class, $e );
			$this->assertEquals( 'Test message', $e->getMessage() );

			return;
		}

		$this->fail( 'Exception was not caught' );
	}

	/**
	 * GIVEN a Container_Exception with message, code, and previous exception
	 * WHEN examining exception properties
	 * THEN all properties are correctly preserved
	 *
	 * @dataProvider exception_properties_provider
	 */
	public function test_container_exception_preserves_properties(
		string $message,
		int $code,
		?Exception $previous
	): void {
		$exception = new Container_Exception( $message, $code, $previous );

		$this->assertEquals( $message, $exception->getMessage() );
		$this->assertEquals( $code, $exception->getCode() );
		$this->assertSame( $previous, $exception->getPrevious() );
	}

	public function exception_properties_provider(): array {
		$previous = new Exception( 'Previous exception' );

		return array(
			'with message only'                => array( 'Custom error message', 0, null ),
			'with message and code'            => array( 'Test', 123, null ),
			'with message, code, and previous' => array( 'Current exception', 456, $previous ),
		);
	}

	// ========================================
	// Not_Found_Exception Tests
	// ========================================

	/**
	 * GIVEN a Not_Found_Exception instance
	 * WHEN examining its type hierarchy
	 * THEN it extends Exception and implements PSR-11 NotFoundExceptionInterface
	 */
	public function test_not_found_exception_implements_correct_interfaces(): void {
		$exception = new Not_Found_Exception( 'Test message' );

		$this->assertInstanceOf( Exception::class, $exception );
		$this->assertInstanceOf( NotFoundExceptionInterface::class, $exception );
	}

	/**
	 * GIVEN Not_Found_Exception is thrown
	 * WHEN caught by different exception types
	 * THEN it can be caught as both Not_Found_Exception and PSR-11 NotFoundExceptionInterface
	 */
	public function test_not_found_exception_can_be_thrown_and_caught(): void {
		$this->expectException( Not_Found_Exception::class );
		$this->expectExceptionMessage( 'Service not found' );

		throw new Not_Found_Exception( 'Service not found' );
	}

	/**
	 * GIVEN Not_Found_Exception thrown
	 * WHEN caught as PSR-11 NotFoundExceptionInterface
	 * THEN the catch block executes correctly
	 */
	public function test_not_found_exception_can_be_caught_as_psr11_interface(): void {
		try {
			throw new Not_Found_Exception( 'Service missing' );
		} catch ( NotFoundExceptionInterface $e ) {
			$this->assertInstanceOf( Not_Found_Exception::class, $e );
			$this->assertEquals( 'Service missing', $e->getMessage() );

			return;
		}

		$this->fail( 'Exception was not caught' );
	}

	/**
	 * GIVEN a Not_Found_Exception with message, code, and previous exception
	 * WHEN examining exception properties
	 * THEN all properties are correctly preserved (reuses Container exception provider)
	 *
	 * @dataProvider exception_properties_provider
	 */
	public function test_not_found_exception_preserves_properties(
		string $message,
		int $code,
		?Exception $previous
	): void {
		$exception = new Not_Found_Exception( $message, $code, $previous );

		$this->assertEquals( $message, $exception->getMessage() );
		$this->assertEquals( $code, $exception->getCode() );
		$this->assertSame( $previous, $exception->getPrevious() );
	}

	// ========================================
	// PSR-11 Compliance Tests
	// ========================================

	/**
	 * GIVEN PSR-11 exception interfaces define a hierarchy
	 * WHEN examining Not_Found_Exception
	 * THEN it implements both NotFoundExceptionInterface and ContainerExceptionInterface
	 */
	public function test_exceptions_follow_psr11_hierarchy(): void {
		// NotFoundExceptionInterface should extend ContainerExceptionInterface
		$notFound = new Not_Found_Exception( 'Test' );

		// Not_Found_Exception should be catchable as both interfaces
		$this->assertInstanceOf( NotFoundExceptionInterface::class, $notFound );
		$this->assertInstanceOf( ContainerExceptionInterface::class, $notFound );
	}

	/**
	 * GIVEN Not_Found_Exception is thrown
	 * WHEN caught as parent ContainerExceptionInterface
	 * THEN the catch succeeds per PSR-11 exception hierarchy
	 */
	public function test_container_exception_can_catch_not_found_exception(): void {
		try {
			throw new Not_Found_Exception( 'Service not found' );
		} catch ( ContainerExceptionInterface $e ) {
			$this->assertInstanceOf( Not_Found_Exception::class, $e );

			return;
		}

		$this->fail( 'Exception was not caught by parent interface' );
	}

	/**
	 * GIVEN Container_Exception and Not_Found_Exception exist
	 * WHEN checking their type implementations
	 * THEN they are distinguishable by NotFoundExceptionInterface
	 */
	public function test_exceptions_are_distinguishable(): void {
		$containerEx = new Container_Exception( 'Container error' );
		$notFoundEx  = new Not_Found_Exception( 'Not found error' );

		$this->assertNotInstanceOf( NotFoundExceptionInterface::class, $containerEx );
		$this->assertInstanceOf( NotFoundExceptionInterface::class, $notFoundEx );
	}

	// ========================================
	// Real-world Usage Tests
	// ========================================

	/**
	 * GIVEN Container_Exception thrown in a try block
	 * WHEN caught in a catch block
	 * THEN the catch executes and exception details are accessible
	 */
	public function test_exceptions_work_in_try_catch_blocks(): void {
		$caught = false;

		try {
			throw new Container_Exception( 'Autowiring failed' );
		} catch ( Container_Exception $e ) {
			$caught = true;
			$this->assertEquals( 'Autowiring failed', $e->getMessage() );
		}

		$this->assertTrue( $caught );
	}

	/**
	 * GIVEN multiple catch blocks for different exception types
	 * WHEN an exception is thrown
	 * THEN only the matching catch block executes
	 */
	public function test_can_differentiate_exceptions_in_catch_blocks(): void {
		$notFoundCaught  = false;
		$containerCaught = false;

		try {
			throw new Not_Found_Exception( 'Service missing' );
		} catch ( Not_Found_Exception $e ) {
			$notFoundCaught = true;
		} catch ( Container_Exception $e ) {
			$containerCaught = true;
		}

		$this->assertTrue( $notFoundCaught );
		$this->assertFalse( $containerCaught );
	}

	/**
	 * GIVEN an exception is thrown from a method
	 * WHEN examining the exception's stack trace
	 * THEN the trace includes the originating method name
	 */
	public function test_stack_trace_is_preserved(): void {
		try {
			$this->throwContainerException();
		} catch ( Container_Exception $e ) {
			$trace = $e->getTrace();
			$this->assertNotEmpty( $trace );

			// Should contain reference to throwContainerException method
			$traceString = $e->getTraceAsString();
			$this->assertStringContainsString( 'throwContainerException', $traceString );
		}
	}

	private function throwContainerException(): void {
		throw new Container_Exception( 'Test exception with trace' );
	}

	// ========================================
	// Exception Hierarchy Tests
	// ========================================

	/**
	 * GIVEN WPDI exception hierarchy (WPDI_Exception -> Container_Exception -> specific exceptions)
	 * WHEN examining exception inheritance
	 * THEN each exception correctly extends its parent in the hierarchy
	 *
	 * @dataProvider exception_hierarchy_provider
	 */
	public function test_exception_hierarchy(
		string $exception_class,
		array $expected_parent_types
	): void {
		$exception = new $exception_class( 'Test message' );

		foreach ( $expected_parent_types as $parent_type ) {
			$this->assertInstanceOf(
				$parent_type,
				$exception,
				"{$exception_class} should be instance of {$parent_type}"
			);
		}
	}

	public function exception_hierarchy_provider(): array {
		return array(
			'WPDI_Exception is base exception'                          => array(
				WPDI_Exception::class,
				array( Exception::class ),
			),
			'Container_Exception extends WPDI_Exception'                => array(
				Container_Exception::class,
				array(
					WPDI_Exception::class,
					Exception::class,
					ContainerExceptionInterface::class,
				),
			),
			'Not_Found_Exception extends Container_Exception'           => array(
				Not_Found_Exception::class,
				array(
					Container_Exception::class,
					WPDI_Exception::class,
					Exception::class,
					ContainerExceptionInterface::class,
					NotFoundExceptionInterface::class,
				),
			),
			'Circular_Dependency_Exception extends Container_Exception' => array(
				Circular_Dependency_Exception::class,
				array(
					Container_Exception::class,
					WPDI_Exception::class,
					Exception::class,
					ContainerExceptionInterface::class,
				),
			),
		);
	}

	/**
	 * GIVEN Circular_Dependency_Exception can be caught at multiple levels
	 * WHEN thrown and caught by different exception types
	 * THEN all catch blocks work correctly (WPDI_Exception, Container_Exception, PSR-11)
	 *
	 * @dataProvider circular_dependency_catch_provider
	 */
	public function test_circular_dependency_exception_catch_hierarchy(
		string $catch_type
	): void {
		$caughtException = null;

		try {
			throw new Circular_Dependency_Exception( 'Circular dependency' );
		} catch ( Exception $e ) {
			// Catch as generic Exception to verify it's catchable
			if ( is_a( $e, $catch_type ) ) {
				$caughtException = $e;
			}
		}

		$this->assertInstanceOf( Circular_Dependency_Exception::class, $caughtException );
		$this->assertInstanceOf( $catch_type, $caughtException );
	}

	public function circular_dependency_catch_provider(): array {
		return array(
			'can catch as WPDI_Exception'              => array( WPDI_Exception::class ),
			'can catch as Container_Exception'         => array( Container_Exception::class ),
			'can catch as ContainerExceptionInterface' => array( ContainerExceptionInterface::class ),
		);
	}

	/**
	 * GIVEN the base WPDI_Exception class
	 * WHEN used to catch any WPDI library exception
	 * THEN all library exceptions are catchable
	 */
	public function test_can_catch_all_wpdi_exceptions_with_base_class(): void {
		$caughtException = null;

		try {
			throw new Circular_Dependency_Exception( 'Circular dependency' );
		} catch ( WPDI_Exception $e ) {
			$caughtException = $e;
		}

		$this->assertInstanceOf( Circular_Dependency_Exception::class, $caughtException );
		$this->assertEquals( 'Circular dependency', $caughtException->getMessage() );
	}
}
