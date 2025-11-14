# Configuration

WPDI works with zero configuration for concrete classes. You only need `wpdi-config.php` for interface bindings.

## The wpdi-config.php File

Create in your plugin/theme root:

```php
<?php
return array(
    Logger_Interface::class => fn() => new WP_Logger(),
    Cache_Interface::class => fn() => new Redis_Cache(),
);
```

## Design Philosophy

**Factories receive NO arguments** - they cannot access the container or resolve dependencies.

###Why This Limitation

Prevents the Service Locator anti-pattern:

```php
// ❌ NOT POSSIBLE (and that's good!)
'Payment_Service' => function($container) {
    return new Payment_Service(
        $container->get('Payment_Gateway'),  // Service Locator anti-pattern
        $container->get('Logger')
    );
}
```

Forces proper dependency injection:

```php
// ✅ CORRECT - use constructor injection
class Payment_Service {
    public function __construct(
        Payment_Gateway $gateway,
        Logger $logger
    ) {
        $this->gateway = $gateway;
        $this->logger = $logger;
    }
}
// No configuration needed - autowired automatically!
```

### What Belongs Here

**Only interface bindings.** If a class can be autowired, don't configure it.

Conditional logic (environment, feature flags) is business logic - handle it in a ServiceProvider class, not DI configuration.

## Interface Bindings

```php
<?php
interface Cache_Interface {
    public function get(string $key);
    public function set(string $key, $value, int $ttl = 3600): bool;
}

class Redis_Cache implements Cache_Interface {
    // Implementation
}

// wpdi-config.php
return array(
    Cache_Interface::class => fn() => new Redis_Cache(),
);
```

Now any service depending on `Cache_Interface` gets `Redis_Cache` injected automatically.

## WordPress Option Pattern

**CRITICAL:** Services are singletons - factories run once and instances are cached.

### ❌ Wrong: Passing Options to Constructor

```php
// wpdi-config.php
return array(
    'Plugin_Settings' => function() {
        return new Plugin_Settings(
            get_option('api_key', ''),     // ❌ Cached at instantiation!
            get_option('timeout', 30)      // ❌ Changes ignored!
        );
    },
);
```

**Problem:**

1. Factory runs once when first requested
2. `get_option()` returns current values
3. Instance cached as singleton
4. Admin changes option - cached instance still has old values

### ✅ Correct: Fetch Options in Methods

```php
// wpdi-config.php (often empty - let autowiring work!)
return array();

// Plugin_Settings.php
class Plugin_Settings {
    public function get_api_key(): string {
        return get_option('api_key', '');  // Fresh value every call
    }

    public function get_timeout(): int {
        return (int) get_option('timeout', 30);
    }
}
```

**Why correct:**

- Service instance cached (good for performance)
- Each method call gets fresh option value
- Option changes reflected immediately
- Follows WordPress coding standards

## Conditional Selection (Reference)

While supported, conditional logic is business logic - typically better in a ServiceProvider class:

```php
<?php
// Shown for reference - consider using ServiceProvider instead
return array(
    'Email_Service_Interface' => function() {
        $settings = get_option('email_settings', array());

        // Use option to CHOOSE implementation, not configure it
        return !empty($settings['smtp_enabled'])
            ? new SMTP_Email_Service()  // Fetches config internally
            : new WP_Mail_Service();
    },
);
```

**Note:** Using `get_option()` to **choose which class** to instantiate is acceptable. The class should fetch its own configuration internally.

## Best Practices

### ✅ Do

```php
return array(
    // Simple interface binding
    Logger_Interface::class => fn() => new WP_Logger(),

    // Another interface binding
    Cache_Interface::class => fn() => new Redis_Cache(),

    // That's it! Keep it minimal.
);
```

### ❌ Don't

```php
return array(
    // Don't instantiate outside factory
    'Bad_Config' => new Bad_Config(),  // ❌ Not lazy!

    // Don't pass options to constructors
    'Bad_Settings' => fn() => new Bad_Settings(get_option('setting')),  // ❌ Cached!

    // Don't manually create dependencies
    'Bad_Service' => function() {
        $dependency = new Some_Dependency();  // ❌ Bypasses container!
        return new Bad_Service($dependency);
    },

    // Don't return scalars
    'bad_setting' => fn() => get_option('setting'),  // ❌ DI is for objects!

    // Don't use magic strings
    'some_string_key' => fn() => new Service(),  // ❌ Use class names!
);
```

**Remember:** For dependencies between services, use constructor injection and let autowiring handle it.

## Loading Configuration

WPDI automatically loads `wpdi-config.php` from your plugin/theme directory. No code needed!

## Summary

- **Keep `wpdi-config.php` minimal** - only interface bindings
- **Let autowiring handle dependencies** - don't create them in factories
- **Fetch options in methods** - not in constructors
- **Conditional logic?** - Use ServiceProvider class, not DI config
