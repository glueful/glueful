# Glueful Extension Metadata Standard

## Overview

This document defines the comprehensive metadata standard for Glueful extensions. The metadata standard is designed to support the extension marketplace, provide better dependency management, and improve the overall extension management experience. This specification builds on the existing metadata system while adding new fields needed for enhanced functionality.

## Metadata Structure

The metadata is returned by the `getMetadata()` method in each extension class. The method must return an associative array with the following structure:

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name of the extension |
| `description` | string | Brief description of the extension (1-2 sentences) |
| `version` | string | Semantic version number (e.g., "1.0.0") |
| `author` | string | Author name or organization |
| `requires` | object | Dependency requirements (see below) |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `homepage` | string | URL to the extension's homepage or repository |
| `documentation` | string | URL to the extension's documentation |
| `support` | object | Support contact information (see below) |
| `license` | string | License identifier (e.g., "MIT", "GPL-3.0") |
| `keywords` | array | Array of keywords for searching and categorization |
| `category` | string | Main category for the extension |
| `screenshots` | array | Array of screenshot objects (see below) |
| `features` | array | Array of feature descriptions |
| `compatibility` | object | Compatibility information (see below) |
| `settings` | object | Settings configuration (see below) |
| `resources` | array | Additional resources like documentation pages |
| `changelog` | array | Version history with changes |
| `rating` | object | Rating information (populated by the system) |
| `stats` | object | Usage statistics (populated by the system) |

### Nested Objects

#### `requires` Object (Required)

```php
'requires' => [
    'glueful' => '>=1.0.0',         // Required Glueful version (semver)
    'php' => '>=8.1.0',             // Required PHP version (semver)
    'extensions' => [               // Required extensions
        'OtherExtension',           // Extension names
        'AnotherExtension'
    ],
    'dependencies' => [             // External dependencies
        'package/name' => '>=1.0.0' // Composer package requirements
    ]
]
```

#### `support` Object (Optional)

```php
'support' => [
    'email' => 'support@example.com',   // Support email
    'issues' => 'https://github.com/user/repo/issues', // Issue tracker
    'forum' => 'https://forum.example.com',           // Community forum
    'docs' => 'https://docs.example.com',             // Documentation URL
    'chat' => 'https://chat.example.com'              // Chat or support channel
]
```

#### `screenshots` Array (Optional)

```php
'screenshots' => [
    [
        'title' => 'Dashboard View',        // Screenshot title
        'description' => 'Admin dashboard', // Screenshot description
        'url' => 'path/to/screenshot.png',  // URL to image
        'thumbnail' => 'path/to/thumb.png'  // URL to thumbnail (optional)
    ],
    // Additional screenshots...
]
```

#### `compatibility` Object (Optional)

```php
'compatibility' => [
    'browsers' => ['Chrome', 'Firefox', 'Safari'], // Compatible browsers
    'environments' => ['production', 'development'], // Recommended environments
    'platforms' => ['macOS', 'Windows', 'Linux'],   // Compatible platforms
    'conflicts' => [                    // Known conflicts with other extensions
        'ConflictingExtension' => 'Conflicts due to shared resource usage'
    ]
]
```

#### `settings` Object (Optional)

```php
'settings' => [
    'configurable' => true,      // Whether extension has configurable settings
    'has_admin_ui' => true,      // Whether extension adds to the admin UI
    'setup_required' => true,    // Whether post-installation setup is required
    'default_config' => [        // Default configuration values
        'setting1' => 'value1',
        'setting2' => true
    ],
    'config_schema' => [         // JSON Schema for configuration validation
        // Schema definition...
    ]
]
```

#### `changelog` Array (Optional)

```php
'changelog' => [
    [
        'version' => '1.1.0',
        'date' => '2025-04-15',
        'changes' => [
            'Added new feature X',
            'Fixed bug Y',
            'Improved performance of Z'
        ]
    ],
    // More version entries...
]
```

## Example Implementation

Here's a complete example of an extension implementing the metadata standard:

