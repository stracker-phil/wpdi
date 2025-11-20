# WP-CLI Commands

WPDI includes WP-CLI commands for development and deployment.

## wp di list

List all injectable services without compiling.

**Synopsis:**

```bash
wp di list [--path=<path>] [--format=<format>]
```

**Options:**

- `--path=<path>` - Module directory (default: current directory)
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
wp di compile [--path=<path>] [--force]
```

**Options:**

- `--path=<path>` - Module directory (default: current directory)
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

## wp di clear

Clear compiled cache.

**Synopsis:**

```bash
wp di clear [--path=<path>]
```

**Options:**

- `--path=<path>` - Module directory (default: current directory)

**Example:**

```
Success: Cache cleared: cache/wpdi-container.php
Success: Removed empty cache directory
```

## Workflows

### Development

```bash
# See what WPDI finds
wp di list

# Check specific classes
wp di list --format=json | jq '.[] | select(.autowirable == "no")'
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
