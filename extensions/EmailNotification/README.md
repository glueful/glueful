# Email Notification Extension for Glueful

## Overview

The EmailNotification extension provides a comprehensive email delivery system for the Glueful Framework's notification system. It features enterprise-grade SMTP support, advanced template management, performance monitoring, and robust error handling with retry mechanisms.

## Features

- ✅ **Enterprise SMTP Support** - PHPMailer integration with multiple provider support
- ✅ **Advanced Template System** - Responsive templates with variable substitution and conditional logic
- ✅ **Multiple Email Providers** - SMTP, Amazon SES, Mailgun, and custom configurations
- ✅ **Performance Monitoring** - Rate limiting, metrics collection, and resource tracking
- ✅ **HTML & Plain Text** - Automatic dual-format email generation
- ✅ **Attachment Support** - File attachments, CC, BCC, and reply-to functionality
- ✅ **Event-Driven Architecture** - Email lifecycle events and listeners
- ✅ **Queue Integration** - Asynchronous email sending with retry mechanisms
- ✅ **Security Features** - SSL/TLS encryption, domain restrictions, content scanning
- ✅ **Health Monitoring** - Comprehensive diagnostics and monitoring

## Requirements

- PHP 8.2 or higher
- Glueful Framework 0.27.0 or higher
- OpenSSL PHP extension
- PHPMailer (included with Glueful)

## Installation

### Automatic Installation (Recommended)

**Option 1: Using the CLI**

```bash
# Enable the extension if it's already present
php glueful extensions enable EmailNotification

# Or install from external source
php glueful extensions install <source-url-or-file> EmailNotification
```

**Option 2: Manual Installation**

1. Copy the EmailNotification extension to your `extensions/` directory
2. Add to `extensions/extensions.json`:
```json
{
  "extensions": {
    "EmailNotification": {
      "version": "0.21.0",
      "enabled": true,
      "installPath": "extensions/EmailNotification",
      "type": "optional",
      "description": "Email notification channel with enterprise features",
      "author": "glueful-team"
    }
  }
}
```

3. Enable the extension:
```bash
php glueful extensions enable EmailNotification
```

### Verify Installation

Check that the extension is properly enabled and registered:

```bash
php glueful extensions list
php glueful extensions info EmailNotification
```

## Configuration

### Environment Variables

Configure the extension using environment variables in your `.env` file:

```env
# SMTP Configuration
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls                    # tls, ssl, or none

# Email Addresses
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=Your Application
MAIL_REPLY_TO_ADDRESS=support@example.com
MAIL_REPLY_TO_NAME=Support Team

# Application
APP_NAME=Your Application Name

# Advanced Configuration
EMAIL_RATE_LIMIT_PER_MINUTE=60
EMAIL_RATE_LIMIT_PER_HOUR=1000
EMAIL_RATE_LIMIT_PER_DAY=10000
EMAIL_QUEUE_ENABLED=true
EMAIL_DEBUG_MODE=false
EMAIL_SSL_VERIFY=true
```

### Extension Configuration

Override settings in the extension's `config.php` or through the notification system:

```php
// Custom configuration in config/mail.php or extension config
return [
    'smtp' => [
        'host' => env('MAIL_HOST', 'smtp.example.com'),
        'port' => env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'ssl_verify' => env('EMAIL_SSL_VERIFY', true),
        'connection_timeout' => 30,
        'response_timeout' => 30,
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Notification System'),
    ],
    'rate_limiting' => [
        'enabled' => true,
        'per_minute' => env('EMAIL_RATE_LIMIT_PER_MINUTE', 60),
        'per_hour' => env('EMAIL_RATE_LIMIT_PER_HOUR', 1000),
        'per_day' => env('EMAIL_RATE_LIMIT_PER_DAY', 10000),
    ],
    'performance' => [
        'queue_enabled' => env('EMAIL_QUEUE_ENABLED', true),
        'connection_pooling' => true,
        'batch_processing' => true,
    ],
    'security' => [
        'allowed_domains' => [], // Empty array allows all domains
        'blocked_domains' => [],
        'content_scanning' => false,
    ]
];
```

## Usage

### Basic Email Sending

The extension integrates seamlessly with Glueful's notification system:

```php
use Glueful\Notifications\NotificationService;

$notificationService = container()->get(NotificationService::class);

// Simple email notification
$notificationService->send(
    'account_activity',              // notification type
    $user,                          // notifiable entity
    'Account Login Alert',          // subject
    [
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'timestamp' => '2024-06-21 14:30:00',
        'action_required' => true
    ],
    ['channels' => ['email']]       // options
);
```

