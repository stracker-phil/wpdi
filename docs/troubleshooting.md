# Troubleshooting

Common issues and solutions.

## Class Not Found

**Error:** `Fatal error: Class 'My_Service' not found`

**Causes:**

1. File not in `src/` directory
2. File name doesn't match class name (must be `My_Service.php` for `class My_Service`; case-sensitive)
3. Class is abstract, interface, or trait

**Check what WPDI discovers:**

```bash
wp di discover
```

## Interface Not Bound

**Error:** `Service 'Logger_Interface' not found`

Interfaces need manual binding in `wpdi-config.php`:

```php
return array(
    Logger_Interface::class => fn() => new WP_Logger(),
);
```

## Circular Dependencies

**Error:** `Circular dependency detected: ServiceA -> ServiceB -> ServiceA`

**Fix:** Extract shared logic into a third service, or use WordPress hooks for communication.

```php
// ❌ Circular
class ServiceA {
    public function __construct(ServiceB $b) {}
}
class ServiceB {
    public function __construct(ServiceA $a) {}
}

// ✅ Fixed
class Shared_Logic {}
class ServiceA {
    public function __construct(Shared_Logic $shared) {}
}
class ServiceB {
    public function __construct(Shared_Logic $shared) {}
}
```

## Cannot Resolve Parameter

**Error:** `Cannot resolve parameter 'api_key' of type 'string'`

WPDI can't autowire scalar types (string, int, bool). Use a config class:

```php
// ❌ Can't autowire
class My_Service {
    public function __construct(string $api_key) {}
}

// ✅ Autowirable
class My_Config {
    public function get_api_key(): string {
        return get_option('api_key', '');
    }
}

class My_Service {
    public function __construct(My_Config $config) {}
}
```

## Stale Configuration

Option changes not reflected? You're likely passing `get_option()` to constructors:

```php
// ❌ Wrong - option cached at instantiation
'My_Service' => fn() => new My_Service(get_option('setting')),

// ✅ Correct - fetch options in methods
class My_Service {
    public function get_setting(): string {
        return get_option('setting', '');
    }
}
```

## Configuration Not Loading

`wpdi-config.php` must be in the same directory as your `Scope` class:

```
/my-plugin/
├── my-plugin.php          (Scope class here)
├── wpdi-config.php        (✅ Correct)
└── src/
    └── wpdi-config.php    (❌ Wrong)
```

## Slow Performance

**Check cache status:**

```bash
wp di discover  # Should show "Using cached container" in production
```

**Compile for production:**

```bash
wp di compile --force
```

Avoid expensive operations in factories - they run on every request.

## WordPress Functions Not Available

**Error:** `Call to undefined function get_option()`

Initialize container after WordPress loads:

```php
// ❌ Too early
new My_Plugin( __FILE__ );

// ✅ Wait for WordPress
add_action('plugins_loaded', fn() => new My_Plugin( __FILE__ ));
```

## Debugging

**See what's discovered:**

```bash
wp di discover --format=json
```

**Clear cache and retry:**

```bash
wp di clear
wp di discover
```

**Inspect container in code:**

```php
$container = new WPDI\Container();
$container->initialize(__DIR__);
error_log(print_r($container->get_registered(), true));
```

## Quick Checklist

- [ ] Files in `src/` directory?
- [ ] File names match class names exactly? (`My_Service.php`)
- [ ] Interfaces bound in `wpdi-config.php`?
- [ ] No circular dependencies?
- [ ] Not passing options to constructors?
- [ ] Container initialized after `plugins_loaded`?
