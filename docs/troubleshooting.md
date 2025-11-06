# Troubleshooting Guide

Common issues and solutions when using WPDI.

## Class Discovery Issues

### Problem: Class Not Found

```
Fatal error: Class 'My_Service' not found
```

**Common Causes:**

1. **File not in `src/` directory**
   ```bash
   # ❌ Wrong location
   /my-plugin/includes/My_Service.php

   # ✅ Correct location
   /my-plugin/src/My_Service.php
   ```

2. **Incorrect file naming**
   ```bash
   # ❌ Wrong naming (doesn't match class name)
   /src/class-my-service.php  (old WordPress style - no longer used)
   /src/my-service.php
   /src/MyService.php         (wrong case if class is My_Service)

   # ✅ Correct naming (PSR-4: matches class name exactly)
   /src/My_Service.php
   ```

3. **Class name doesn't match WordPress conventions**
   ```php
   // ❌ Wrong class name
   class MyService {}
   class myService {}
   
   // ✅ Correct class name
   class My_Service {}
   ```

**Solution:**

```bash
# Check what WPDI actually discovers
wp wpdi discover

# Verify your file structure
find src/ -name "*.php" -type f
```

### Problem: Interface Not Bound

```
Service 'Logger_Interface' not found
```

**Cause:** Interface needs manual binding in `wpdi-config.php`

**Solution:**

```php
<?php
// wpdi-config.php
return array(
    'Logger_Interface' => function() {
        return new WP_Logger();
    },
);
```

## Dependency Injection Issues

### Problem: Circular Dependencies

```
Cannot resolve parameter 'service_a' - circular dependency detected
```

**Example Circular Dependency:**

```php
class Service_A {
    public function __construct( Service_B $b ) {}
}

class Service_B {
    public function __construct( Service_A $a ) {} // ❌ Circular!
}
```

**Solutions:**

1. **Use Interfaces to Break Cycles**
   ```php
   interface Service_A_Interface {
       public function do_something(): void;
   }
   
   class Service_A implements Service_A_Interface {
       public function __construct( Service_B $b ) {}
   }
   
   class Service_B {
       public function __construct( Service_A_Interface $a ) {} // ✅ Fixed
   }
   ```

2. **Refactor to Remove Dependency**
   ```php
   class Service_A {
       public function __construct( Shared_Dependency $shared ) {}
   }
   
   class Service_B {
       public function __construct( Shared_Dependency $shared ) {} // ✅ Both use shared
   }
   ```

### Problem: Cannot Resolve Parameter

```
Cannot resolve parameter 'config' of type 'string' in class 'My_Service'
```

**Cause:** WPDI can't auto-wire scalar types (string, int, bool, array)

**Wrong:**

```php
class My_Service {
    public function __construct( string $api_key ) {} // ❌ Can't autowire string
}
```

**Solution - Use Configuration Class:**

```php
class My_Config {
    public function __construct( string $api_key ) {
        $this->api_key = $api_key;
    }
    
    public function get_api_key(): string {
        return $this->api_key;
    }
}

class My_Service {
    public function __construct( My_Config $config ) {} // ✅ Can autowire class
}

// wpdi-config.php
return array(
    'My_Config' => function() {
        return new My_Config( get_option( 'api_key' ) );
    },
);
```

## Configuration Issues

### Problem: Stale Configuration Values

```php
// Configuration cached at container creation time
return array(
    'My_Service' => new My_Service( get_option( 'setting' ) ), // ❌ Cached!
);
```

**Problem:** Option value is cached when container loads, not when service is used.

**Solution - Use Factory Functions:**

```php
return array(
    'My_Service' => function() {
        return new My_Service( get_option( 'setting' ) ); // ✅ Fresh each time
    },
);
```

### Problem: Configuration Not Loading

WPDI looks for `wpdi-config.php` in the same directory as your `Scope` class.

**Check File Location:**

```bash
# If your plugin file is:
/wp-content/plugins/my-plugin/my-plugin.php

# Config should be:
/wp-content/plugins/my-plugin/wpdi-config.php

# NOT:
/wp-content/plugins/my-plugin/config/wpdi-config.php  # ❌ Wrong
/wp-content/plugins/my-plugin/src/wpdi-config.php    # ❌ Wrong
```

