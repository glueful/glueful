<?php

/**
 * Extensions Test Bootstrap
 * This file registers extension-specific namespaces for testing.
 * This helps tests locate extension classes without modifying original files.
 */

// Get the class loader
$classLoader = null;
foreach (spl_autoload_functions() as $function) {
    if (is_array($function) && $function[0] instanceof \Composer\Autoload\ClassLoader) {
        $classLoader = $function[0];
        break;
    }
}

if ($classLoader) {
    // Register specific extension namespaces for testing
    $extensionsDir = dirname(__DIR__) . '/extensions';
    // Register the extension provider namespaces explicitly
    $classLoader->addPsr4('Glueful\\Extensions\\SocialLogin\\Providers\\', $extensionsDir . '/SocialLogin/Providers/');
    // Add other common extension namespaces if needed
    $classLoader->addPsr4('Glueful\\Extensions\\SocialLogin\\', $extensionsDir . '/SocialLogin/');
}
