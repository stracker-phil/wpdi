<?php

use WPDI\Test_Service;

class Test_Application {
	private Test_Service $service;

	// The Test_Service instance is injected by WPDI:
	public function __construct( Test_Service $service ) {
		$this->service = $service;
	}

	public function run(): void {
		add_action( 'init', array( $this, 'on_init' ) );
	}

	public function on_init(): void {
		error_log( 'WPDI Test: ' . $this->service->get_message() );
	}
}