# WP-CLI Commands

WPDI includes helpful WP-CLI commands for development and deployment workflows.

## Available Commands

### wp wpdi discover

Discover and analyze classes in your module without compiling.

```bash
# Discover classes in current directory
wp wpdi discover

# Discover in specific directory
wp wpdi discover --path=/path/to/plugin

# Output as table (default)
wp wpdi discover --format=table

# Output as JSON
wp wpdi discover --format=json
```

**Example Output:**

```
+----------------------------------+-----------+-------------+
| class                           | type      | autowirable |
+----------------------------------+-----------+-------------+
| Payment_Processor               | concrete  | yes         |
| Payment_Validator               | concrete  | yes         |
| Payment_Config                  | concrete  | yes         |
| Payment_Client_Interface        | interface | no          |
+----------------------------------+-----------+-------------+
```

### wp wpdi compile

Compile your container for production performance.

```bash
# Compile current directory
wp wpdi compile

# Compile specific directory
wp wpdi compile --path=/path/to/plugin

# Force recompilation
wp wpdi compile --force
```

**Example Output:**

```
Discovering classes in /path/to/plugin/src...
Found 12 classes:
  - Payment_Processor
  - Payment_Validator
  - Payment_Config
  - Payment_Logger
  [... more classes ...]

Loading configuration from wpdi-config.php...
Compiling container cache...
✓ Container compiled successfully to /path/to/plugin/cache/wpdi-container.php

Total services: 15
Autowired: 12
Manual: 2
Interfaces: 1
```

### wp wpdi clear

Clear compiled cache files.

```bash
# Clear cache in current directory
wp wpdi clear

# Clear cache in specific directory
wp wpdi clear --path=/path/to/plugin
```

## Development Workflow

### 1. Development Phase

```bash
# Discover what classes WPDI finds
wp wpdi discover

# Check for any issues
wp wpdi discover --format=json | jq '.[] | select(.autowirable == "no")'
```

### 2. Pre-Deployment

```bash
# Compile for production
wp wpdi compile --force

# Verify compilation worked
ls -la cache/wpdi-container.php
```

### 3. Debugging Issues

```bash
# Clear cache and rediscover
wp wpdi clear
wp wpdi discover

# Check what's actually being compiled
wp wpdi compile --force --verbose
```

## Integration with Deployment

### WordPress.org Plugin Submission

```bash
# Before creating your plugin zip
wp wpdi compile --force
zip -r my-plugin.zip . -x "*.git*" "node_modules/*" "tests/*"
```

### Automated Deployment Script

```bash
#!/bin/bash
echo "Compiling WPDI container..."
wp wpdi compile --force

echo "Running tests..."
vendor/bin/phpunit

echo "Creating deployment package..."
# ... rest of deployment script
```

### GitHub Actions Example

```yaml
- name: Compile WPDI Container
  run: |
    wp wpdi compile --force

- name: Verify Compilation
  run: |
    test -f cache/wpdi-container.php || exit 1
```

## Command Options Reference

| Option              | Description                              | Commands |
|---------------------|------------------------------------------|----------|
| `--path=<path>`     | Specify directory to work with           | all      |
| `--force`           | Force recompilation even if cache exists | compile  |
| `--format=<format>` | Output format (table, json, yaml, csv)   | discover |

## Next Steps

- [API Reference](api-reference.md) - Container methods and interfaces
- [Troubleshooting](troubleshooting.md) - Common issues and solutions™