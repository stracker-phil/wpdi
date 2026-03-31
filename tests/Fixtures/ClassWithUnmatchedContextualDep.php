<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with a parameter name that doesn't match any contextual binding key
 */
class ClassWithUnmatchedContextualDep {
	private CacheInterface $unknown_cache;

	public function __construct( CacheInterface $unknown_cache ) {
		$this->unknown_cache = $unknown_cache;
	}

	public function get_cache(): CacheInterface {
		return $this->unknown_cache;
	}
}
