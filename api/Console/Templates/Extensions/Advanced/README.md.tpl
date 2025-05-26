# {{EXTENSION_NAME}} Extension for Glueful (Advanced Template)

{{EXTENSION_DESCRIPTION}}

**Template Type:** Advanced - Comprehensive extension template with full feature implementation.

**Use this template when:**
- Building production-ready extensions
- Need advanced features (middleware, events, migrations)
- Creating complex business logic
- Building marketplace-ready extensions
- Need API endpoints and security features

**Includes:**
- Event system integration
- Middleware implementation
- Database migrations
- Service classes
- API endpoint handlers
- Security validation
- Asset management
- Configuration schema

## Installation

### Via Glueful CLI (Recommended)

1. Install the extension package:
```bash
glueful extensions:install {{EXTENSION_NAME}}-1.0.0.gluex
```

2. Enable the extension:
```bash
glueful extensions:enable {{EXTENSION_NAME}}
```

### Manual Installation

1. Copy the extension files to your `extensions/{{EXTENSION_NAME}}` directory
2. Manually register the extension in `extensions/extensions.json`:
```json
{
  "extensions": {
    "{{EXTENSION_NAME}}": {
      "version": "1.0.0",
      "enabled": false,
      "installPath": "extensions/{{EXTENSION_NAME}}"
    }
  }
}
```
3. Enable the extension:
```bash
glueful extensions:enable {{EXTENSION_NAME}}
```

### Verify Installation

Check that the extension is properly installed and enabled:
```bash
glueful extensions:list
```

## Configuration

Configure the extension by editing `extensions/{{EXTENSION_NAME}}/src/config.php`:

```php
return [
    'enabled' => true,
    'debug' => false,
    // Add your custom configuration options
    'option1' => 'value1',
    'option2' => 'value2',
];
```

## Usage

Describe how to use your extension here. Include code examples and API endpoints if applicable.

## Features

- List the main features of your extension
- Describe what makes it useful
- Mention any dependencies or requirements

## API Endpoints

If your extension provides API endpoints, document them here.

```http
GET /api/v1/extensions/{{EXTENSION_NAME}}/endpoint
POST /api/v1/extensions/{{EXTENSION_NAME}}/action
```

## Development

### Building for Distribution

To build this extension as a `.gluex` package:

```bash
php build/build.php {{EXTENSION_NAME}}
```

This will create `{{EXTENSION_NAME}}.gluex` in the `build/dist/` directory.

### Testing

Run extension tests:
```bash
php glueful test extensions/{{EXTENSION_NAME}}
```

### Extension Structure

```
{{EXTENSION_NAME}}/
├── {{EXTENSION_NAME}}.php    # Main extension class (with full implementation)
├── extension.json           # Extension metadata  
├── composer.json            # Composer dependencies
├── README.md               # This file
├── build.php               # Build script for .gluex packaging
├── assets/                 # Static assets (CSS, JS, images)
│   └── icon.png            # Extension icon
├── src/                    # Source code directory
│   ├── config.php          # Configuration settings
│   ├── Middleware/         # Custom middleware classes
│   │   └── {{EXTENSION_NAME}}Middleware.php
│   ├── Services/           # Business logic services
│   │   └── {{EXTENSION_NAME}}Service.php
│   ├── Providers/          # Service providers
│   ├── Listeners/          # Event listeners
│   └── Templates/          # Template files
├── migrations/             # Database migrations
│   └── 001_Create{{EXTENSION_NAME}}Table.php
└── screenshots/            # Extension screenshots
```

## Marketplace

This extension can be published to the Glueful Extensions Marketplace via GitHub releases.

### Publishing

1. Update version in `extension.json`
2. Build the extension package
3. Create a GitHub release with the `.gluex` file
4. Submit to the marketplace registry

## License

This extension is licensed under the MIT License.

## Author

{{AUTHOR_NAME}} <{{AUTHOR_EMAIL}}>

## Support

For support, please open an issue on the GitHub repository or contact the author directly.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request