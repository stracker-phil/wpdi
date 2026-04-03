<?php

namespace WPDI\Tests\Fixtures;

/**
 * Null cache implementation for testing contextual binding default fallback
 */
class Null_Cache implements CacheInterface {
	public function get( string $key ): string {
		return '';
	}
}
