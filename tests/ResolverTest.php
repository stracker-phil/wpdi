<?php

namespace WPDI\Tests;

use PHPUnit\Framework\TestCase;
use WPDI\Container;
use WPDI\Resolver;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Tests\Fixtures\SimpleClass;
use WPDI\Tests\Fixtures\ClassWithDependency;
use ReflectionMethod;
use ReflectionClass;

/**
 * @covers \WPDI\Resolver
 */
class ResolverTest extends TestCase {

	private Container $container;
	private Resolver $resolver;

	protected function setUp(): void {
		$this->container = new Container();
		$this->resolver  = new Resolver( $this->container );
	}

	protected function tearDown(): void {
		$this->container->clear();
	}

	// ========================================
	// get() Method Tests
	// ========================================

	/**
	 * GIVEN a container with a bound service
	 * WHEN resolver->get() is called
	 * THEN it returns the service from container
	 */
	public function test_get_delegates_to_container(): void {
		$this->container->bind( SimpleClass::class );

		$instance = $this->resolver->get( SimpleClass::class );

		$this->assertInstanceOf( SimpleClass::class, $instance );
		$this->assertEquals( 'Hello from SimpleClass', $instance->get_message() );
	}

	/**
	 * GIVEN a container with autowireable classes
	 * WHEN resolver->get() is called for a class with dependencies
	 * THEN it resolves the full dependency chain
	 */
	public function test_get_resolves_dependencies(): void {
		$instance = $this->resolver->get( ClassWithDependency::class );

		$this->assertInstanceOf( ClassWithDependency::class, $instance );
		$this->assertEquals( 'Message from dependency: Hello from SimpleClass', $instance->get_message() );
	}

	/**
	 * GIVEN a container
	 * WHEN resolver->get() is called for non-existent class
	 * THEN it throws Not_Found_Exception
	 */
	public function test_get_throws_not_found_exception(): void {
		$this->expectException( Not_Found_Exception::class );

		$this->resolver->get( 'NonExistent\\Class' );
	}

	/**
	 * GIVEN a container with singleton services
	 * WHEN resolver->get() is called multiple times
	 * THEN it returns the same cached instance
	 */
	public function test_get_returns_singleton_instances(): void {
		$this->container->bind( SimpleClass::class );

		$instance1 = $this->resolver->get( SimpleClass::class );
		$instance2 = $this->resolver->get( SimpleClass::class );

		$this->assertSame( $instance1, $instance2 );
	}

	// ========================================
	// has() Method Tests
	// ========================================

	/**
	 * GIVEN a container with a bound service
	 * WHEN resolver->has() is called
	 * THEN it returns true
	 */
	public function test_has_returns_true_for_bound_service(): void {
		$this->container->bind( SimpleClass::class );

		$result = $this->resolver->has( SimpleClass::class );

		$this->assertTrue( $result );
	}

	/**
	 * GIVEN a container
	 * WHEN resolver->has() is called for autowireable class
	 * THEN it returns true
	 */
	public function test_has_returns_true_for_autowireable_class(): void {
		$result = $this->resolver->has( SimpleClass::class );

		$this->assertTrue( $result );
	}

	/**
	 * GIVEN a container
	 * WHEN resolver->has() is called for non-existent class
	 * THEN it returns false
	 */
	public function test_has_returns_false_for_nonexistent_class(): void {
		$result = $this->resolver->has( 'NonExistent\\Class' );

		$this->assertFalse( $result );
	}

	// ========================================
	// Limited API Tests
	// ========================================

	/**
	 * GIVEN a Resolver instance
	 * WHEN checking available methods
	 * THEN only get() and has() are public
	 */
	public function test_exposes_only_limited_api(): void {
		$reflection = new ReflectionClass( Resolver::class );
		$methods    = $reflection->getMethods( ReflectionMethod::IS_PUBLIC );

		$method_names = array_map( fn( $m ) => $m->getName(), $methods );

		$this->assertContains( 'get', $method_names );
		$this->assertContains( 'has', $method_names );
		$this->assertContains( '__construct', $method_names );
		$this->assertCount( 3, $method_names );
	}

	/**
	 * GIVEN a Resolver instance
	 * WHEN checking for bind method
	 * THEN it does not exist (limited API)
	 */
	public function test_does_not_expose_bind_method(): void {
		$this->assertFalse( method_exists( $this->resolver, 'bind' ) );
	}

	/**
	 * GIVEN a Resolver instance
	 * WHEN checking for clear method
	 * THEN it does not exist (limited API)
	 */
	public function test_does_not_expose_clear_method(): void {
		$this->assertFalse( method_exists( $this->resolver, 'clear' ) );
	}

	// ========================================
	// Container::resolver() Integration Tests
	// ========================================

	/**
	 * GIVEN a container
	 * WHEN Container::resolver() is called multiple times
	 * THEN it returns the same cached Resolver instance
	 */
	public function test_container_returns_cached_resolver(): void {
		$resolver1 = $this->container->resolver();
		$resolver2 = $this->container->resolver();

		$this->assertSame( $resolver1, $resolver2 );
		$this->assertInstanceOf( Resolver::class, $resolver1 );
	}

	/**
	 * GIVEN a factory that receives resolver
	 * WHEN service is resolved
	 * THEN factory can use resolver to get dependencies
	 */
	public function test_factory_can_use_resolver(): void {
		$this->container->bind(
			ClassWithDependency::class,
			fn( Resolver $r ) => new ClassWithDependency( $r->get( SimpleClass::class ) )
		);

		$instance = $this->resolver->get( ClassWithDependency::class );

		$this->assertInstanceOf( ClassWithDependency::class, $instance );
		$this->assertEquals( 'Message from dependency: Hello from SimpleClass', $instance->get_message() );
	}
}
