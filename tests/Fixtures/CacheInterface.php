<?php

namespace WPDI\Tests\Fixtures;

/**
 * Sample interface for testing contextual bindings
 */
interface CacheInterface {
	public function get( string $key ): string;
}
