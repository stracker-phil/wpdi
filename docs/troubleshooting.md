# Troubleshooting

## Class Not Found

**Error:** `Class 'My_Service' not found`

**Causes:**

- File not in `src/` directory
- File name doesn't match class name (must be `My_Service.php`)
- Class is abstract, interface, or trait

**Fix:** Check with `wp di list`

## Interface Not Bound

**Error:** `Service 'Logger_Interface' not found`

**Fix:** Bind in `wpdi-config.php`:

```php
return array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
);
```

## Circular Dependencies

**Error:** `Circular dependency detected: ServiceA -> ServiceB -> ServiceA`

**Fix:** Extract shared logic:

```php
// ❌ Circular
class ServiceA {
    public function __construct( ServiceB $b ) {}
}
class ServiceB {
    public function __construct( ServiceA $a ) {}
}

// ✅ Fixed
class Shared_Logic {}
class ServiceA {
    public function __construct( Shared_Logic $shared ) {}
}
class ServiceB {
    public function __construct( Shared_Logic $shared ) {}
}
```

## Cannot Resolve Parameter

**Error:** `Cannot resolve parameter 'api_key' of type 'string'`

WPDI can't autowire scalars. Use a config class:

```php
// ❌ Can't autowire
class My_Service {
    public function __construct( string $api_key ) {}
}

// ✅ Autowirable
class My_Config {
    public function get_api_key(): string {
        return get_option( 'api_key', '' );
    }
}

class My_Service {
    public function __construct( My_Config $config ) {}
}
```

## Stale Options

Option changes not reflected? Don't pass `get_option()` to constructors:

```php
// ❌ Wrong - cached at instantiation
My_Service::class => fn( $r ) => new My_Service(
    get_option( 'setting' )
),

// ✅ Correct - fetch in methods
class My_Service {
    public function get_setting(): string {
        return get_option( 'setting', '' );
    }
}
```

## Configuration Not Loading

`wpdi-config.php` must be next to your Scope class:

```
my-plugin/
├── my-plugin.php          # Scope class
├── wpdi-config.php        # ✅ Correct location
└── src/
    └── wpdi-config.php    # ❌ Wrong
```

## Cache Not Writable

**Error:** `Cache directory is not writable: /path/to/plugin/cache`

The `cache/` folder needs _write_ permissions. If using a docker environment, ensure the cache folder is mounted with `:rw` permissions.

**DDEV**

In DDEV with read-only plugin mounts, add a writable mount for the cache directory in `.ddev/docker-compose.*.yaml`:

```yaml
services:
  web:
    volumes:
      - ../:/var/www/html/.ddev/wordpress/wp-content/plugins/${DDEV_PROJECT}:ro
      - ../cache:/var/www/html/.ddev/wordpress/wp-content/plugins/${DDEV_PROJECT}/cache:rw
```

## Debugging

```bash
# See all services
wp di list

# Clear cache
wp di clear
```

## Quick Checklist

- [ ] Files in `src/` directory?
- [ ] File names match class names? (`My_Service.php`)
- [ ] Interfaces bound in `wpdi-config.php`?
- [ ] No circular dependencies?
- [ ] Not passing options to constructors?
- [ ] Container initialized after `plugins_loaded`?
- [ ] Cache folder writable?
