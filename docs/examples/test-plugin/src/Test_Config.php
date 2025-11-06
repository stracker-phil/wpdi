<?php

class Test_Config {
	private string $environment;

	public function __construct() {
		$this->environment = wp_get_environment_type();
	}

	public function get_environment() : string {
		return $this->environment;
	}
}