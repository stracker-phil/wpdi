<?php

class Test_Config {
	/**
	 * Get WordPress environment type.
	 *
	 * Note: This calls wp_get_environment_type() on each access to get fresh value.
	 * Since this service is a singleton, storing the value in a property would cache
	 * it at instantiation, which is incorrect for WordPress option/config values.
	 */
	public function get_environment(): string {
		return wp_get_environment_type();
	}
}