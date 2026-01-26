# Development Guide

## Releasing a New Version

Update the version number in **two places**:

1. **`composer.json`** (line 4):
   ```json
   "version": "1.0.3"
   ```

2. **`src/version-check.php`** (line 9):
   ```php
   $module_version = '1.0.3';
   ```

Both must match to ensure consistency between the Composer package version and runtime version check.

### Pre-Release Checklist

```bash
# Run tests
ddev composer test

# Check coding standards
ddev composer cs

# Update version numbers (see above)

# Commit and tag
git commit -am "Release v1.0.3"
git tag v1.0.3
git push origin main --tags
```
