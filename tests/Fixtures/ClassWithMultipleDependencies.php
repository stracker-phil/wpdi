<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with multiple dependencies
 */
class ClassWithMultipleDependencies {
	private SimpleClass $first;
	private ClassWithDependency $second;

	public function __construct( SimpleClass $first, ClassWithDependency $second ) {
		$this->first  = $first;
		$this->second = $second;
	}

	public function get_first(): SimpleClass {
		return $this->first;
	}

	public function get_second(): ClassWithDependency {
		return $this->second;
	}
}
