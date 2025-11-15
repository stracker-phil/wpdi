<?php

namespace WPDI\Tests\Fixtures;

use WPDI\Scope;
use WPDI\Resolver;

/**
 * Concrete implementation of Scope for testing
 */
class TestScope extends Scope {
	public bool $bootstrap_called = false;
	public $resolved_service = null;
	public ?Resolver $resolver = null;

	protected function bootstrap( Resolver $resolver ): void {
		$this->bootstrap_called = true;
		$this->resolver         = $resolver;

		// Try to resolve a simple service
		if ( $resolver->has( SimpleClass::class ) ) {
			$this->resolved_service = $resolver->get( SimpleClass::class );
		}
	}

	// Expose resolver for testing
	public function public_get( string $class ) {
		return $this->resolver->get( $class );
	}

	public function public_has( string $class ): bool {
		return $this->resolver->has( $class );
	}
}
