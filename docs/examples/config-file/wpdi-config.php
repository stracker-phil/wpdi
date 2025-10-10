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
		$environment = get_option( 'payment_environment', 'sandbox' );

		return 'live' === $environment
			? new PayPal_Live_Client()
			: new PayPal_Sandbox_Client();
	},

	LoggerInterface::class => function () {
		$log_level = get_option( 'payment_log_level', 'info' );

		return new WP_Logger( $log_level );
	},

	// WordPress-specific factories - always fresh values
	Payment_Config::class  => function () {
		return new Payment_Config(
			get_option( 'paypal_client_id', '' ),
			get_option( 'paypal_client_secret', '' ),
			get_option( 'paypal_environment', 'sandbox' )
		);
	},

	Payment_Settings::class => function () {
		return new Payment_Settings(
			get_option( 'payment_currencies', array( 'USD', 'EUR' ) ),
			(int) get_option( 'payment_retry_attempts', 3 ),
			(float) get_option( 'payment_minimum_amount', 0.01 )
		);
	},
);