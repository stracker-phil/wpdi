<?php
/**
 * WPDI Configuration
 *
 * Factory functions receive NO ARGUMENTS - they cannot access the container.
 * Only configure INTERFACE BINDINGS here.
 * Concrete classes are auto-discovered and autowired - keep this file minimal!
 */

return array(
	/**
	 * Interface binding: Bind interface to concrete implementation
	 */
	PaymentClientInterface::class => function () {
		return new PayPal_Client();
	},

	/**
	 * Interface binding: Bind interface to concrete implementation
	 */
	LoggerInterface::class        => function () {
		return new WP_Logger();
	},

	/**
	 * That's it! Keep this file minimal.
	 *
	 * Concrete classes (Payment_Settings, Payment_Config, etc.) are auto-discovered
	 * and autowired automatically - no configuration needed!
	 *
	 * Need conditional logic? Create a ServiceProvider class instead of
	 * adding business logic to this configuration file.
	 */
);
