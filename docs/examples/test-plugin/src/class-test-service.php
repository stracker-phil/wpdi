<?php

use WPDI\Test_Config;

class Test_Service {
	private Test_Config $config;

	// The Test_Config instance is injected by WPDI:
	public function __construct( Test_Config $config ) {
		$this->config = $config;
	}

	public function get_message(): string {
		return "WPDI is working! Environment: {$this->config->get_environment()}";
	}
}