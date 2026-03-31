<?php

namespace WPDI\Tests\Fixtures;

/**
 * Database cache implementation for testing contextual bindings
 */
class DB_Cache implements CacheInterface {
	public function get( string $key ): string {
		return 'db:' . $key;
	}
}