### Template-Based Emails

Use pre-built or custom templates for rich email experiences:

```php
// Welcome email with template
$notificationService->sendWithTemplate(
    'user_welcome',                 // notification type
    $user,                         // recipient
    'welcome',                     // template name
    [
        'user_name' => $user->getName(),
        'app_name' => 'Your Amazing App',
        'welcome_message' => 'Welcome to our platform!',
        'get_started_url' => 'https://example.com/onboarding',
        'support_email' => 'support@example.com'
    ],
    ['channels' => ['email']]
);

// Password reset email
$notificationService->sendWithTemplate(
    'password_reset',
    $user,
    'password-reset',              // Uses password-reset.html template
    [
        'user_name' => $user->getName(),
        'reset_url' => $resetUrl,
        'expiry_time' => '24 hours',
        'security_tip' => 'Never share this link with anyone.'
    ],
    ['channels' => ['email']]
);
```

### Advanced Email Features

```php
// Email with attachments and custom headers
$notificationService->send(
    'invoice_generated',
    $customer,
    'Your Invoice #' . $invoice->number,
    [
        'invoice_number' => $invoice->number,
        'amount' => $invoice->total,
        'due_date' => $invoice->due_date
    ],
    [
        'channels' => ['email'],
        'email_options' => [
            'attachments' => [
                [
                    'path' => $invoice->pdf_path,
                    'name' => 'Invoice-' . $invoice->number . '.pdf',
                    'type' => 'application/pdf'
                ]
            ],
            'cc' => ['accounting@example.com'],
            'bcc' => ['archive@example.com'],
            'reply_to' => [
                'address' => 'billing@example.com',
                'name' => 'Billing Department'
            ],
            'headers' => [
                'X-Invoice-ID' => $invoice->id,
                'X-Customer-ID' => $customer->id
            ]
        ]
    ]
);
```

## Available Templates

The extension includes 5 professionally designed, responsive email templates:

### 1. Default Template (`default.html`)
- **Use Case**: General notifications, alerts, and multi-purpose emails
- **Features**: OTP support, action buttons, customizable styling
- **Variables**: `{{subject}}`, `{{message}}`, `{{action_url}}`, `{{action_text}}`, `{{otp_code}}`

### 2. Welcome Template (`welcome.html`)
- **Use Case**: User onboarding and welcome emails
- **Features**: Friendly greeting, getting started guidance
- **Variables**: `{{user_name}}`, `{{app_name}}`, `{{welcome_message}}`, `{{get_started_url}}`

### 3. Alert Template (`alert.html`)
- **Use Case**: Security alerts, important notifications, warnings
- **Features**: Attention-grabbing design, urgency indicators
- **Variables**: `{{alert_type}}`, `{{message}}`, `{{timestamp}}`, `{{action_required}}`

### 4. Password Reset Template (`password-reset.html`)
- **Use Case**: Password reset functionality
- **Features**: Secure reset process, expiry warnings
- **Variables**: `{{user_name}}`, `{{reset_url}}`, `{{expiry_time}}`, `{{security_tip}}`

### 5. Verification Template (`verification.html`)
- **Use Case**: Account verification, email confirmation
- **Features**: Verification codes, confirmation links
- **Variables**: `{{user_name}}`, `{{verification_code}}`, `{{verification_url}}`, `{{expiry_time}}`

## Advanced Template System

### Variable Substitution

The template engine supports sophisticated variable handling:

```php
// Basic variables
{{user_name}}              // Simple variable substitution
{{user.email}}             // Nested object properties
{{user_name|Guest}}        // Default values

// Conditional blocks
{{#if premium_user}}
    <div class="premium-content">
        Welcome, premium member!
    </div>
{{/if}}

// Partial includes
{{> header}}               // Include header.html partial
{{> footer}}               // Include footer.html partial
```

### Custom Template Creation

Create custom templates for specific use cases:

```php
use Glueful\Extensions\EmailNotification\EmailFormatter;

$formatter = new EmailFormatter();

// Register a custom template
$formatter->registerTemplate('custom_notification', [
    'html' => '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{{subject}}</title>
        </head>
        <body style="font-family: Arial, sans-serif;">
            {{> header}}
            <div style="padding: 20px;">
                <h2 style="color: #2c3e50;">{{title}}</h2>
                <p>{{message}}</p>
                {{#if show_button}}
                    <a href="{{button_url}}" style="background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
                        {{button_text}}
                    </a>
                {{/if}}
            </div>
            {{> footer}}
        </body>
        </html>
    ',
    'plain' => '{{title}}\n\n{{message}}\n\n{{#if show_button}}{{button_text}}: {{button_url}}{{/if}}'
]);
```

