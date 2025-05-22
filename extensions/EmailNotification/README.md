# Email Notification Channel Extension

This extension adds email notification capabilities to the Glueful Notifications system.

## Features

- Sends notifications via email using SMTP (via PHPMailer)
- Supports both HTML and plain text email formats
- Includes responsive email templates out of the box
- Customizable template system with variable substitution
- Supports attachments, CC, and BCC
- Configurable from environment variables

## Installation

1. Ensure the extension is in your `extensions/EmailNotification` directory.

## Development Setup

When developing this extension outside the Glueful directory:

### Option 1: Using the Monorepo Setup (Recommended)

If you're working with the Glueful extensions monorepo:

1. Set up the monorepo environment first:
```bash
cd /path/to/glueful/extensions
php setup.php
```

2. Configure this extension:
```bash
php setup-extension.php EmailNotification
```

### Option 2: Standalone Extension Setup

If you're working with this extension as a standalone repository:

```bash
php setup.php
```

### Option 3: Manual Setup

1. Set the Glueful path environment variable:

```bash
# For bash/zsh
export GLUEFUL_PATH=/path/to/glueful

# For Windows Command Prompt
set GLUEFUL_PATH=C:\path\to\glueful

# For PowerShell
$env:GLUEFUL_PATH = "C:\path\to\glueful"
```

2. Install dependencies:

```bash
composer install
```
2. Update your `config/extensions.php` file to enable the extension:

```php
return [
    // Other extensions...
    'email_notification' => [
        'provider' => \Glueful\Extensions\EmailNotification\EmailNotificationProvider::class,
        'enabled' => true,
    ],
];
```

3. Configure your email settings in your environment variables or directly in the extension's config file.

## Configuration

The extension uses the following environment variables (with fallbacks to default values):

| Environment Variable | Description | Default |
|----------------------|-------------|---------|
| MAIL_HOST | SMTP server hostname | smtp.example.com |
| MAIL_PORT | SMTP server port | 587 |
| MAIL_USERNAME | SMTP username | - |
| MAIL_PASSWORD | SMTP password | - |
| MAIL_ENCRYPTION | Encryption (tls, ssl) | tls |
| MAIL_FROM_ADDRESS | From email address | noreply@example.com |
| MAIL_FROM_NAME | From name | Notification System |
| MAIL_REPLY_TO_ADDRESS | Reply-to address | - |
| MAIL_REPLY_TO_NAME | Reply-to name | - |
| APP_NAME | Application name (for templates) | Glueful Application |

You can also override the configuration in your `config/extensions.php` file:

```php
return [
    // Other extensions...
    'email_notification' => [
        'provider' => \Glueful\Extensions\EmailNotification\EmailNotificationProvider::class,
        'enabled' => true,
        'config' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'your-email@gmail.com',
            'password' => 'your-app-password',
            'from' => [
                'address' => 'notifications@yourdomain.com',
                'name' => 'Your Application',
            ],
            // Other config options...
        ],
    ],
];
```

## Usage

### Basic Usage

The email channel will be automatically registered with the notification system. You can use it like any other notification channel:

```php
$notificationService->send(
    'account_activity',      // notification type
    $user,                   // notifiable entity (must implement Notifiable)
    'Your account was accessed',  // subject
    [                        // additional data
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'time' => '2025-04-24 14:30:00'
    ],
    [                        // options
        'channels' => ['email'],
    ]
);
```

### Using Templates

The extension comes with three pre-built templates: `default`, `alert`, and `welcome`. You can use them with the `sendWithTemplate` method:

```php
$notificationService->sendWithTemplate(
    'welcome',              // notification type
    $user,                  // notifiable entity
    'welcome',              // template name
    [                       // template data
        'name' => $user->getName(),
        'app_name' => 'Your Amazing App',
        'message' => 'We\'re excited to have you join our platform!',
        'action_url' => 'https://example.com/get-started'
    ],
    [                       // options
        'channels' => ['email'],
    ]
);
```

### Creating Custom Templates

You can add your own templates by extending the `EmailFormatter` class:

```php
$formatter = new EmailFormatter();

// Register a custom template
$formatter->registerTemplate('password_reset', [
    'header' => $formatter->getTemplate('default', 'default')['header'],
    'body' => '
        <div style="color: #333333; font-size: 16px; line-height: 1.6em;">
            <p>Hello {{name}},</p>
            <p>We received a request to reset your password.</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{reset_url}}" style="background-color: #3490dc; border-radius: 3px; color: #ffffff; display: inline-block; font-size: 16px; font-weight: 400; line-height: 1.4; padding: 12px 24px; text-decoration: none; text-align: center;">
                    Reset Password
                </a>
            </div>
            <p>This link will expire in {{expiry}}.</p>
            <p>If you did not request a password reset, no further action is required.</p>
        </div>
    ',
    'footer' => $formatter->getTemplate('default', 'default')['footer']
]);

// Create a custom channel with the formatter
$channel = new EmailChannel([], $formatter);
```

## Requirements

- PHP 7.4 or higher
- PHPMailer (automatically available in Glueful)
- OpenSSL PHP extension

## Troubleshooting

If emails are not being sent:

1. Check that the extension is enabled in your configuration.
2. Verify that your SMTP credentials are correct.
3. Check your server's outgoing email capabilities.
4. If using Gmail, make sure you're using an App Password rather than your account password.
5. Enable debug mode in the configuration to see more detailed error information.