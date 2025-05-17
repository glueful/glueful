# Memory Monitor Command

## Overview

The `MemoryMonitorCommand` provides command-line tools for monitoring, analyzing, and diagnosing memory usage in Glueful applications. It helps developers track memory consumption over time, identify memory leaks, and optimize resource-intensive operations. This command is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Monitoring Modes](#monitoring-modes)
- [Output Formats](#output-formats)
- [Logging and Reporting](#logging-and-reporting)
- [Integration with Memory Manager](#integration-with-memory-manager)
- [Advanced Options](#advanced-options)
- [Common Use Cases](#common-use-cases)
- [Best Practices](#best-practices)

## Key Features

The `MemoryMonitorCommand` provides several critical capabilities:

- **Real-time memory tracking**: Monitor memory usage of the application in real-time
- **Configurable sampling interval**: Control how frequently memory usage is checked
- **Memory threshold alerts**: Get notifications when memory exceeds set thresholds
- **CSV logging**: Record memory metrics to file for long-term analysis
- **Memory usage visualization**: Display memory usage trends in the terminal
- **Process monitoring**: Track memory usage of running processes

## Usage Examples

### Basic Usage

```bash
# Monitor memory usage of the current process
php glueful memory:monitor

# Output:
# Memory Monitoring Started
# Current memory: 4.32 MB, Peak: 4.75 MB, Limit: 128 MB (3.4% used)
# Press Ctrl+C to stop monitoring...
# [=====                                        ] 5.12 MB (4.0%)
# [=====                                        ] 5.18 MB (4.0%)
# [======                                       ] 5.34 MB (4.2%)
# ...
```

### Monitoring with Custom Settings

```bash
# Monitor with custom interval and threshold
php glueful memory:monitor --interval=2 --threshold=50

# Monitor for a specific duration (in seconds)
php glueful memory:monitor --duration=60

# Log memory usage to CSV file
php glueful memory:monitor --log --csv=memory-log.csv
```

### Monitoring Another Command

```bash
# Monitor memory usage of a specific command
php glueful memory:monitor -- php glueful import:large-dataset

# Output includes the command's output plus memory metrics:
# Running command: php glueful import:large-dataset
# Import started...
# Memory: 5.12 MB (4.0%) ↑
# Processing batch 1 of 50...
# Memory: 15.45 MB (12.1%) ↑
# ...
```

## Monitoring Modes

The command supports different monitoring modes:

### Self-Monitoring Mode

```bash
# Monitor the memory:monitor command itself
php glueful memory:monitor

# This mode displays memory usage of the monitoring process
# Useful for baseline memory measurements and general diagnostics
```

### Command Monitoring Mode

```bash
# Monitor another command's memory usage
php glueful memory:monitor -- php glueful import:data

# This mode runs the specified command and monitors its memory usage
# Shows both the command's output and memory metrics
# Ideal for tracking memory-intensive operations
```

## Output Formats

The command provides multiple output formats:

### Terminal Display

```
Memory usage:  [========                       ] 10.5 MB (8.2%)
Peak memory:   [==========                     ] 12.7 MB (9.9%)
Memory limit:  128 MB
```

The terminal display includes:
- Visual bar graph of current memory usage
- Numeric memory values with percentages
- Memory limit information
- Trend indicators (↑, ↓, →) showing usage direction

### CSV Format

When logging to CSV, the following columns are recorded:

```
timestamp,memory_current_bytes,memory_peak_bytes,memory_limit_bytes,memory_percentage,peak_percentage
2025-05-17T14:30:01.123,11010048,13312000,134217728,0.082,0.099
2025-05-17T14:30:03.234,11534336,13312000,134217728,0.086,0.099
2025-05-17T14:30:05.345,12058624,14336000,134217728,0.090,0.107
```

This format enables:
- Long-term memory trend analysis
- Import into data analysis tools
- Historical comparison of memory usage
- Correlation with other metrics

## Logging and Reporting

The command provides comprehensive logging capabilities:

```bash
# Log memory usage to CSV file
php glueful memory:monitor --log --csv=memory-usage.csv

# Generate a summary report after monitoring
php glueful memory:monitor --duration=300 --summary
```

The summary report includes:
- Minimum, maximum, and average memory usage
- Growth rate analysis (to detect potential leaks)
- Time spent above warning threshold
- Peak memory usage statistics
- Memory usage variance

## Integration with Memory Manager

The `MemoryMonitorCommand` integrates with the `MemoryManager` to leverage its capabilities:

```php
// Within the command implementation
$usage = $this->memoryManager->getCurrentUsage();

// Display formatted memory information
echo "Current memory: {$usage['formatted']['current']}\n";
echo "Peak memory: {$usage['formatted']['peak']}\n";
echo "Memory limit: {$usage['formatted']['limit']}\n";

// Check against thresholds
if ($this->memoryManager->isMemoryHighUsage()) {
    // Display warning
    $this->outputWarning("High memory usage detected!");
}
```

This integration ensures consistent memory reporting across all parts of the application.

## Advanced Options

The command offers several advanced options:

```bash
# Set warning threshold (percentage of memory limit)
php glueful memory:monitor --threshold=75

# Monitor with higher precision (shorter interval)
php glueful memory:monitor --interval=0.5

# Run for specific duration then exit
php glueful memory:monitor --duration=120

# Control output verbosity
php glueful memory:monitor --verbose
php glueful memory:monitor --quiet

# Sort summary report by specific metric
php glueful memory:monitor --summary --sort=peak
```

These options allow fine-tuning the monitoring process for different scenarios.

## Common Use Cases

### Identifying Memory Leaks

```bash
# Monitor a command for a long period to detect memory growth
php glueful memory:monitor --duration=3600 --interval=5 --log --csv=leak-check.csv -- php glueful queue:work

# Analyze the CSV file to look for steady memory growth over time
# A continuously increasing memory usage curve suggests a memory leak
```

### Performance Testing

```bash
# Monitor memory usage during stress testing
php glueful memory:monitor --threshold=80 --log --csv=stress-test.csv -- php glueful benchmark:run --iterations=1000

# The CSV output can be used to correlate memory usage with performance metrics
```

### Optimizing Resource-Intensive Operations

```bash
# Compare memory usage before and after optimization
php glueful memory:monitor -- php glueful pre-optimized-command
# Note memory usage patterns

php glueful memory:monitor -- php glueful optimized-command
# Compare memory usage patterns to validate improvements
```

## Best Practices

For optimal use of the memory monitor command:

1. **Establish baseline measurements** of normal memory usage
2. **Monitor regularly** to catch gradual increases early
3. **Log to CSV** for long-term trend analysis
4. **Set appropriate thresholds** based on your application's characteristics
5. **Use duration limits** for automated monitoring
6. **Compare memory usage** before and after code changes
7. **Monitor with production-like data** for realistic results

```bash
# Establish baseline (normal operation)
php glueful memory:monitor --duration=300 --log --csv=baseline.csv

# Monitor specific operations with threshold alerts
php glueful memory:monitor --threshold=70 -- php glueful resource-intensive-command

# Regular automated monitoring (in cron job)
php glueful memory:monitor --duration=60 --interval=5 --log --csv=daily-$(date +%Y%m%d).csv
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory Alerting Service](./memory-alerting-service.md), and [Performance Monitoring](./performance-monitoring.md) documentation.*
