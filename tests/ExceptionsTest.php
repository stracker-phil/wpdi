<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Exceptions\WPDI_Exception;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ExceptionsTest extends TestCase {

	// ========================================
	// Container_Exception Tests
	// ========================================

	public function test_container_exception_is_instance_of_exception(): void {
		$exception = new Container_Exception( 'Test message' );

		$this->assertInstanceOf( \Exception::class, $exception );
	}

	public function test_container_exception_implements_psr11_interface(): void {
		$exception = new Container_Exception( 'Test message' );

		$this->assertInstanceOf( ContainerExceptionInterface::class, $exception );
	}

	public function test_container_exception_can_be_thrown(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'Test error message' );

		throw new Container_Exception( 'Test error message' );
	}

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

	public function test_container_exception_preserves_message(): void {
		$message   = 'Custom error message';
		$exception = new Container_Exception( $message );

		$this->assertEquals( $message, $exception->getMessage() );
	}

	public function test_container_exception_preserves_code(): void {
		$code      = 123;
		$exception = new Container_Exception( 'Test', $code );

		$this->assertEquals( $code, $exception->getCode() );
	}

	public function test_container_exception_preserves_previous_exception(): void {
		$previous  = new \Exception( 'Previous exception' );
		$exception = new Container_Exception( 'Current exception', 0, $previous );

		$this->assertSame( $previous, $exception->getPrevious() );
	}

	// ========================================
	// Not_Found_Exception Tests
	// ========================================

	public function test_not_found_exception_is_instance_of_exception(): void {
		$exception = new Not_Found_Exception( 'Test message' );

		$this->assertInstanceOf( \Exception::class, $exception );
	}

	public function test_not_found_exception_implements_psr11_interface(): void {
		$exception = new Not_Found_Exception( 'Test message' );

		$this->assertInstanceOf( NotFoundExceptionInterface::class, $exception );
	}

	public function test_not_found_exception_can_be_thrown(): void {
		$this->expectException( Not_Found_Exception::class );
		$this->expectExceptionMessage( 'Service not found' );

		throw new Not_Found_Exception( 'Service not found' );
	}

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

	public function test_not_found_exception_preserves_message(): void {
		$message   = 'Service XYZ not found';
		$exception = new Not_Found_Exception( $message );

		$this->assertEquals( $message, $exception->getMessage() );
	}

	public function test_not_found_exception_preserves_code(): void {
		$code      = 404;
		$exception = new Not_Found_Exception( 'Not found', $code );

		$this->assertEquals( $code, $exception->getCode() );
	}

	public function test_not_found_exception_preserves_previous_exception(): void {
		$previous  = new \Exception( 'Previous exception' );
		$exception = new Not_Found_Exception( 'Current exception', 0, $previous );

		$this->assertSame( $previous, $exception->getPrevious() );
	}

	// ========================================
	// PSR-11 Compliance Tests
	// ========================================

	public function test_exceptions_follow_psr11_hierarchy(): void {
		// NotFoundExceptionInterface should extend ContainerExceptionInterface
		$notFound = new Not_Found_Exception( 'Test' );

		// Not_Found_Exception should be catchable as both interfaces
		$this->assertInstanceOf( NotFoundExceptionInterface::class, $notFound );
		$this->assertInstanceOf( ContainerExceptionInterface::class, $notFound );
	}

	public function test_container_exception_can_catch_not_found_exception(): void {
		try {
			throw new Not_Found_Exception( 'Service not found' );
		} catch ( ContainerExceptionInterface $e ) {
			$this->assertInstanceOf( Not_Found_Exception::class, $e );

			return;
		}

		$this->fail( 'Exception was not caught by parent interface' );
	}

	public function test_exceptions_are_distinguishable(): void {
		$containerEx = new Container_Exception( 'Container error' );
		$notFoundEx  = new Not_Found_Exception( 'Not found error' );

		$this->assertNotInstanceOf( NotFoundExceptionInterface::class, $containerEx );
		$this->assertInstanceOf( NotFoundExceptionInterface::class, $notFoundEx );
	}

	// ========================================
	// Real-world Usage Tests
	// ========================================

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

	public function test_wpdi_exception_is_instance_of_exception(): void {
		$exception = new WPDI_Exception( 'Test message' );

		$this->assertInstanceOf( \Exception::class, $exception );
	}

	public function test_container_exception_extends_wpdi_exception(): void {
		$exception = new Container_Exception( 'Test message' );

		$this->assertInstanceOf( WPDI_Exception::class, $exception );
		$this->assertInstanceOf( \Exception::class, $exception );
	}

	public function test_not_found_exception_extends_container_exception(): void {
		$exception = new Not_Found_Exception( 'Test message' );

		$this->assertInstanceOf( Container_Exception::class, $exception );
		$this->assertInstanceOf( WPDI_Exception::class, $exception );
		$this->assertInstanceOf( \Exception::class, $exception );
	}

	public function test_circular_dependency_exception_extends_container_exception(): void {
		$exception = new Circular_Dependency_Exception( 'Test message' );

		$this->assertInstanceOf( Container_Exception::class, $exception );
		$this->assertInstanceOf( WPDI_Exception::class, $exception );
		$this->assertInstanceOf( \Exception::class, $exception );
	}

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

	public function test_can_catch_circular_dependency_with_container_exception(): void {
		$caughtException = null;

		try {
			throw new Circular_Dependency_Exception( 'Circular dependency' );
		} catch ( Container_Exception $e ) {
			$caughtException = $e;
		}

		$this->assertInstanceOf( Circular_Dependency_Exception::class, $caughtException );
	}

	public function test_can_catch_circular_dependency_with_psr11_interface(): void {
		$caughtException = null;

		try {
			throw new Circular_Dependency_Exception( 'Circular dependency' );
		} catch ( ContainerExceptionInterface $e ) {
			$caughtException = $e;
		}

		$this->assertInstanceOf( Circular_Dependency_Exception::class, $caughtException );
	}

	public function test_circular_dependency_exception_is_psr11_compliant(): void {
		$exception = new Circular_Dependency_Exception( 'Test message' );

		$this->assertInstanceOf( ContainerExceptionInterface::class, $exception );
	}
}
