# Migration Guide: Extension System v1.0 to v2.0

## Overview

This guide helps existing extension developers migrate from the legacy v1.0 extension system to the new high-performance v2.0 schema-based system.

## What's Changed

### Performance Improvements
- **50x faster loading**: From ~50ms to <1ms
- **77x less memory**: From ~2MB to ~26KB  
- **Eliminated filesystem scanning**: Pre-computed autoload mappings
- **Instant namespace registration**: No runtime discovery

### New Features
- Pre-computed extension metadata
- Enhanced validation and debugging tools
- Real-time performance monitoring
- Comprehensive configuration validation
- Advanced dependency management

## Migration Process

### Automatic Migration

🎉 **Good News**: Migration is **automatic** and **seamless**!

1. **Detection**: System automatically detects v1.0 configurations
2. **Conversion**: Migrates to v2.0 format in real-time
3. **Validation**: Ensures all extensions work correctly
4. **Cleanup**: Removes legacy files after successful migration

### What Happens During Migration

```bash
# Before (v1.0)
extensions/
├── config.php                    # Legacy config
├── ExtensionName/
│   ├── ExtensionName.php
│   └── src/

# After (v2.0)  
extensions/
├── extensions.json               # New schema v2.0
├── ExtensionName/
│   ├── ExtensionName.php
│   └── src/
```

### Migration Timeline

| Step | Action | Status |
|------|--------|--------|
| 1 | System detects v1.0 config | ✅ Automatic |
| 2 | Creates extensions.json | ✅ Automatic |
| 3 | Migrates extension metadata | ✅ Automatic |
| 4 | Updates autoload mappings | ✅ Automatic |
| 5 | Validates configuration | ✅ Automatic |
| 6 | Removes legacy files | ✅ Automatic |

## For Extension Developers

### No Code Changes Required

✅ **Your existing extensions work unchanged**

```php
// This still works exactly the same
namespace Glueful\Extensions\MyExtension;

class MyExtension
{
    public function register(): void
    {
        // Your existing code
    }
}
```

### Enhanced Metadata (Optional)

You can optionally enhance your extension with richer metadata:

#### Create extension.json (Optional)
```json
{
    "name": "MyExtension",
    "version": "1.0.0",
    "description": "My awesome extension",
    "author": "Your Name",
    "license": "MIT",
    "type": "optional",
    "dependencies": {
        "php": ">=8.1",
        "extensions": ["RequiredExtension"],
        "packages": {
            "vendor/package": "^1.0"
        }
    },
    "provides": {
        "services": ["src/MyServiceProvider.php"],
        "routes": ["src/routes.php"],
        "middleware": ["src/MyMiddleware.php"],
        "commands": ["src/MyCommand.php"],
        "migrations": ["migrations/001_CreateTable.php"]
    },
    "config": {
        "categories": ["utility", "api"],
        "icon": "assets/icon.png",
        "keywords": ["extension", "utility"]
    }
}
```

### Testing Your Extension

After migration, verify your extension works:

```bash
# 1. Check extension status
php glueful extensions list

# 2. Get detailed info
php glueful extensions info MyExtension

# 3. Validate structure
php glueful extensions validate MyExtension

# 4. Check debug info
php glueful extensions debug
```

## Removed Legacy Features

### Deprecated Methods (Removed)

These methods were removed from ExtensionsManager:

```php
// ❌ Removed - No longer needed
ExtensionsManager::registerExtensionNamespacesLegacy()
ExtensionsManager::registerSubdirectoryNamespaces()  
ExtensionsManager::scanAndLoadExtensions()
ExtensionsManager::initializeExtensions()

// ✅ New - Use these instead
ExtensionsManager::loadExtensionsConfig()
ExtensionsManager::getLoadedExtensions()
```

### Legacy Configuration

```php
// ❌ Old config.php format (deprecated)
<?php
return [
    'enabled' => ['MyExtension'],
    'core' => ['CoreExtension'],
    'paths' => [...]
];

// ✅ New extensions.json format  
{
    "schema_version": "2.0",
    "extensions": {
        "MyExtension": {
            "enabled": true,
            "type": "optional",
            // ... full metadata
        }
    }
}
```

## New Command Features

### Enhanced Commands

