# Extension System v2.0 Quick Start Guide

## 🚀 Get Started in 5 Minutes

This guide gets you up and running with the Glueful Extension System v2.0 in just a few minutes.

## Step 1: Check Your System

```bash
# Verify extension system is working
php glueful extensions list
```

Expected output:
```
Installed Extensions
===================
Name                     Status      Type      Description                             
---------------------------------------------------------------------------------------
EmailNotification        Enabled     Core      Provides email notification functiona...
Admin                    Enabled     Optional  Administrative interface for managing...
RBAC                     Enabled     Optional  Role-Based Access Control for managin...
```

## Step 2: Explore Available Commands

```bash
# See all extension commands
php glueful extensions --help
```

## Step 3: System Health Check

```bash
# Validate your configuration
php glueful extensions validate-config

# Run performance benchmark
php glueful extensions benchmark

# View system debug info
php glueful extensions debug
```

Expected benchmark results:
```
Extension Loading Performance Benchmark
======================================
JSON Loading: 0.08ms average (7.61ms total)
Extension Loading: 0.08ms average (7.58ms total)
Memory Usage: 25.96 KB
Extensions Found: 4

Performance Notes:
✓ Excellent performance (< 1ms average)
```

## Step 4: Create Your First Extension

```bash
# Create a new extension
php glueful extensions create MyFirstExtension
```

Follow the interactive prompts:
1. Enter description: "My first extension"
2. Enter author: "Your Name"
3. Select type: "optional"
4. Select template: "Basic"
5. Choose features as needed

## Step 5: Enable and Test

```bash
# Enable your extension
php glueful extensions enable MyFirstExtension

# Get detailed information
php glueful extensions info MyFirstExtension

# Verify it's loaded
php glueful extensions list --show-autoload
```

## Common Tasks

### Managing Extensions

```bash
# List all extensions with details
php glueful extensions list --show-autoload

# Get specific extension info  
php glueful extensions info ExtensionName

# Enable an extension
php glueful extensions enable ExtensionName

# Disable an extension
php glueful extensions disable ExtensionName
```

### System Maintenance

```bash
# Validate configuration
php glueful extensions validate-config

# Check performance
php glueful extensions benchmark

# Debug issues
php glueful extensions debug

# View namespace mappings
php glueful extensions namespaces
```

### Development Workflow

```bash
# Create extension
php glueful extensions create MyExtension

# Validate structure
php glueful extensions validate MyExtension

# Test performance impact
php glueful extensions benchmark

# Check debug output
php glueful extensions debug
```

## Key Features

### 🚀 Performance
- **50x faster** than legacy system
- **Sub-millisecond loading** times
- **Minimal memory usage** (26KB typical)

### 🛠️ Developer Tools
- **Real-time validation** of configurations
- **Performance benchmarking** built-in
- **Comprehensive debugging** information
- **Autoload mapping** visualization

### 📊 Monitoring
- **Health checks** with validate-config
- **Performance tracking** with benchmark
- **System diagnostics** with debug command

## Troubleshooting

### Extension Not Loading

```bash
# Check if extension is enabled
php glueful extensions list

# Validate configuration
php glueful extensions validate-config

# Check debug output for issues
php glueful extensions debug
```

### Performance Issues

```bash
# Run benchmark to identify problems
php glueful extensions benchmark

# Check debug metrics
php glueful extensions debug

# Validate all configurations
php glueful extensions validate-config
```

### Namespace Issues

```bash
# View all namespace mappings
php glueful extensions namespaces

# Check autoload info
php glueful extensions list --show-autoload

# Get specific extension details
php glueful extensions info ExtensionName
```

## Best Practices

### 1. Regular Health Checks
```bash
# Weekly system check
php glueful extensions validate-config
php glueful extensions benchmark
```

### 2. Performance Monitoring
```bash
# Monitor performance trends
php glueful extensions benchmark

# Target metrics:
# - Loading time: < 1ms
# - Memory usage: < 100KB
```

### 3. Development Testing
```bash
# Before enabling in production
php glueful extensions validate ExtensionName
php glueful extensions debug
```

## Next Steps

### Learn More
- **[Full Documentation](/docs/EXTENSION_SYSTEM_V2.md)** - Complete system guide
- **[Commands Reference](/docs/EXTENSION_COMMANDS_REFERENCE.md)** - All commands detailed
- **[Migration Guide](/docs/MIGRATION_GUIDE_V1_TO_V2.md)** - Upgrade from v1.0
- **[Performance Analysis](/docs/PERFORMANCE_COMPARISON.md)** - Detailed benchmarks

### Advanced Features
- Extension dependencies
- Environment-specific configurations
- Custom autoload mappings
- Performance optimization

### Development
- Extension templates
- Custom commands
- Service providers
- Route registration

## Support

### Quick Help
```bash
# Command help
php glueful extensions --help

# Command-specific help
php glueful extensions info --help
```

### System Status
```bash
# One-command system check
php glueful extensions debug
```

### Performance Check
```bash
# Quick performance validation
php glueful extensions benchmark
```

---

🎉 **Congratulations!** You're now ready to use the high-performance Extension System v2.0. The system provides 50x better performance while maintaining full compatibility with existing extensions.

For detailed information, check out the [complete documentation](/docs/EXTENSION_SYSTEM_V2.md).