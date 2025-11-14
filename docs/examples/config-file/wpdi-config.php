<?php
/**
 * WPDI Configuration
 *
 * This file contains interface bindings and WordPress-specific factories.
 * Concrete classes are auto-discovered and don't need configuration.
 */

return array(
	// Interface bindings - these need manual configuration
	PaymentClientInterface::class => function () {
		// Environment is static during one request (intentional).
		$environment = get_option( 'payment_environment', 'sandbox' );

		return 'live' === $environment
			? new PayPal_Live_Client()
			: new PayPal_Sandbox_Client();
	},

	LoggerInterface::class => function () {
		// Log level does not change for the rest of this request (intentional).
		$log_level = get_option( 'payment_log_level', 'info' );

		return new WP_Logger( $log_level );
	},

	Payment_Settings::class => function () {
		return new Payment_Settings();
	},

	Payment_Config::class   => function () {
		return new Payment_Config();
	},
);
