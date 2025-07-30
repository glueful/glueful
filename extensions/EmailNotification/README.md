# Email Notification Extension for Glueful

## Overview

The EmailNotification extension provides a modern, enterprise-grade email delivery system for the Glueful Framework's notification system. Built on **Symfony Mailer**, it features robust multi-provider support, advanced queue integration, comprehensive monitoring, and extensible transport architecture.

> **🚀 Version 1.0.0**: Complete migration from PHPMailer to Symfony Mailer with enhanced performance, reliability, and modern provider bridge support.

## Features

- ✅ **Modern Symfony Mailer Integration** - Enterprise-grade email infrastructure
- ✅ **Multi-Provider Support** - Brevo, SendGrid, Mailgun, Amazon SES, Postmark, and custom providers
- ✅ **Provider Bridges** - Native API integrations for optimal performance and reliability
- ✅ **Advanced Queue System** - Integration with Glueful's database/Redis queue system
- ✅ **Failover & Load Balancing** - Multiple transport support with automatic failover
- ✅ **Extensible Architecture** - Support for any Symfony Mailer provider bridge
- ✅ **Advanced Template System** - Responsive templates with variable substitution and conditional logic
- ✅ **Performance Monitoring** - Rate limiting, metrics collection, and resource tracking
- ✅ **Enhanced Security** - Modern encryption, validation, and provider isolation
- ✅ **Developer Experience** - Clear error messages, debugging tools, and type safety

## Requirements

- PHP 8.2 or higher with strict typing
- Glueful Framework 0.29.0 or higher
- OpenSSL PHP extension
- Symfony Mailer (included)
- Composer for provider bridge dependencies

## Installation

### Automatic Installation (Recommended)

```bash
# Enable the extension if it's already present
php glueful extensions enable EmailNotification

# Verify installation
php glueful extensions list
php glueful extensions info EmailNotification
```

### Provider Bridge Installation

Install required Symfony provider bridges based on your email providers:

```bash
# For Brevo (Sendinblue)
composer require symfony/brevo-mailer

# For SendGrid
composer require symfony/sendgrid-mailer

# For Mailgun
composer require symfony/mailgun-mailer

# For Amazon SES
composer require symfony/amazon-mailer

# For Postmark
composer require symfony/postmark-mailer
```

## Configuration

### Environment Variables

Configure your email providers in your `.env` file:

```env
# Email Provider Selection
MAIL_MAILER=brevo

# Brevo Configuration (Sendinblue)
BREVO_TRANSPORT=brevo+smtp         # or brevo+api
BREVO_API_KEY=your-brevo-api-key
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password

# Generic SMTP Configuration
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls               # tls, ssl, or none
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-app-password

# Email Addresses
MAIL_FROM=noreply@example.com
MAIL_FROM_NAME=Your Application

# Performance & Queue Settings  
MAIL_QUEUE_ENABLED=true
EMAIL_RATE_LIMIT_PER_MINUTE=60
EMAIL_DEBUG=false
```

### Services Configuration

Configure multiple email providers in `config/services.php`:

```php
return [
    'mail' => [
        'default' => env('MAIL_MAILER', 'smtp'),
        
        'mailers' => [
            // Generic SMTP
            'smtp' => [
                'transport' => 'smtp',
                'host' => env('MAIL_HOST'),
                'port' => env('MAIL_PORT', 587),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'username' => env('MAIL_USERNAME'),
                'password' => env('MAIL_PASSWORD'),
            ],
            
            // Brevo (Sendinblue) - API or SMTP
            'brevo' => [
                'transport' => env('BREVO_TRANSPORT', 'brevo+api'),
                'key' => env('BREVO_API_KEY'),
                'username' => env('MAIL_USERNAME'),
                'password' => env('MAIL_PASSWORD'),
                'dsn' => env('BREVO_DSN'), // Override for custom DSN
            ],
            
            // SendGrid
            'sendgrid' => [
                'transport' => 'sendgrid+api',
                'key' => env('SENDGRID_API_KEY'),
            ],
            
            // Mailgun
            'mailgun' => [
                'transport' => 'mailgun+api',
                'domain' => env('MAILGUN_DOMAIN'),
                'key' => env('MAILGUN_SECRET'),
                'region' => env('MAILGUN_REGION', 'us'),
            ],
            
            // Amazon SES
            'ses' => [
                'transport' => 'ses+api',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ],
            
            // Postmark
            'postmark' => [
                'transport' => 'postmark+api',
                'token' => env('POSTMARK_TOKEN'),
            ],
        ],
        
        'from' => [
            'address' => env('MAIL_FROM', 'noreply@example.com'),
            'name' => env('MAIL_FROM_NAME', 'Glueful Application'),
        ],
        
        // Failover configuration
        'failover' => [
            'mailers' => explode(',', env('MAIL_FAILOVER_MAILERS', '')),
        ],
    ],
];
```