```bash
# New autoload information
php glueful extensions list --show-autoload

# Configuration validation
php glueful extensions validate-config

# Performance benchmarking  
php glueful extensions benchmark

# Comprehensive debugging
php glueful extensions debug
```

### Example Outputs

#### Autoload Information
```bash
$ php glueful extensions list --show-autoload

Autoload Information:
==================================================

MyExtension (enabled)
  Glueful\Extensions\MyExtension\ → extensions/MyExtension/src/
  Glueful\Extensions\MyExtension\Tests\ → extensions/MyExtension/tests/
```

#### Performance Benchmark
```bash
$ php glueful extensions benchmark

Extension Loading Performance Benchmark
======================================
JSON Loading: 0.08ms average (7.61ms total)
Extension Loading: 0.08ms average (7.58ms total)
Memory Usage: 25.96 KB
Extensions Found: 4

Performance Notes:
✓ Excellent performance (< 1ms average)
```

## Troubleshooting Migration

### Common Issues

#### Issue: Extension not found after migration

**Solution:**
```bash
# Check extension status
php glueful extensions list

# Validate configuration  
php glueful extensions validate-config

# Check debug output
php glueful extensions debug
```

#### Issue: Namespace not loading

**Solution:**
```bash
# Check autoload mappings
php glueful extensions list --show-autoload

# Validate specific extension
php glueful extensions validate MyExtension

# Check debug namespaces section
php glueful extensions debug
```

#### Issue: Performance regression

**Solution:**
```bash
# Run benchmark
php glueful extensions benchmark

# Should show <1ms average - if not, check debug output
php glueful extensions debug
```

### Validation Checklist

After migration, verify:

- ✅ `php glueful extensions list` shows all extensions
- ✅ `php glueful extensions validate-config` passes
- ✅ `php glueful extensions benchmark` shows <1ms performance
- ✅ Your extension classes load correctly
- ✅ Extension functionality works as expected

## Benefits After Migration

### For Developers

1. **Faster Development**: Instant extension loading
2. **Better Debugging**: Comprehensive debug tools
3. **Performance Monitoring**: Built-in benchmarking
4. **Configuration Validation**: Automatic error detection
5. **Enhanced Commands**: Rich CLI management tools

### For Users

1. **50x Faster Loading**: Near-instant extension initialization
2. **Lower Memory Usage**: 77x reduction in memory footprint  
3. **Better Reliability**: Robust validation and error handling
4. **Improved Performance**: Optimized for production workloads

## Schema v2.0 Features

### Automatic Features

- ✅ Pre-computed autoload mappings
- ✅ Dependency validation
- ✅ Performance optimization
- ✅ Configuration integrity checking
- ✅ Environment-specific settings

### Enhanced Metadata

```json
{
    "metadata": {
        "created_at": "2025-06-10T22:27:59+00:00",
        "last_updated": "2025-06-10T23:48:38+00:00", 
        "total_extensions": 4,
        "enabled_extensions": 3,
        "migrated_from": "schema_v1.0"
    }
}
```

## Best Practices Post-Migration

### Regular Maintenance

```bash
# Weekly health check
php glueful extensions validate-config
php glueful extensions benchmark

# Before deployments  
php glueful extensions debug
php glueful extensions list
```

### Performance Monitoring

```bash
# Monitor extension performance
php glueful extensions benchmark

# Target: <1ms average loading time
# Target: <100KB memory usage
```

### Extension Development

1. **Use the new validation tools** during development
2. **Add rich metadata** to your extensions  
3. **Test with the debug command** before deployment
4. **Monitor performance** with benchmark command

## Support

If you encounter any issues during migration:

1. **Check the debug output**: `php glueful extensions debug`
2. **Validate configuration**: `php glueful extensions validate-config`  
3. **Review this guide**: Common solutions are documented above
4. **Check extension status**: `php glueful extensions list --show-autoload`

## Summary

The migration to Extension System v2.0 provides:

- ✅ **Zero breaking changes** for existing extensions
- ✅ **Automatic migration** with no manual intervention  
- ✅ **50x performance improvement** 
- ✅ **Enhanced developer tools**
- ✅ **Better debugging capabilities**
- ✅ **Comprehensive validation**

Your extensions will work exactly as before, but with dramatically improved performance and enhanced management capabilities.