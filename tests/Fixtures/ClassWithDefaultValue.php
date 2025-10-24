<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with default parameter values
 */
class ClassWithDefaultValue {
	private string $name;
	private int $count;

	public function __construct( string $name = 'default', int $count = 10 ) {
		$this->name  = $name;
		$this->count = $count;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_count(): int {
		return $this->count;
	}
}
