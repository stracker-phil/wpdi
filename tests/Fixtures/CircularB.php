<?php

namespace WPDI\Tests\Fixtures;

/**
 * Test fixture for circular dependency detection
 * CircularB depends on CircularA, which depends back on CircularB
 */
class CircularB {
    private CircularA $dependency;

    public function __construct(CircularA $dependency) {
        $this->dependency = $dependency;
    }

    public function get_dependency(): CircularA {
        return $this->dependency;
    }
}