### Custom Provider Support

The extension supports any Symfony Mailer provider bridge through three methods:

#### 1. Custom DSN (Most Flexible)
```php
'custom_provider' => [
    'transport' => 'custom',
    'dsn' => 'mandrill+api://your-api-key@default',
],
```

#### 2. Auto-Configuration (Standard Patterns)
```php
'office365' => [
    'transport' => 'office365+smtp',
    'username' => 'user@company.com',
    'password' => 'password',
    // Auto-builds: office365+smtp://user:password@default
],
```

#### 3. Explicit Support (Built-in)
Already supported providers work without additional configuration.

## Usage

### Basic Email Sending

The extension integrates seamlessly with Glueful's notification system:

```php
use Glueful\Notifications\NotificationService;

$notificationService = container()->get(NotificationService::class);

// Simple email notification
$notificationService->send(
    'account_activity',
    $user,
    'Account Login Alert',
    [
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'timestamp' => '2024-06-21 14:30:00',
    ],
    ['channels' => ['email']]
);
```

### Template-Based Emails

Use professional templates for rich email experiences:

```php
// Email verification with OTP using verification template
$result = $notificationService->send(
    'email_verification',
    $notifiable,
    'Verify your email address',
    [
        'otp' => '123456',
        'expiry_minutes' => 15,
        'template_name' => 'verification'
    ],
    ['channels' => ['email']]
);

// Password reset with OTP using password-reset template
$result = $notificationService->send(
    'password_reset',
    $notifiable,
    'Password Reset Code',
    [
        'name' => $user->getFirstName(),
        'otp' => '654321',
        'expiry_minutes' => 15,
        'template_name' => 'password-reset'
    ],
    ['channels' => ['email']]
);

// Welcome email using welcome template
$result = $notificationService->send(
    'user_welcome',
    $user,
    'Welcome to Our Platform',
    [
        'user_name' => $user->getName(),
        'welcome_message' => 'Thank you for joining us!',
        'get_started_url' => 'https://example.com/onboarding',
        'template_name' => 'welcome'
    ],
    ['channels' => ['email']]
);

// Alert notification using alert template
$result = $notificationService->send(
    'security_alert',
    $user,
    'Security Alert',
    [
        'alert_type' => 'login_from_new_device',
        'message' => 'Your account was accessed from a new device.',
        'location' => 'San Francisco, CA',
        'device' => 'iPhone 13',
        'timestamp' => '2024-06-21 14:30:00',
        'template_name' => 'alert'
    ],
    ['channels' => ['email']]
);
```

**Key Benefits of this Approach:**

- ✅ **Clean and Simple** - Minimal code required for template-based emails
- ✅ **Automatic Global Variables** - Variables like `app_name`, `current_year`, `logo_url` are automatically available
- ✅ **Template Mappings** - Use friendly template names that map to actual template files
- ✅ **No Duplication** - Each piece of data specified only once
- ✅ **Type Safety** - All template variables are validated and type-checked

**Global Variables Available in All Templates:**
```php
// These are automatically available without specifying them:
$globalVariables = [
    'app_name' => 'Glueful Application',
    'app_url' => 'https://example.com',
    'support_email' => 'support@example.com',
    'logo_url' => 'https://brand.glueful.com/logo.png',
    'current_year' => '2024',
    'company_name' => 'Your Company'
];
```

### Advanced Email Features

Leverage Symfony Mailer's advanced capabilities:

```php
// Email with attachments, custom headers, and advanced features
$notificationService->send(
    'invoice_generated',
    $customer,
    'Your Invoice #' . $invoice->number,
    [
        'invoice_number' => $invoice->number,
        'amount' => $invoice->total,
        'due_date' => $invoice->due_date,
        'embedImages' => [
            'logo' => '/path/to/logo.png',
        ],
    ],
    [
        'channels' => ['email'],
        'email_options' => [
            'attachments' => [
                [
                    'path' => $invoice->pdf_path,
                    'name' => 'Invoice-' . $invoice->number . '.pdf',
                    'contentType' => 'application/pdf'
                ]
            ],
            'cc' => ['accounting@example.com'],
            'bcc' => ['archive@example.com'],
            'priority' => 'high',
            'headers' => [
                'X-Invoice-ID' => $invoice->id,
                'X-Customer-ID' => $customer->id,
            ],
            'returnPath' => 'bounces@example.com',
        ]
    ]
);
```

