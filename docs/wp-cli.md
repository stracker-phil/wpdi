# WP-CLI Commands

WPDI includes WP-CLI commands for development and deployment.

## discover

Discover and analyze classes without compiling.

```bash
wp di discover
wp di discover --path=/path/to/plugin
wp di discover --format=json
```

**Output:**

```
+---------------------------+-----------+-------------+
| class                     | type      | autowirable |
+---------------------------+-----------+-------------+
| Payment_Processor         | concrete  | yes         |
| Payment_Client_Interface  | interface | no          |
+---------------------------+-----------+-------------+
```

## compile

Compile container for production performance.

```bash
wp di compile
wp di compile --path=/path/to/plugin
wp di compile --force  # Force recompilation
```

**Output:**

```
Discovering classes in /path/to/plugin/src...
Found 12 classes

✓ Container compiled to cache/wpdi-container.php

Total services: 15
Autowired: 12
Manual: 3
```

## clear

Clear compiled cache files.

```bash
wp di clear
wp di clear --path=/path/to/plugin
```

## Workflows

### Development

```bash
# See what WPDI discovers
wp di discover

# Check for issues
wp di discover --format=json | jq '.[] | select(.autowirable == "no")'
```

### Pre-Deployment

```bash
# Compile for production
wp di compile --force

# Verify
ls -la cache/wpdi-container.php
```

### Debugging

```bash
# Clear and rediscover
wp di clear
wp di discover
```

## Deployment Integration

### Plugin Packaging

```bash
wp di compile --force
zip -r my-plugin.zip . -x "*.git*" "node_modules/*" "tests/*"
```

### GitHub Actions

```yaml
- name: Compile Container
  run: wp di compile --force

- name: Verify
  run: test -f cache/wpdi-container.php || exit 1
```

## Options

| Option              | Description                      | Commands |
|---------------------|----------------------------------|----------|
| `--path=<path>`     | Specify directory                | all      |
| `--force`           | Force recompilation              | compile  |
| `--format=<format>` | Output format (table, json, csv) | discover |
