<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class with two parameters of the same interface type for testing contextual bindings
 */
class ClassWithContextualDeps {
	private CacheInterface $db_cache;
	private CacheInterface $file_cache;

	public function __construct( CacheInterface $db_cache, CacheInterface $file_cache ) {
		$this->db_cache   = $db_cache;
		$this->file_cache = $file_cache;
	}

	public function get_db_cache(): CacheInterface {
		return $this->db_cache;
	}

	public function get_file_cache(): CacheInterface {
		return $this->file_cache;
	}
}
