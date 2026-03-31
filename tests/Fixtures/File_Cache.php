<?php

namespace WPDI\Tests\Fixtures;

/**
 * File cache implementation for testing contextual bindings
 */
class File_Cache implements CacheInterface {
	public function get( string $key ): string {
		return 'file:' . $key;
	}
}