## Queue Integration

### Framework Queue System

The extension integrates with Glueful's robust queue system:

```php
// Emails are automatically queued when MAIL_QUEUE_ENABLED=true
// The framework's SendNotification job handles email processing

// Monitor queue size
use Glueful\Extensions\EmailNotification\EmailChannel;

$emailChannel = container()->get(EmailChannel::class);
$queueSize = $emailChannel->getQueueSize(); // Returns emails in queue

// Start queue workers
// php glueful queue:work --queue=emails
```

### Queue Configuration

Configure email queue processing:

```php
// In config/queue.php
'queues' => [
    'emails' => [
        'workers' => env('EMAIL_QUEUE_WORKERS', 2),
        'max_workers' => env('EMAIL_QUEUE_MAX_WORKERS', 4),
        'priority' => 5,
        'timeout' => env('EMAIL_QUEUE_TIMEOUT', 120),
        'auto_scale' => true,
    ],
],
```

## Transport Features

### Multi-Transport Support

Configure failover and load balancing:

```php
// Failover configuration
'failover' => [
    'mailers' => ['brevo', 'sendgrid', 'smtp'],
],

// Round-robin load balancing
'round_robin' => [
    'mailers' => ['ses', 'mailgun'],
],
```

### Transport Health Monitoring

```php
use Glueful\Extensions\EmailNotification\TransportFactory;

// Check available providers
$providers = TransportFactory::getAvailableProviders();
// Returns status of all Symfony provider bridges

// Verify transport configuration
$emailChannel->isAvailable(); // Returns true if transport is configured
```

## Template System

The extension provides a flexible template system with built-in responsive templates and support for custom templates.

### Built-in Templates

The extension includes 5 professionally designed, responsive email templates:

#### 1. Default Template (`default.html`)
- **Use Case**: General notifications, alerts, and multi-purpose emails
- **Features**: OTP support, action buttons, customizable styling
- **Variables**: `{{subject}}`, `{{message}}`, `{{action_url}}`, `{{action_text}}`, `{{otp_code}}`

#### 2. Welcome Template (`welcome.html`)
- **Use Case**: User onboarding and welcome emails
- **Features**: Friendly greeting, getting started guidance
- **Variables**: `{{user_name}}`, `{{app_name}}`, `{{welcome_message}}`, `{{get_started_url}}`

#### 3. Alert Template (`alert.html`) 
- **Use Case**: Security alerts, important notifications, warnings
- **Features**: Attention-grabbing design, urgency indicators
- **Variables**: `{{alert_type}}`, `{{message}}`, `{{timestamp}}`, `{{action_required}}`

#### 4. Password Reset Template (`password-reset.html`)
- **Use Case**: Password reset functionality
- **Features**: Secure reset process, expiry warnings
- **Variables**: `{{user_name}}`, `{{reset_url}}`, `{{expiry_time}}`, `{{security_tip}}`

#### 5. Verification Template (`verification.html`)
- **Use Case**: Account verification, email confirmation
- **Features**: Verification codes, confirmation links
- **Variables**: `{{user_name}}`, `{{verification_code}}`, `{{verification_url}}`, `{{expiry_time}}`

### Custom Templates

You can add your own email templates and customize the template system through configuration.

#### Template Configuration

Configure custom templates in `config/services.php`:

```php
'mail' => [
    'templates' => [
        // Primary template directory (defaults to extension's templates)
        'path' => env('MAIL_TEMPLATES_PATH', dirname(__DIR__) . '/extensions/EmailNotification/src/Templates/html'),
        
        // Additional custom template directories (checked in order)
        'custom_paths' => [
            // Framework's mail templates
            dirname(__DIR__) . '/resources/mail',
            // Your custom templates directory
            dirname(__DIR__) . '/templates/email',
        ],
        
        // Template caching for performance
        'cache_enabled' => env('MAIL_TEMPLATE_CACHE', true),
        'cache_path' => env('MAIL_TEMPLATE_CACHE_PATH', dirname(__DIR__) . '/storage/cache/mail-templates'),
        
        // Layout and partials
        'default_layout' => env('MAIL_DEFAULT_LAYOUT', 'layout'),
        'partials_directory' => 'partials',
        
        // Template file extension
        'extension' => '.html',
        
        // Custom template mappings (aliases)
        'mappings' => [
            // Map friendly names to actual template files
            'user_welcome' => 'onboarding/welcome',
            'password_reset' => 'auth/reset-password',
            'invoice' => 'billing/invoice-generated',
        ],
        
        // Global variables available to all templates
        'global_variables' => [
            'app_name' => env('APP_NAME', 'Glueful Application'),
            'app_url' => env('BASE_URL', 'https://example.com'),
            'support_email' => env('MAIL_SUPPORT_EMAIL', 'support@example.com'),
            'logo_url' => env('MAIL_LOGO_URL', 'https://brand.glueful.com/logo.png'),
            'current_year' => date('Y'),
            'company_name' => env('COMPANY_NAME', 'Your Company'),
        ],
    ],
],
```

