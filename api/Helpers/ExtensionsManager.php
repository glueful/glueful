<?php
namespace Glueful\Helpers;

use Glueful\Http\Router;

class ExtensionsManager {

    /**
     * Load API Extensions
     * 
     * Dynamically loads API extension modules:
     * - Scans extension directories
     * - Loads extension classes
     * - Initializes extension routes
     * - Handles extension dependencies
     * 
     * @return void
     */
    public static function loadExtensions(): void 
    {
        $extensionsNamespaces = [
            'Glueful\\Extensions\\' => ['api/api-extensions/','extensions/'],
        ];
        
        foreach ($extensionsNamespaces as $namespace => $directories) {
            foreach($directories as $directory){
                 self::scanExtensionsDirectory(
                    dirname(__DIR__) . '/' . $directory, 
                    $namespace, 
                    Router::getInstance()
                );
            }
        }
    }

    public static function scanExtensionsDirectory(string $dir, string $namespace, Router $router): void 
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($dir));
                $className = str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    $relativePath
                );
                $fullClassName = $namespace . $className;

                // Check if class exists and extends Extensions
                if (class_exists($fullClassName)) {
                    $reflection = new \ReflectionClass($fullClassName);
                    if ($reflection->isSubclassOf(\Glueful\Extensions::class)) {
                        try {
                            // Check if class has initializeRoutes method
                            if ($reflection->hasMethod('initializeRoutes')) {
                                // Initialize routes for this extension
                                $fullClassName::initializeRoutes($router);
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
            }
        }
    }
}