## Performance Issues

### Problem: Slow Container Initialization

**Symptoms:**

- Long page load times
- High memory usage
- Timeout errors

**Diagnosis:**

```bash
# Check if you're in production mode
wp wpdi discover
# Should show: "Using cached container" in production

# Check cache file exists
ls -la cache/wpdi-container.php
```

**Solutions:**

1. **Compile for Production**
   ```bash
   wp wpdi compile --force
   ```

2. **Optimize Factory Functions**
   ```php
   // ❌ Slow - expensive computation on every request
   'Heavy_Service' => function() {
       $data = expensive_api_call(); // Called every time
       return new Heavy_Service( $data );
   }
   
   // ✅ Fast - lazy loading
   'Heavy_Service' => function() {
       return new Heavy_Service(); // Service handles expensive ops internally
   }
   ```

### Problem: Memory Usage

**Check Container Size:**

```bash
wp wpdi discover --format=json | jq 'length'
# Should be reasonable (< 100 services for most plugins)
```

**Reduce Memory Usage:**

- Use non-singleton services for large objects
- Avoid caching expensive data in factories
- Remove unused service bindings

## WordPress Integration Issues

### Problem: WordPress Functions Not Available

```
Fatal error: Call to undefined function get_option()
```

**Cause:** Container initialization happens too early in WordPress lifecycle.

**Wrong:**

```php
// In plugin main file
require_once 'wpdi/init.php';
new My_Plugin(); // ❌ Too early - WordPress not ready
```

**Solution:**

```php
// Wait for WordPress to load
add_action( 'plugins_loaded', function() {
    require_once 'wpdi/init.php';
    new My_Plugin(); // ✅ WordPress functions available
} );
```

### Problem: Hooks Not Working

**Check Hook Registration:**

```php
class My_Application {
    public function run(): void {
        // ✅ Good - uses array callback
        add_action( 'init', array( $this, 'on_init' ) );
        
        // ❌ Problematic - string callback
        add_action( 'init', 'my_function' );
    }
}
```

## Debugging Techniques

### Enable Debug Mode

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WPDI_DEBUG', true ); // If implemented
```

### Container Inspection

```php
// Get all registered services
$container = new WPDI\Container();
$container->initialize( __DIR__ );
$services = $container->get_registered();
error_log( 'WPDI Services: ' . print_r( $services, true ) );
```

### WP-CLI Debugging

```bash
# See what's actually discovered
wp wpdi discover --format=json

# Check compilation output
wp wpdi compile --force

# Clear cache and retry
wp wpdi clear
wp wpdi discover
```

### Manual Service Testing

```php
// Test service creation outside container
try {
    $service = new My_Service(
        new My_Dependency(),
        new My_Other_Dependency()
    );
    error_log( 'Service created successfully' );
} catch ( Exception $e ) {
    error_log( 'Service creation failed: ' . $e->getMessage() );
}
```

## Getting Help

### Information to Include

When reporting issues, include:

1. **WPDI Discovery Output:**
   ```bash
   wp wpdi discover --format=json
   ```

2. **File Structure:**
   ```bash
   find . -name "*.php" -type f | head -20
   ```

3. **Configuration:**
   ```php
   // Contents of wpdi-config.php (sanitized)
   ```

4. **Error Messages:**
   ```
   // Full error message and stack trace
   ```

5. **Environment:**
    - WordPress version
    - PHP version
    - Plugin/theme structure

### Common Solutions Checklist

- [ ] Files in `src/` directory?
- [ ] PSR-4 file naming (`My_Service.php` matches class name)?
- [ ] WordPress class naming (`My_Service` with underscores)?
- [ ] Interfaces bound in `wpdi-config.php`?
- [ ] Factory functions return objects?
- [ ] No circular dependencies?
- [ ] WordPress functions available when container loads?

## Next Steps

- [API Reference](api-reference.md) - Method signatures and options
- [Getting Started](getting-started.md) - Verify basic setup