```php
/**
 * Get extension metadata
 * 
 * @return array Extension metadata for admin interface and marketplace
 */
public static function getMetadata(): array
{
    return [
        // Required fields
        'name' => 'Social Login',
        'description' => 'Provides social authentication through Google, Facebook and GitHub',
        'version' => '1.2.0',
        'author' => 'Glueful Extensions Team',
        'requires' => [
            'glueful' => '>=1.0.0',
            'php' => '>=8.1.0',
            'extensions' => [],
            'dependencies' => [
                'league/oauth2-client' => '^2.6'
            ]
        ],
        
        // Optional fields
        'homepage' => 'https://glueful.example.com/extensions/social-login',
        'documentation' => 'https://docs.glueful.example.com/extensions/social-login',
        'license' => 'MIT',
        'keywords' => ['authentication', 'social', 'login', 'oauth', 'security'],
        'category' => 'authentication',
        
        'screenshots' => [
            [
                'title' => 'Login Page',
                'description' => 'Social login buttons on the login page',
                'url' => 'screenshots/login-page.png',
                'thumbnail' => 'screenshots/login-page-thumb.png'
            ],
            [
                'title' => 'Settings Panel',
                'description' => 'Configure social login providers',
                'url' => 'screenshots/settings-panel.png'
            ]
        ],
        
        'features' => [
            'Google authentication integration',
            'Facebook authentication integration',
            'GitHub authentication integration',
            'Customizable login buttons',
            'Provider-specific user profiles'
        ],
        
        'compatibility' => [
            'browsers' => ['Chrome', 'Firefox', 'Safari', 'Edge'],
            'environments' => ['production', 'development'],
            'conflicts' => []
        ],
        
        'settings' => [
            'configurable' => true,
            'has_admin_ui' => true,
            'setup_required' => true,
            'default_config' => [
                'enabled_providers' => ['google', 'github'],
                'button_style' => 'large'
            ]
        ],
        
        'support' => [
            'email' => 'extensions@glueful.example.com',
            'docs' => 'https://docs.glueful.example.com/extensions/social-login',
            'issues' => 'https://github.com/glueful/social-login/issues'
        ],
        
        'changelog' => [
            [
                'version' => '1.2.0',
                'date' => '2025-03-10',
                'changes' => [
                    'Added GitHub authentication provider',
                    'Improved error handling for failed logins',
                    'Added customization options for login buttons'
                ]
            ],
            [
                'version' => '1.1.0',
                'date' => '2025-01-15',
                'changes' => [
                    'Added Facebook authentication provider',
                    'Fixed user profile synchronization issues'
                ]
            ],
            [
                'version' => '1.0.0',
                'date' => '2024-11-20',
                'changes' => [
                    'Initial release with Google authentication'
                ]
            ]
        ]
    ];
}
```

## Validation Rules

The ExtensionsManager will validate the metadata against these rules:

1. **Required fields** must be present and have the correct types
2. **Version format** must follow semantic versioning (X.Y.Z)
3. **URLs** must be valid and accessible
4. **Dependency versions** must follow Composer's version constraint syntax
5. **Screenshots** must point to existing files (relative to extension directory)
6. **Category** must be one of the predefined categories
7. **License** should be a valid SPDX license identifier

## Backward Compatibility

For backward compatibility, the ExtensionsManager will:

1. Only require the basic fields (name, description, version, author, requires)
2. Provide default values for missing fields
3. Validate only required fields for existing extensions
4. Not trigger errors for missing optional fields

## System-Populated Fields

The following fields are populated by the system and should not be included by extension developers:

```php
// These fields are populated by the system:
'rating' => [
    'average' => 4.7,           // Average rating (1-5)
    'count' => 120,             // Number of ratings
    'distribution' => [5=>80, 4=>30, 3=>8, 2=>1, 1=>1] // Rating distribution
],

'stats' => [
    'downloads' => 1500,        // Total downloads
    'active_installations' => 800, // Estimated active installations
    'first_published' => '2024-11-20', // First publication date
    'last_updated' => '2025-03-10'    // Last update date
]
```

## Implementation Guidelines

### For Extension Developers

1. Implement the `getMetadata()` method in your extension class
2. Include all required fields and as many optional fields as relevant
3. Store screenshots in a `screenshots/` subdirectory of your extension
4. Provide comprehensive dependency information
5. Keep the changelog updated with each release

### For Core System Implementation

1. Enhance the ExtensionsManager to use and validate the extended metadata
2. Update the admin UI to display the rich metadata
3. Implement the marketplace functionality based on this metadata
4. Use the metadata for dependency resolution
5. Leverage the compatibility information for system checks

## Migration Path

Existing extensions should gradually adopt the new metadata standard by:

1. First adding the required fields if they're missing
2. Then adding the most important optional fields:
   - `homepage` and `documentation`
   - `screenshots`
   - `features`
   - `settings`
3. Finally adding the remaining fields over time

The system should handle gracefully both the old minimal metadata format and this new comprehensive format.