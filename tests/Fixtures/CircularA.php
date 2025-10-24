<?php

namespace WPDI\Tests\Fixtures;

/**
 * Test fixture for circular dependency detection
 * CircularA depends on CircularB, which depends back on CircularA
 */
class CircularA {
    private CircularB $dependency;

    public function __construct(CircularB $dependency) {
        $this->dependency = $dependency;
    }

    public function get_dependency(): CircularB {
        return $this->dependency;
    }
}
