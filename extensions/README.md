# Glueful Extensions Documentation

## Overview

Glueful Extensions provide a powerful way to extend and customize the API functionality. Extensions are modular components that can add new features, integrate services, and modify core behavior without changing the base codebase.

## Directory Structure

glueful/ 
├── extensions/ # User-created extensions 
│   ├── MyExtension/ # Individual extension directory 
│   │   ├── MyExtension.php 
│   │   └── README.md 
│   └── README.md 
├── api/ 
│   ├── Extensions.php # Base extension class 
│   └── api-extensions/ # Core API extensions 
└── config/ 
    └── extensions.php # Extension configuration


## Extension Management

Extensions can be managed using the CLI tool:

```bash
# List all extensions
php glueful extensions list

# Get extension details
php glueful extensions info MyExtension

# Enable/disable extensions
php glueful extensions enable MyExtension
php glueful extensions disable MyExtension

# Create new extension
php glueful extensions create MyExtension
```

## Creating Extensions

Each extension must:

- Extend the Glueful\Extensions base class
- Be in the Glueful\Extensions namespace
- Implement required lifecycle methods

## Extension Lifecycle

- **Loading**: Extensions are discovered and loaded by ExtensionsManager
- **Initialization**: `initialize()` method called
- **Service Registration**: `registerServices()` called to add services
- **Middleware Registration**: `registerMiddleware()` called to add middleware
- **Request Processing**: `process()` handles extension-specific requests

## Configuration

Extensions are configured in `config/extensions.php`:

```php
<?php
return [
    'enabled' => [
        'MyExtension',
        'PaymentGateway'
    ],
    'paths' => [
        'extensions' => '/path/to/extensions',
        'api-extensions' => '/path/to/api-extensions'
    ]
];
```

## Best Practices

- **Naming**: Use PascalCase for extension names (e.g., PaymentGateway)
- **Documentation**: Include docblocks with description, version, and author
- **Namespace**: Place extensions in `Glueful\Extensions` namespace
- **Error Handling**: Return proper error responses with status codes
- **Dependencies**: Check for required dependencies in `initialize()`
- **Configuration**: Use extension-specific config files for settings

## Currently Available Extensions
- **Email**: Email functionality
- **AdvancedEmail**: Extended email capabilities with templates

## Troubleshooting

Common issues and solutions:

### Extension Not Loading

- Check namespace declaration
- Verify file location matches namespace
- Ensure extension is enabled in config

### Permission Errors

- Check directory permissions
- Verify user has write access for config

### Class Not Found

- Confirm class name matches filename
- Check autoloader configuration
- Verify namespace is correct

## Support

For additional help:

- Review API documentation
- Submit issues through the support system
