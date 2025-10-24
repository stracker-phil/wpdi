<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with optional dependency
 */
class ClassWithOptionalDependency {
    private ?SimpleClass $dependency;

    public function __construct(?SimpleClass $dependency = null) {
        $this->dependency = $dependency;
    }

    public function get_dependency(): ?SimpleClass {
        return $this->dependency;
    }

    public function has_dependency(): bool {
        return $this->dependency !== null;
    }
}
