<?php
/**
 * WPDI Configuration
 *
 * Map interface names to concrete class names.
 * Only configure INTERFACE BINDINGS here.
 * Concrete classes are auto-discovered and autowired - keep this file minimal!
 */

return array(
	/**
	 * Interface binding: Bind interface to concrete implementation
	 */
	PaymentClientInterface::class => PayPal_Client::class,

	/**
	 * Interface binding: Bind interface to concrete implementation
	 */
	LoggerInterface::class        => WP_Logger::class,

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
