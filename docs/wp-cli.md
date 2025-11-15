# WP-CLI Commands

WPDI includes WP-CLI commands for development and deployment.

## wp di discover

Discover and list classes without compiling.

```bash
wp di discover
wp di discover --path=/path/to/plugin
wp di discover --format=json
```

**Output:**

```
+---------------------------+----------+-------------+
| class                     | type     | autowirable |
+---------------------------+----------+-------------+
| Payment_Processor         | concrete | yes         |
| Payment_Config            | concrete | yes         |
| Order_Handler             | concrete | yes         |
+---------------------------+----------+-------------+
```

**Note:** Only concrete classes are discovered. Interfaces, abstract classes, and traits are filtered out.

## wp di compile

Compile container cache for production.

```bash
wp di compile
wp di compile --path=/path/to/plugin
wp di compile --force
```

**Output:**

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

```bash
wp di clear
wp di clear --path=/path/to/plugin
```

**Output:**

```
Success: Cache cleared: cache/wpdi-container.php
Success: Removed empty cache directory
```

## Workflows

### Development

```bash
# See what WPDI discovers
wp di discover

# Check specific classes
wp di discover --format=json | jq '.[] | select(.autowirable == "no")'
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
# Clear and rediscover
wp di clear
wp di discover
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

## Options

| Option              | Description                            | Commands |
|---------------------|----------------------------------------|----------|
| `--path=<path>`     | Module directory                       | all      |
| `--force`           | Force recompilation                    | compile  |
| `--format=<format>` | Output format (table, json, yaml, csv) | discover |