#### Creating Custom Templates

1. **Create Template Directory Structure**:
   ```
   resources/mail/
   ├── custom-welcome.html
   ├── invoice.html
   ├── newsletter.html
   └── partials/
       ├── layout.html
       ├── header.html
       └── footer.html
   ```

2. **Custom Template Example** (`resources/mail/invoice.html`):
   ```html
   <!DOCTYPE html>
   <html>
   <head>
       <meta charset="UTF-8">
       <title>{{subject}}</title>
       <style>
           .invoice-header { background: #f8f9fa; padding: 20px; }
           .invoice-details { margin: 20px 0; }
           .total { font-weight: bold; font-size: 18px; }
       </style>
   </head>
   <body>
       <div class="invoice-header">
           <h1>{{app_name}}</h1>
           <h2>Invoice #{{invoice_number}}</h2>
       </div>
       
       <div class="invoice-details">
           <p>Dear {{customer_name}},</p>
           <p>Your invoice is ready for review.</p>
           
           <table>
               <tr><td>Invoice Number:</td><td>{{invoice_number}}</td></tr>
               <tr><td>Amount:</td><td class="total">${{amount}}</td></tr>
               <tr><td>Due Date:</td><td>{{due_date}}</td></tr>
           </table>
           
           <p><a href="{{invoice_url}}">View Invoice Online</a></p>
       </div>
       
       {{> footer}}
   </body>
   </html>
   ```

3. **Using Custom Templates**:
   ```php
   // Use by filename
   $notificationService->sendWithTemplate(
       'invoice_generated',
       $customer,
       'invoice', // Uses resources/mail/invoice.html
       [
           'invoice_number' => 'INV-2024-001',
           'customer_name' => $customer->name,
           'amount' => '299.99',
           'due_date' => '2024-07-15',
           'invoice_url' => 'https://app.com/invoices/123',
       ]
   );
   
   // Use with mapping alias
   $notificationService->sendWithTemplate(
       'user_registration',
       $user,
       'user_welcome', // Maps to onboarding/welcome.html via template mappings
       [
           'user_name' => $user->name,
           'activation_url' => $activationUrl,
       ]
   );
   ```

#### Template Features

**Variable Substitution**:
- Simple variables: `{{variable_name}}`
- Default values: `{{variable_name|default_value}}`
- Nested variables: `{{user.profile.name}}`

**Conditional Blocks**:
```html
{{#if show_discount}}
<div class="discount">
    <p>Special offer: {{discount_percent}}% off!</p>
</div>
{{/if}}
```

**Partials (Template Includes)**:
```html
{{> header}}
<div class="content">
    <!-- Your content -->
</div>
{{> footer}}
```

**Global Variables**:
All templates automatically have access to configured global variables like `{{app_name}}`, `{{logo_url}}`, `{{current_year}}`, etc.

#### Template Inheritance

Create a base layout in `partials/layout.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{subject}} - {{app_name}}</title>
    <style>
        /* Your base styles */
    </style>
</head>
<body>
    <header>
        <img src="{{logo_url}}" alt="{{app_name}}">
    </header>
    
    <main>
        {{{content}}} <!-- Template content goes here -->
    </main>
    
    <footer>
        <p>&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </footer>
</body>
</html>
```

Templates without `<!DOCTYPE html>` automatically use this layout.

## Performance Features

### Rate Limiting

Protect against email abuse with configurable rate limits:

```env
EMAIL_RATE_LIMIT_PER_MINUTE=60    # Max 60 emails per minute
EMAIL_RATE_LIMIT_PER_HOUR=1000    # Max 1000 emails per hour
EMAIL_RATE_LIMIT_PER_DAY=10000    # Max 10000 emails per day
```

### Connection Optimization

- **Provider Bridges**: Direct API integrations for better performance
- **Connection Pooling**: Efficient SMTP connection management
- **Batch Processing**: Optimized for high-volume sending
- **Memory Management**: Proper object lifecycle management

## Security Features

### Modern Security Standards

