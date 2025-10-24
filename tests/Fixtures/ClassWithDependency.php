<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with a single dependency
 */
class ClassWithDependency {
    private SimpleClass $dependency;

    public function __construct(SimpleClass $dependency) {
        $this->dependency = $dependency;
    }

    public function get_dependency(): SimpleClass {
        return $this->dependency;
    }

    public function get_message(): string {
        return 'Message from dependency: ' . $this->dependency->get_message();
    }
}
