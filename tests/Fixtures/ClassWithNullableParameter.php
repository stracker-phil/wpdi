<?php

namespace WPDI\Tests\Fixtures;

/**
 * Test fixture with nullable parameter WITHOUT default value
 * This ensures we test the allowsNull() path (line 233) instead of isDefaultValueAvailable()
 */
class ClassWithNullableParameter {
    private $optional_dependency;

    // Note: NO default value here, just nullable type
    public function __construct(?LoggerInterface $optional) {
        $this->optional_dependency = $optional;
    }

    public function has_dependency(): bool {
        return null !== $this->optional_dependency;
    }

    public function get_dependency(): ?LoggerInterface {
        return $this->optional_dependency;
    }
}
