# OAuthServer Extension for Glueful

OAuth server implementation that handles different grant types

## Installation

1. Copy the extension files to your `extensions/OAuthServer` directory
2. Enable the extension in `config/extensions.php`:

```php
return [
    'enabled' => [
        // other extensions...
        'OAuthServer',
    ],
];
```

3. Run migrations if needed:

```bash
php glueful db:migrate
```

## Configuration

Configure the extension by editing `extensions/OAuthServer/config.php`:

```php
return [
    'option1' => 'value1',
    'option2' => 'value2',
    // Add your configuration options
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

## License

This extension is licensed under the same license as the Glueful framework.

## Author

Glueful Team <>

## Support

For support, please open an issue on the GitHub repository or contact the author directly.