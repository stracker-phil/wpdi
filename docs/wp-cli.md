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
| `type`        | `class`, `interface`, `abstract`, or `unknown`                                             |
| `autowirable` | `yes` = can be instantiated automatically; `no` = requires factory (interfaces, abstracts) |
| `source`      | `src` = auto-discovered from `src/`; `config` = defined in `wpdi-config.php`               |

**Example:**

```
┌──────────────────────────────────────────────┬───────────┬─────────────┬────────┐
│ class                                        │ type      │ autowirable │ source │
├──────────────────────────────────────────────┼───────────┼─────────────┼────────┤
│ MyPlugin\Services\Payment_Processor          │ class     │ yes         │ src    │
│ MyPlugin\Services\Payment_Config             │ class     │ yes         │ src    │
│ MyPlugin\Contracts\Logger_Interface          │ interface │ no          │ config │
│ MyPlugin\Contracts\Cache_Interface           │ interface │ no          │ config │
└──────────────────────────────────────────────┴───────────┴─────────────┴────────┘
-- 4 entries --
```

## wp di compile

Compile container cache for production.

**Synopsis:**

```bash
wp di compile [--dir=<dir>] [--autowiring-paths=<paths>] [--format=<format>]
```

**Options:**

- `--dir=<dir>` - Module directory (default: current directory)
- `--autowiring-paths=<paths>` - Comma-separated autowiring paths relative to module (default: src)
- `--format=<format>` - Output format (default: table): `table`, `ascii`, `json`, `yaml`, `csv`

`compile` always regenerates the full cache, even if one already exists. This is intentional: the container bootstraps from the cache on every WP-CLI invocation, so an existing cache is already stale by the time the command runs.

**Example:**

```
┌─────────────────────────────────────────────────────┐
│ /src                                                │
├───────┬─────────────────────────────────────────────┤
│ type  │ class                                       │
├───────┼─────────────────────────────────────────────┤
│ class │ MyPlugin\Services\Payment_Processor         │
│ class │ MyPlugin\Services\Payment_Config            │
│ class │ MyPlugin\Services\Order_Handler             │
└───────┴─────────────────────────────────────────────┘
-- discovered 3 classes --

┌───────────────────────────────────────────────────────────────────────────────────────────┐
│ /wpdi-config.php                                                                          │
├───────────┬──────────────────────────────────────┬─────────┬──────────────────────────────┤
│ type      │ class                                │ param   │ binding                      │
├───────────┼──────────────────────────────────────┼─────────┼──────────────────────────────┤
│ interface │ MyPlugin\Contracts\Logger_Interface  │ default │ MyPlugin\Services\Logger     │
├───────────┼──────────────────────────────────────┼─────────┼──────────────────────────────┤
│ interface │ MyPlugin\Contracts\Cache_Interface   │ default │ MyPlugin\Services\Redis_Cache│
└───────────┴──────────────────────────────────────┴─────────┴──────────────────────────────┘
-- found 2 manual configs --

Success: Container compiled to: cache/wpdi-container.php
```

## wp di inspect

Inspect a class and display its dependency tree. Alias: `wp di ins`

**Synopsis:**

```bash
wp di inspect <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>]
wp di ins <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>]
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

Path: src/Services/Payment_Gateway.php
class MyPlugin\Services\Payment_Gateway

┌──────────────────┬───────────┬──────────────────────────────────────────────────┐
│ param            │ type      │ class                                            │
├──────────────────┼───────────┼──────────────────────────────────────────────────┤
│ $validator       │ class     │ MyPlugin\Services\Payment_Validator              │
│  └── $logger     │ interface │ MyPlugin\Contracts\Logger_Interface              │
├──────────────────┼───────────┼──────────────────────────────────────────────────┤
│ $config          │ class     │ MyPlugin\Services\Payment_Config                 │
└──────────────────┴───────────┴──────────────────────────────────────────────────┘
-- 3 dependencies --
```

The output shows:
- **File path** of the inspected class and its type + fully-qualified name
- **Dependency table** with parameter names, types, and FQCNs; nested dependencies are indented under their parent
- **Warnings** for unbound interfaces or abstract classes
- **[CIRCULAR]** marker on any dependency that creates a cycle

## wp di depends

List all classes that depend on a given class or interface — the reverse of `wp di inspect`. Alias: `wp di dep`

Useful for answering questions like *"which services consume this interface?"* or *"what breaks if I change this class's constructor?"*

**Synopsis:**

```bash
wp di depends <class> [--dir=<dir>] [--autowiring-paths=<paths>]
wp di dep <class> [--dir=<dir>] [--autowiring-paths=<paths>]
```

**Options:**

- `<class>` - Class or interface name to find dependents for (short or fully-qualified)
- `--dir=<dir>` - Module directory (default: current directory)
- `--autowiring-paths=<paths>` - Comma-separated autowiring paths relative to module (default: src)

Short names are resolved by scanning discovered classes and their dependency FQCNs, so interface names are resolvable even though interfaces aren't concrete classes. If ambiguous, you'll be prompted to use the fully-qualified name.

**Example:**

```
$ wp di depends Logger_Interface

Path: src/Contracts/Logger_Interface.php
interface MyPlugin\Contracts\Logger_Interface

┌───────────┬────────────────────────────────────────┬─────────┬────────────────┐
│ type      │ class                                  │ param   │ config mapping │
├───────────┼────────────────────────────────────────┼─────────┼────────────────┤
│ class     │ MyPlugin\Services\Payment_Gateway      │ $logger │ as File_Logger │
│ class     │ MyPlugin\Services\Order_Handler        │ $logger │ as File_Logger │
│ class     │ MyPlugin\Services\Auth_Service         │ $log    │ as File_Logger │
└───────────┴────────────────────────────────────────┴─────────┴────────────────┘
-- 3 usages --
```

Each row shows:
- **type** — `class`, `interface`, or `abstract`
- **class** — fully-qualified name of the dependent
- **param** — constructor parameter name through which the dependency is injected
- **config mapping** — how the dependency is resolved: `as ConcreteClass` (bound in `wpdi-config.php`), `via InterfaceName` (injected via a config-bound interface), or `-` (no binding)

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
wp di compile --autowiring-paths=modules/auth/src,modules/payment/src
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
wp di compile

# Verify cache exists
ls -la cache/wpdi-container.php
```

### Debugging

```bash
# Inspect a specific service's dependency tree
wp di inspect Payment_Gateway
wp di ins Payment_Gateway         # shorthand

# Inspect with depth limit
wp di inspect Payment_Gateway --depth=2

# Find all services that depend on an interface
wp di depends Logger_Interface
wp di dep Logger_Interface        # shorthand

# Clear and re-list
wp di clear
wp di list
```

## Deployment

### GitHub Actions

```yaml
- name: Compile Container
  run: wp di compile

- name: Verify Cache
  run: test -f cache/wpdi-container.php || exit 1
```

### Plugin Packaging

```bash
wp di compile
zip -r my-plugin.zip . -x "*.git*" "node_modules/*" "tests/*"
```
