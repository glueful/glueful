# Extension Commands Quick Reference

## Basic Commands

### List Extensions
```bash
# Show all extensions
php glueful extensions list

# Show with autoload mappings
php glueful extensions list --show-autoload
```

### Extension Information
```bash
# Get detailed info about an extension
php glueful extensions info ExtensionName
```

### Enable/Disable Extensions
```bash
# Enable an extension
php glueful extensions enable ExtensionName

# Disable an extension
php glueful extensions disable ExtensionName
```

### View Namespaces
```bash
# Show all registered extension namespaces
php glueful extensions namespaces
```

## Development Commands

### Create Extension
```bash
# Create new extension scaffold
php glueful extensions create MyExtension

# Create from template
php glueful extensions template auth MyAuthExtension
```

### Validate Extension
```bash
# Validate specific extension
php glueful extensions validate ExtensionName
```

### Install Extension
```bash
# Install from URL
php glueful extensions install https://example.com/extension.zip MyExtension

# Install from local file
php glueful extensions install /path/to/extension.zip MyExtension
```

### Delete Extension
```bash
# Permanently delete extension
php glueful extensions delete ExtensionName
```

## System Management Commands

### Configuration Validation
```bash
# Validate extensions.json configuration
php glueful extensions validate-config
```

### Performance Benchmarking
```bash
# Run performance benchmarks
php glueful extensions benchmark
```

### Debug Information
```bash
# Show comprehensive debug info
php glueful extensions debug
```

## Command Examples

### Development Workflow
```bash
# 1. Create new extension
php glueful extensions create PaymentGateway

# 2. Enable for testing
php glueful extensions enable PaymentGateway

# 3. Validate structure
php glueful extensions validate PaymentGateway

# 4. Check debug info
php glueful extensions debug

# 5. Run performance test
php glueful extensions benchmark
```

### System Health Check
```bash
# 1. Validate configuration
php glueful extensions validate-config

# 2. Check all extensions
php glueful extensions list

# 3. Debug system state
php glueful extensions debug

# 4. Performance check
php glueful extensions benchmark
```

### Troubleshooting
```bash
# 1. List all extensions and their status
php glueful extensions list --show-autoload

# 2. Check specific extension
php glueful extensions info ProblemExtension

# 3. View debug information
php glueful extensions debug

# 4. Validate configuration
php glueful extensions validate-config
```

## Output Examples

### List with Autoload
```
Installed Extensions
===================
Name                     Status      Type      Description                             
---------------------------------------------------------------------------------------
EmailNotification        Enabled     Core      Provides email notification functiona...
SocialLogin              Disabled    Optional  Provides social authentication throug...
Admin                    Enabled     Optional  Administrative interface for managing...
RBAC                     Enabled     Optional  Role-Based Access Control for managin...

Total: 4 extensions (3 enabled, 1 core, 3 optional)

Autoload Information:
==================================================

EmailNotification (enabled)
  Glueful\Extensions\EmailNotification\ → extensions/EmailNotification/src/
  Glueful\Extensions\EmailNotification\Tests\ → extensions/EmailNotification/tests/
```

### Benchmark Results
```
Extension Loading Performance Benchmark
======================================
Testing extensions.json loading (100 iterations)...
Testing getLoadedExtensions() (100 iterations)...

Results:
========
JSON Loading: 0.08ms average (7.61ms total)
Extension Loading: 0.08ms average (7.58ms total)
Memory Usage: 25.96 KB
Extensions Found: 4

Performance Notes:
✓ Excellent performance (< 1ms average)
```

### Debug Output
```
Extension System Debug Information
=================================

1. Configuration:
   Config Path: /path/to/extensions.json
   Config Exists: Yes
   Config Size: 8.21 KB
   Last Modified: 2025-06-10 23:48:38

2. Extensions Summary:
   Total Extensions: 4
   Enabled: 3
   Core Extensions: 1

3. Loaded Namespaces:
   EmailNotification:
     ✓ Glueful\Extensions\EmailNotification\ → extensions/EmailNotification/src/
     ✗ Glueful\Extensions\EmailNotification\Tests\ → extensions/EmailNotification/tests/

4. Performance Metrics:
   Config Load Time: 0.09ms
   Extensions Load Time: 0.08ms
   Total Time: 0.17ms

5. Configuration Validation:
   Schema Version: 2.0
   Has Metadata: ✓
   Has Global Config: ✓

6. Dependency Tree:
   EmailNotification: No dependencies
   Admin: No dependencies
   RBAC: No dependencies
```

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General failure |
| 2 | Invalid arguments |

## Tips

1. **Use `--show-autoload`** to debug namespace issues
2. **Run `validate-config`** after manual JSON edits
3. **Use `benchmark`** to monitor performance
4. **Check `debug`** for comprehensive system state
5. **Validate extensions** before enabling in production