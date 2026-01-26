# MU-Plugin Installation

## Who Needs This?

**Target Audience:**

- ✅ **Plugin developers** working on WPDI-based plugins
- ✅ **Self-managed sites** with custom internal plugins
- ✅ **Development environments** running multiple WPDI plugins

**NOT for:**

- ❌ **Public WP.org plugins** - These should use [PHP-Scoper](https://github.com/humbug/php-scoper) or [Mozart](https://github.com/coenjacobs/mozart) to namespace bundled libraries
- ❌ **Production sites with public plugins** - Well-packaged plugins handle namespacing internally

**Why the difference?**

Public plugins distributed via WordPress.org should namespace their dependencies to avoid conflicts. Tools like PHP-Scoper automatically prefix all library classes (e.g., `WPDI\Scope` → `MyPlugin\Vendor\WPDI\Scope`), making conflicts impossible.

However, during development or on self-managed sites where you control all plugins, mu-plugin installation offers a simpler alternative to scoping.

## The Problem: Multiple Plugins Using WPDI

When multiple plugins bundle WPDI without scoping, the first plugin to load determines which version all plugins use. If plugins require different WPDI versions, conflicts can occur.

**Example conflict:**

- Plugin A bundles WPDI v1.0.3
- Plugin B bundles WPDI v1.0.1
- Plugin A loads first → WPDI v1.0.3 used
- Plugin B loads second → Uses v1.0.3 (backward compatible ✅)

**But if loading order reverses:**

- Plugin B loads first → WPDI v1.0.1 used
- Plugin A loads second → Requires v1.0.3, but v1.0.1 loaded
- **Fatal error** with clear message ❌

## Solution: MU-Plugin Installation

Install WPDI as a **mu-plugin** (must-use plugin) to control which version loads.

MU-plugins load before regular plugins, ensuring WPDI is available at the correct version before any plugin tries to use it.

### Installation Steps

#### 1. Download WPDI

Place WPDI in `wp-content/mu-plugins/wpdi/`:

```
wp-content/
└── mu-plugins/
    ├── wpdi/                  ← WPDI library
    │   ├── src/
    │   │   ├── Scope.php
    │   │   ├── Container.php
    │   │   └── ...
    │   └── composer.json
    └── wpdi-loader.php        ← Loader file (create this)
```

**Via Composer:**

```bash
cd wp-content/mu-plugins
composer require stracker-phil/wpdi
```

**Manual Download:**

```bash
cd wp-content/mu-plugins
git clone https://github.com/stracker-phil/wpdi.git
```

#### 2. Create Loader File

Create `wp-content/mu-plugins/wpdi-loader.php`:

```php
<?php
/**
 * Plugin Name: WPDI Framework Loader
 * Description: Loads WPDI framework before all plugins
 * Version: 1.0.2
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load WPDI from mu-plugins directory
require_once __DIR__ . '/wpdi/src/Scope.php';
```

**If installed via Composer:**

```php
<?php
/**
 * Plugin Name: WPDI Framework Loader
 * Description: Loads WPDI framework before all plugins
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
```

### Benefits

✅ **Early available**: WPDI loads first and can be used in all plugins or themes
✅ **Version Control**: You decide which WPDI version loads
✅ **Updates**: Update WPDI independently of plugins

### Verification

After installation, verify WPDI loads correctly:

```bash
wp eval 'echo defined("WPDI_VERSION") ? "WPDI v" . WPDI_VERSION : "Not loaded";'
```

Expected output:

```
WPDI v1.0.2
```

## Advanced: Custom WPDI Location

If WPDI is installed elsewhere, adjust the loader:

```php
<?php
/**
 * Plugin Name: WPDI Framework Loader
 */

// Load from custom location
require_once WP_CONTENT_DIR . '/lib/wpdi/src/Scope.php';
```

## Troubleshooting

### "Class 'WPDI\Scope' not found"

**Cause**: WPDI not loaded before plugins
**Fix**: Ensure `wpdi-loader.php` exists in `mu-plugins/` directory

### Version conflict error persists

**Cause**: Plugin bundles older WPDI that loads before mu-plugin
**Fix**: Check plugin loading order. MU-plugins should load first. Verify with:

```bash
wp plugin list --field=name | head -5
```

### Multiple WPDI installations

**Cause**: WPDI in both mu-plugins and plugin vendors
**Impact**: No issue - version check ensures consistency
**Optimization**: Remove from plugin vendors to reduce duplication

## When to Use Scoping vs. MU-Plugin

### Use PHP-Scoper/Mozart (Recommended for Public Plugins)

**When:**

- Distributing plugin via WordPress.org
- Building commercial plugins for external customers
- Cannot control what other plugins users install

**How:**

```bash
# Install PHP-Scoper
composer require --dev humbug/php-scoper

# Configure scoper.inc.php
# Prefix all WPDI classes with your plugin namespace
php-scoper add-prefix --output-dir=build
```

**Result:**

```php
// Before scoping
use WPDI\Scope;

// After scoping
use MyPlugin\Vendor\WPDI\Scope;
```

No conflicts possible - each plugin has its own namespaced copy.

### Use MU-Plugin Installation (For Development/Internal Use)

**When:**

- Development environment
- Self-managed site with custom plugins
- You control all plugins on the site

**Benefit:**
Simpler than scoping, single WPDI version, easier debugging.
