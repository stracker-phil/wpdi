# WP-CLI Commands

WPDI includes WP-CLI commands for development and deployment.

## Registering Commands

When you extend `WPDI\Scope`, the CLI commands are registered automatically. If your plugin uses WPDI without instantiating a `Scope` at load time (e.g., lazy initialization inside a WP-CLI callback), register the commands explicitly:

```php
require_once __DIR__ . '/vendor/autoload.php';

// Register "wp di" commands without instantiating Scope.
WPDI\Commands\Cli::register_commands();
```

The method is a no-op outside WP-CLI and safe to call multiple times.

## wp di list

List all injectable services without compiling.

**Synopsis:**

```bash
wp di list [--dir=<dir>] [--autowiring-paths=<paths>] [--filter=<filter>] [--format=<format>]
```

**Options:**

- `--dir=<dir>` - Module directory (default: current directory)
- `--autowiring-paths=<paths>` - Comma-separated autowiring paths relative to module (default: src)
- `--filter=<filter>` - Only show services whose fully-qualified class name contains this substring
- `--format=<format>` - Output format (default: table)
    - `table` - ASCII table
    - `json` - JSON array
    - `csv` - Comma-separated values
    - `yaml` - YAML format

**Output columns:**

| Column        | Description                                                                                |
|---------------|--------------------------------------------------------------------------------------------|
| `class`       | Fully-qualified class or interface name                                                    |
| `type`        | `concrete`, `interface`, `abstract`, or `unknown`                                          |
| `autowirable` | `yes` = can be instantiated automatically; `no` = requires factory (interfaces, abstracts) |
| `source`      | `src` = auto-discovered from `src/`; `config` = defined in `wpdi-config.php`               |

**Example:**

```
+-------------------+----------+-------------+--------+
| class             | type     | autowirable | source |
+-------------------+----------+-------------+--------+
| Payment_Processor | concrete | yes         | src    |
| Payment_Config    | concrete | yes         | src    |
| Logger_Interface  | interface| no          | config |
| Cache_Interface   | interface| no          | config |
+-------------------+----------+-------------+--------+
```

## wp di compile

Compile container cache for production.

**Synopsis:**

```bash
wp di compile [--dir=<dir>] [--autowiring-paths=<paths>] [--force]
```

**Options:**

- `--dir=<dir>` - Module directory (default: current directory)
- `--autowiring-paths=<paths>` - Comma-separated autowiring paths relative to module (default: src)
- `--force` - Overwrite existing cache file

**Example:**

```
Discovering classes in /path/to/plugin/src...
Found 3 classes:
  - Payment_Processor
  - Payment_Config
  - Order_Handler
Loading configuration from wpdi-config.php...
Compiling container cache...
Success: Container compiled successfully to cache/wpdi-container.php
Total discovered classes: 3
Manual configurations: 2
  - Logger_Interface
  - Cache_Interface
```

## wp di inspect

Inspect a class and display its dependency tree.

**Synopsis:**

```bash
wp di inspect <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>]
```

**Options:**

- `<class>` - Class or interface name to inspect (short or fully-qualified)
- `--dir=<dir>` - Module directory (default: current directory)
- `--autowiring-paths=<paths>` - Comma-separated autowiring paths relative to module (default: src)
- `--depth=<depth>` - Maximum tree depth to display (default: unlimited)

Short class names are resolved automatically by scanning the autodiscovery paths. If the name is ambiguous (exists in multiple namespaces), you'll be prompted to use the fully-qualified name.

**Example:**

```
$ wp di inspect Payment_Gateway

Path: src/Payment_Gateway.php

Payment_Gateway    class        MyPlugin\Services
├── $validator     class        MyPlugin\Services\Payment_Validator
│   └── $logger    interface    MyPlugin\Contracts\Logger_Interface
└── $config        class        MyPlugin\Services\Payment_Config
```

The output shows:
- **File path** of the inspected class
- **Dependency tree** with parameter names, types, and namespaces
- **Warnings** for unbound interfaces or abstract classes
- **Circular dependency** markers when detected

## wp di clear

Clear compiled cache.

**Synopsis:**

```bash
wp di clear [--dir=<dir>]
```

**Options:**

- `--dir=<dir>` - Module directory (default: current directory)

**Example:**

```
Success: Cache cleared: cache/wpdi-container.php
Success: Removed empty cache directory
```

## Multi-Module Projects

When using custom autowiring paths, specify them with `--autowiring-paths`:

```bash
# List services from multiple modules
wp di list --autowiring-paths=modules/auth/src,modules/payment/src,shared/src

# Compile with custom paths
wp di compile --autowiring-paths=modules/auth/src,modules/payment/src --force
```

## Workflows

### Development

```bash
# See what WPDI finds
wp di list

# Check specific classes
wp di list --format=json | jq '.[] | select(.autowirable == "no")'

# List from custom path
wp di list --autowiring-paths=lib
```

### Pre-Deployment

```bash
# Compile for production
wp di compile --force

# Verify cache exists
ls -la cache/wpdi-container.php
```

### Debugging

```bash
# Inspect a specific service's dependency tree
wp di inspect Payment_Gateway

# Inspect with depth limit
wp di inspect Payment_Gateway --depth=2

# Clear and re-list
wp di clear
wp di list
```

## Deployment

### GitHub Actions

```yaml
- name: Compile Container
  run: wp di compile --force

- name: Verify Cache
  run: test -f cache/wpdi-container.php || exit 1
```

### Plugin Packaging

```bash
wp di compile --force
zip -r my-plugin.zip . -x "*.git*" "node_modules/*" "tests/*"
```