## Performance Features

### Rate Limiting

Protect against email abuse with configurable rate limits:

```php
// Rate limiting is automatically applied
// Configure limits in environment variables or config
EMAIL_RATE_LIMIT_PER_MINUTE=60    // Max 60 emails per minute
EMAIL_RATE_LIMIT_PER_HOUR=1000    // Max 1000 emails per hour
EMAIL_RATE_LIMIT_PER_DAY=10000    // Max 10000 emails per day
```

### Queue Integration

Asynchronous email processing for better performance:

```php
// Emails are automatically queued when EMAIL_QUEUE_ENABLED=true
// Use immediate sending for critical emails
$notificationService->send(
    'critical_alert',
    $user,
    'Urgent: Security Issue',
    $data,
    [
        'channels' => ['email'],
        'queue' => false  // Send immediately, bypass queue
    ]
);
```

### Batch Processing

Send multiple emails efficiently:

```php
// Batch sending (handled automatically by the extension)
$recipients = [$user1, $user2, $user3];
foreach ($recipients as $recipient) {
    $notificationService->send('newsletter', $recipient, 'Monthly Update', $data);
}
// Emails are automatically batched for optimal performance
```

## Monitoring and Analytics

### Health Monitoring

Monitor email system health:

```php
use Glueful\Extensions\EmailNotification\EmailNotification;

$extension = container()->get(EmailNotification::class);

// Check extension health
$health = $extension->getHealth();
// Returns: connection status, configuration validity, rate limits, etc.

// Get performance metrics
$metrics = $extension->getMetrics();
// Returns: success rates, delivery times, bounce rates, etc.
```

### Event Listeners

Monitor email lifecycle events:

```php
use Glueful\Extensions\EmailNotification\Listeners\EmailNotificationListener;

// Email events are automatically logged
// Events include: email.sending, email.sent, email.failed, email.bounced
```

## Security Features

### SSL/TLS Encryption

Secure email transmission:

```env
MAIL_ENCRYPTION=tls        # Enable TLS encryption
EMAIL_SSL_VERIFY=true      # Verify SSL certificates
```

### Domain Restrictions

Control email delivery:

```php
// In extension configuration
'security' => [
    'allowed_domains' => ['@example.com', '@company.org'],
    'blocked_domains' => ['@tempmail.com', '@spam.com'],
    'content_scanning' => true  // Optional content scanning
]
```

## Troubleshooting

### Common Issues

1. **Emails Not Sending**
   - Verify SMTP credentials and server connectivity
   - Check rate limiting settings
   - Ensure extension is enabled and properly configured

2. **Template Not Found**
   - Verify template files exist in `src/Templates/html/`
   - Check template name spelling
   - Ensure templates are properly registered

3. **Gmail/Google Workspace Issues**
   - Use App Passwords instead of account passwords
   - Enable 2-factor authentication
   - Check Google security settings

4. **Performance Issues**
   - Enable queue processing for high-volume sending
   - Configure connection pooling
   - Monitor rate limits and adjust as needed

### Debug Mode

Enable detailed logging for troubleshooting:

```env
EMAIL_DEBUG_MODE=true
APP_DEBUG=true
```

### Health Checks

Use built-in health checks to diagnose issues:

```bash
# Check email system health
curl -H "Authorization: Bearer your-token" \
     http://your-domain.com/health/email

# View email metrics
curl -H "Authorization: Bearer your-token" \
     http://your-domain.com/metrics/email
```

## Provider-Specific Configuration

### Amazon SES

```env
MAIL_HOST=email-smtp.us-east-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=your-ses-access-key
MAIL_PASSWORD=your-ses-secret-key
MAIL_ENCRYPTION=tls
```

### Mailgun

```env
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@your-domain.mailgun.org
MAIL_PASSWORD=your-mailgun-password
MAIL_ENCRYPTION=tls
```

### Office 365

```env
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=your-email@company.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
```

## Migration and Upgrades

When upgrading from previous versions:

1. **Backup existing templates** before upgrading
2. **Review configuration changes** in the new version
3. **Test email functionality** after upgrade
4. **Update custom templates** if using deprecated features

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the EmailNotification extension:
- Create an issue in the repository
- Consult the Glueful documentation
- Check the extension health monitoring for diagnostics