- **Symfony Mailer Security**: Built on Symfony's security standards
- **Provider Isolation**: Isolated transport creation prevents configuration leakage
- **Input Validation**: Enhanced validation for transport configurations
- **Error Sanitization**: Sanitized error messages to prevent credential exposure

### SSL/TLS Encryption

```env
MAIL_ENCRYPTION=tls        # Enable TLS encryption
EMAIL_SSL_VERIFY=true      # Verify SSL certificates
```

## Monitoring and Debugging

### Health Monitoring

```php
use Glueful\Extensions\EmailNotification\EmailNotification;

$extension = container()->get(EmailNotification::class);

// Check extension health
$health = $extension->checkHealth();
// Returns: transport status, configuration validity, queue health

// Get email metrics
$provider = container()->get('Glueful\Extensions\EmailNotification\EmailNotificationProvider');
$metrics = $provider->getMetrics();
// Returns: success rates, delivery times, queue statistics
```

### Debug Mode

Enable detailed logging for troubleshooting:

```env
EMAIL_DEBUG=true
APP_DEBUG=true
MAIL_LOG_CHANNEL=mail
```

## Migration from PHPMailer

### Breaking Changes in v1.0.0

1. **Transport Configuration**: New multi-mailer structure required
2. **Queue System**: File-based queue replaced with framework queue
3. **Provider Specification**: Explicit transport types required (e.g., `brevo+api`)
4. **Dependencies**: Symfony Mailer replaces PHPMailer

### Migration Steps

1. **Update Dependencies**:
   ```bash
   composer require symfony/mailer symfony/brevo-mailer
   ```

2. **Update Configuration**:
   ```php
   // OLD (PHPMailer)
   'mail' => [
       'host' => 'smtp.brevo.com',
       'port' => 587,
       'username' => 'user@domain.com',
       'password' => 'password',
   ]
   
   // NEW (Symfony Mailer)
   'mail' => [
       'default' => 'brevo',
       'mailers' => [
           'brevo' => [
               'transport' => 'brevo+smtp',
               'username' => env('MAIL_USERNAME'),
               'password' => env('MAIL_PASSWORD'),
           ],
       ],
   ]
   ```

3. **Update Environment Variables**:
   ```env
   MAIL_MAILER=brevo
   BREVO_TRANSPORT=brevo+smtp  # or brevo+api
   MAIL_ENCRYPTION=tls         # not MAIL_SECURE
   ```

4. **Test Configuration**:
   ```bash
   # Test email sending
   php glueful test:email
   
   # Check extension health
   php glueful extensions info EmailNotification
   ```

## Troubleshooting

### Common Issues

1. **Transport Creation Errors**
   - Verify provider bridge is installed: `composer require symfony/brevo-mailer`
   - Check configuration structure in `services.php`
   - Review error logs for specific transport issues

2. **Queue Not Processing**
   - Ensure `MAIL_QUEUE_ENABLED=true`
   - Start queue workers: `php glueful queue:work --queue=emails`
   - Check queue configuration in `config/queue.php`

3. **Provider Bridge Issues**
   - Verify API credentials are correct
   - Check transport specification (e.g., `brevo+api` vs `brevo+smtp`)
   - Review provider-specific documentation

4. **Configuration Path Issues**
   - Ensure extension is enabled in `config/services.php` extensions
   - Verify `services.mail` configuration exists
   - Check `from` address is configured

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

## Provider-Specific Setup

### Brevo (Sendinblue)

```env
MAIL_MAILER=brevo
BREVO_TRANSPORT=brevo+api           # API mode (recommended)
# BREVO_TRANSPORT=brevo+smtp        # SMTP mode
BREVO_API_KEY=your-brevo-api-key
MAIL_USERNAME=your-smtp-login
MAIL_PASSWORD=your-smtp-key
```

### SendGrid

```env
MAIL_MAILER=sendgrid
SENDGRID_API_KEY=your-sendgrid-key
```

### Amazon SES

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

### Mailgun

```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your-domain.mailgun.org
MAILGUN_SECRET=your-mailgun-key
MAILGUN_REGION=us                  # or eu
```

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the EmailNotification extension:
- Create an issue in the repository
- Consult the [Symfony Mailer documentation](https://symfony.com/doc/current/mailer.html)
- Check the extension health monitoring for diagnostics
- Review the migration guide for PHPMailer → Symfony Mailer issues

---

**📚 Documentation**: [Glueful Framework Documentation](https://docs.glueful.com)  
**🔧 Provider Bridges**: [Symfony Mailer Bridges](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport)  
**⚡ Queue System**: [Glueful Queue Documentation](https://docs.glueful.com/queue)