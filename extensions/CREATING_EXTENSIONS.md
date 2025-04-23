# Creating Extensions for Glueful

This guide explains how to create new extensions for the Glueful Framework using the ExtensionManager system.

## Extension Structure

Extensions in Glueful follow a standard directory and namespace structure:

```
extensions/
  YourExtension/       # Extension folder (PascalCase)
    YourExtension.php  # Main extension class (same name as folder)
    config.php         # Extension configuration
    routes.php         # Extension routes
    Other files and directories...
```

## Creating a New Extension

1. **Create an extension directory**

   Create a new directory in the `extensions/` folder. The name should be in PascalCase and will be the name of your extension.

   ```
   mkdir extensions/YourExtension
   ```

2. **Create the main extension class**

   Create a PHP file with the same name as your extension directory. This class must implement the `Glueful\Extensions` interface.

   ```php
   <?php
   declare(strict_types=1);

   namespace Glueful\Extensions\YourExtension;

   use Glueful\Extensions;

   /**
    * YourExtension for Glueful
    * 
    * Description of what your extension does.
    */
   class YourExtension implements Extensions
   {
       /**
        * Initialize extension
        */
       public static function initialize(): void
       {
           // Initialize your extension
           // Load configuration, set up services, etc.
       }
       
       /**
        * Register extension-provided services
        */
       public static function registerServices(): void
       {
           // Register any services provided by your extension
       }
       
       /**
        * Register extension-specific routes
        */
       public static function registerRoutes(): void
       {
           // Routes are defined in the routes.php file
           // This method is called automatically to load them
       }
       
       /**
        * Get extension metadata
        */
       public static function getMetadata(): array
       {
           return [
               'name' => 'YourExtension',
               'description' => 'Description of what your extension does',
               'version' => '1.0.0',
               'author' => 'Your Name',
               'requires' => [
                   'glueful' => '>=1.0.0',
                   'php' => '>=8.1.0',
                   'extensions' => []
               ]
           ];
       }
   }
   ```

3. **Create a configuration file (optional)**

   If your extension needs configuration, create a `config.php` file:

   ```php
   <?php
   /**
    * YourExtension Configuration
    */
   return [
       'option1' => 'value1',
       'option2' => 'value2',
       // Add your configuration options
   ];
   ```

4. **Create a routes file (optional)**

   If your extension provides API endpoints, create a `routes.php` file:

   ```php
   <?php
   declare(strict_types=1);

   use Glueful\Http\Router;
   use Symfony\Component\HttpFoundation\Request;
   use Glueful\Http\Response;

   /**
    * YourExtension Routes
    */
   Router::group('/your-extension', function() {
       Router::get('/', function (Request $request) {
           return Response::ok(['message' => 'YourExtension is working!'])->send();
       });
       
       // Add more routes as needed
   });
   ```

5. **Enable your extension**

   Edit the `config/extensions.php` file to enable your extension:

   ```php
   return [
       'enabled' => [
           // other extensions...
           'YourExtension',
       ],
   ];
   ```

## Namespace Structure

All classes in your extension should use the namespace `Glueful\Extensions\YourExtension\`. For example:

```php
namespace Glueful\Extensions\YourExtension\Services;

class YourService
{
    // Service implementation
}
```

## Accessing and Using Extensions

To check if an extension is enabled and use it from other parts of the application:

```php
$extensionManager = new \Glueful\Helpers\ExtensionManager($classLoader);

if ($extensionManager->isExtensionEnabled('YourExtension')) {
    // Use the extension
}
```

## Benefits of the ExtensionManager System

- **No need to modify composer.json**: The ExtensionManager automatically registers your extension's namespace with Composer's autoloader.
- **Organized code structure**: Each extension has its own namespace and directory.
- **Simple to enable/disable**: Just add or remove the extension name from the config file.
- **Standardized interface**: All extensions implement the same interface for consistent behavior.

## Best Practices

1. **Keep extensions self-contained**: Each extension should be independent and not rely on other extensions unless explicitly declared as a dependency.
2. **Use proper namespacing**: All classes should be properly namespaced to avoid conflicts.
3. **Document your extension**: Include a README.md file with documentation on how to use your extension.
4. **Follow coding standards**: Follow the same coding standards as the rest of the Glueful framework.
5. **Include tests**: Write tests for your extension functionality.
6. **Use dependency injection**: When possible, use dependency injection to make your code more testable and maintainable.