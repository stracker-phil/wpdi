<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with chained dependency for testing transitive discovery.
 * Chain: ClassWithChainedDependency -> ClassWithDependency -> SimpleClass
 */
class ClassWithChainedDependency {
	private ClassWithDependency $dependency;

	public function __construct( ClassWithDependency $dependency ) {
		$this->dependency = $dependency;
	}

	public function get_message(): string {
		return 'Chained: ' . $this->dependency->get_message();
	}
}
