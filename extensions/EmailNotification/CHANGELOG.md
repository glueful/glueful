# Changelog

All notable changes to the EmailNotification extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Real-time email delivery tracking
- Advanced analytics dashboard for email metrics
- Email template builder with drag-and-drop interface
- Bounce and unsubscribe handling automation

## [1.0.0] - 2025-01-24

### 🚀 MAJOR MIGRATION: PHPMailer → Symfony Mailer

This release represents a complete architectural migration from PHPMailer to Symfony Mailer, providing modern email infrastructure with enhanced reliability, performance, and extensibility.

### Added

#### **🔧 Modern Transport System**
- **Symfony Mailer Integration**: Complete migration from PHPMailer to Symfony Mailer
- **Provider Bridge Support**: Native support for modern email providers
  - Brevo (Sendinblue) - API and SMTP modes
  - SendGrid API integration  
  - Mailgun API integration
  - Amazon SES API integration
  - Postmark API integration
- **Multi-Transport Architecture**: Failover and round-robin transport support
- **Custom DSN Support**: Full Symfony DSN configuration for any provider
- **Auto-Configuration**: Automatic transport configuration for standard provider patterns

#### **⚡ Enhanced Queue Integration**
- **Framework Queue System**: Integration with Glueful's built-in database/Redis queue
- **SendNotification Job**: Leverages existing job system for reliable email delivery
- **Queue Monitoring**: Real-time queue size and worker status monitoring
- **Retry Logic**: Exponential backoff with configurable retry attempts

#### **🛠️ Developer Experience**
- **Extensible Transport Factory**: Support for any Symfony Mailer provider bridge
- **Modern Configuration**: Multi-mailer configuration structure in `services.php`
- **Enhanced Error Handling**: Detailed error messages and debugging information
- **Type Safety**: Full PHP 8+ type declarations and strict mode

### Changed

#### **💥 Breaking Changes**
- **Transport Configuration**: New multi-mailer configuration structure required
- **Queue System**: File-based queue replaced with framework's database/Redis queue
- **Provider Configuration**: Explicit transport specification required (e.g., `brevo+api` vs `brevo+smtp`)
- **No Legacy Support**: PHPMailer and legacy configurations no longer supported

#### **🔄 Migration Path**
```php
// OLD (PHPMailer-based)
'mail' => [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'user@gmail.com',
    'password' => 'password',
]

// NEW (Symfony Mailer-based)
'mail' => [
    'default' => 'smtp',
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST'),
            'port' => env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        ],
        'brevo' => [
            'transport' => env('BREVO_TRANSPORT', 'brevo+api'),
            'key' => env('BREVO_API_KEY'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
        ],
    ],
]
```

### Enhanced

#### **📧 Email Features**
- **Advanced Email Objects**: Full Symfony Email object support
- **Enhanced Attachments**: Improved file attachment handling
- **Email Priority**: Configurable priority levels (highest, high, normal, low, lowest)
- **Custom Headers**: Support for custom email headers
- **Embedded Images**: Image embedding with CID references
- **Return Path**: Configurable return-path for bounce handling

#### **🔒 Security & Reliability**
- **Transport Validation**: Comprehensive configuration validation
- **Credential Protection**: Secure handling of API keys and passwords
- **Provider Bridge Isolation**: Isolated transport creation with proper error handling
- **Connection Pooling**: Efficient connection management for high-volume sending

#### **📊 Monitoring & Debugging**
- **Queue Statistics**: Integration with framework's queue monitoring
- **Transport Health Checks**: Provider availability validation
- **Enhanced Logging**: Structured logging with context information
- **Error Recovery**: Graceful fallback to null transport in development

### Removed

#### **🗑️ Legacy Components**
- **PHPMailer Dependencies**: Complete removal of PHPMailer library
- **Legacy Configuration**: Old configuration structure no longer supported
- **File-Based Queue**: Replaced with framework's database/Redis queue system
- **Backward Compatibility**: Legacy transport aliases removed for clarity

### Fixed

#### **🐛 Issues Resolved**
- **SMTP Username Encoding**: Fixed @ symbol handling in Brevo SMTP transport
- **Configuration Path Issues**: Resolved `config('extensions')` vs `config('services.extensions')` conflicts  
- **Silent Failures**: Enhanced error reporting when transport creation fails
- **Provider Bridge Validation**: Proper credential validation for provider bridges
- **MAMP Pro Compatibility**: Resolved SMTP transport issues in MAMP Pro environment

### Security

#### **🔐 Security Improvements**
- **Modern Dependencies**: Updated to Symfony Mailer's security standards
- **Input Validation**: Enhanced validation for transport configurations
- **Error Information**: Sanitized error messages to prevent credential exposure
- **Provider Isolation**: Isolated transport creation prevents configuration leakage

### Performance

#### **⚡ Performance Optimizations**
- **Native Provider Bridges**: Direct API integrations for better performance
- **Connection Efficiency**: Improved connection handling and pooling
- **Memory Management**: Optimized memory usage with proper object lifecycle
- **Queue Processing**: Efficient background email processing with framework queue

### Developer Experience

#### **👨‍💻 Developer Improvements**
- **Modern PHP**: Full PHP 8+ compatibility with strict typing
- **Clear Error Messages**: Helpful error messages with configuration guidance
- **Extensible Architecture**: Easy addition of new provider bridges
- **Comprehensive Testing**: Enhanced test coverage for transport creation
- **Documentation**: Updated documentation for Symfony Mailer migration

---

## [0.21.0] - 2024-06-21

### Added
- **Enterprise Email Delivery System**
  - Advanced SMTP support with multiple provider configurations
  - Amazon SES, Mailgun, and Office 365 integration
  - Connection pooling for high-volume email sending
  - Automatic failover between SMTP providers
- **Advanced Template Engine**
  - Variable substitution with dot notation support (`{{user.name}}`)
  - Conditional logic blocks (`{{#if condition}}...{{/if}}`)
  - Template partial includes (`{{> header}}`, `{{> footer}}`)
  - Default value support (`{{variable|default}}`)
  - Responsive email template system
- **Performance & Monitoring**
  - Rate limiting with configurable limits (per minute/hour/day)
  - Queue integration for asynchronous email processing
  - Comprehensive metrics collection (success rates, delivery times)
  - Resource monitoring and health checks
  - Email delivery analytics and reporting

---

## Upgrade Guide: PHPMailer → Symfony Mailer

### 🔄 Configuration Migration

#### 1. Update Environment Variables
```env
# Update transport specification
MAIL_MAILER=brevo
BREVO_TRANSPORT=brevo+smtp  # or brevo+api

# Ensure encryption setting uses new format
MAIL_ENCRYPTION=tls  # not MAIL_SECURE=tls
```

#### 2. Update services.php Configuration
Replace old configuration with new multi-mailer structure as shown above.

#### 3. Provider Bridge Setup
Install required Symfony provider bridges:
```bash
composer require symfony/brevo-mailer
composer require symfony/sendgrid-mailer
composer require symfony/mailgun-mailer
```

### 🧪 Testing Your Migration

1. **Verify Configuration**: Check email provider configuration is detected
2. **Test Email Sending**: Send test emails through your preferred transport
3. **Monitor Queue**: Verify queue integration is working properly
4. **Check Logs**: Review logs for any transport creation errors

### 📞 Support

For migration assistance or issues:
- Review the updated documentation in README.md
- Check configuration examples in `config/services.php`
- Enable debug logging to troubleshoot transport issues

---

**Full Changelog**: https://github.com/glueful/extensions/compare/email-notification-v0.21.0...email-notification-v1.0.0