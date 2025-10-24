<?php

namespace WPDI\Tests\Fixtures;

use WPDI\Scope;

/**
 * Concrete implementation of Scope for testing
 */
class TestScope extends Scope {
    public bool $bootstrap_called = false;
    public $resolved_service = null;

    protected function bootstrap(): void {
        $this->bootstrap_called = true;

        // Try to resolve a simple service
        if ($this->has(SimpleClass::class)) {
            $this->resolved_service = $this->get(SimpleClass::class);
        }
    }

    // Expose protected methods for testing
    public function public_get(string $class) {
        return $this->get($class);
    }

    public function public_has(string $class): bool {
        return $this->has($class);
    }

    public function public_get_base_path(): string {
        return $this->get_base_path();
    }
}
