#!/usr/bin/env php
<?php
/**
 * {{EXTENSION_NAME}} Extension Build Script
 * 
 * Builds this extension for distribution
 */

// Navigate to the project root
$projectRoot = dirname(__DIR__, 3);
$buildScript = $projectRoot . '/extensions/scripts/build.php';

if (!file_exists($buildScript)) {
    echo "Error: Build script not found at $buildScript\n";
    echo "Please ensure you're running this from within a Glueful project.\n";
    exit(1);
}

// Build this specific extension
echo "Building {{EXTENSION_NAME}} extension...\n";
passthru("php $buildScript {{EXTENSION_NAME}}");