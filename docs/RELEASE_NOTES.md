# Release Notes

## v0.20.0 (April 26, 2025)

### New Features
- Implemented update method in QueryBuilder for efficient record updates
  - Added support for updating records with complex conditions
  - Optimized query generation for better performance
  - Simplified repository operations with standardized update pattern

### Improvements
- Refactored database operations in repositories to use update method for improved clarity and consistency
- Enhanced admin routes with comprehensive API documentation for:
  - Authentication management
  - Database operations
  - Extensions configuration
  - Migrations handling
  - Jobs monitoring
  - System configurations
  - Permissions management
  - Role assignments

### System Refactoring
- Refactored Notification system to use UUIDs for identification
  - Updated Notification model to allow nullable ID and moved it to the end of constructor parameters
  - Changed NotificationRetryService to find notifications by UUID instead of ID
  - Modified NotificationService to generate and pass UUIDs for notifications and preferences
  - Enhanced TemplateManager to accept UUIDs for templates
  - Adjusted NotificationRepository to handle nullable ID during insert operations
  - Updated API documentation to reflect changes from ID to UUID for notifications

## v0.19.1 (April 25, 2025)

### New Features
- Added comprehensive API documentation for authentication, file management, and notification routes
  - Created auth.json for authentication routes including login, email verification, OTP verification, password reset, token validation, and logout
  - Created files.json for file management routes including file retrieval, upload, and deletion
  - Created notifications.json for notification management routes including listing, retrieving, marking as read/unread, and updating preferences

### Improvements
- Enhanced schema documentation for better API reference
- Improved developer experience with detailed route documentation

## v0.19.0 (April 25, 2025)

### New Features

#### Notification System
- Implemented comprehensive notification service with CRUD operations and routing
- Added support for multiple notification channels
- Created notification events for read, retry, and scheduled notifications
- Integrated notification status tracking (read/unread/failed)
- Added retry mechanism for failed notifications

#### Email Notification Extension
- Added new extension for sending templated email notifications
- Implemented support for multiple email providers (SMTP, Sendgrid, Mailgun)
- Added HTML and plain text email templating system
- Created file-based caching for improved email delivery performance
- Integrated with core notification system

#### Schema Manager Enhancements
- Added support for multi-column indexes
- Improved index naming conventions for better maintainability
- Enhanced schema managers for optimized query performance
- Added automatic index generation for common query patterns

### Improvements
- Refactored email verification notification subject for clarity
- Improved email template layouts for better user experience
- Generated enhanced OpenAPI documentation for extension routes
- Extracted route documentation from doc comments for better API reference
- Implemented FileCacheDriver for file-based caching

### Breaking Changes
- None

### Deprecations
- Removed deprecated API extensions
- Cleaned up configuration paths

## v0.18.0 (Previous Release)

### New Features
- Added support for native token verification in social login providers
- Refactored SocialLogin extension for improved performance
- Improved API documentation generation

### Improvements
- Enhanced authentication features
- Infrastructure improvements

## v0.17.0 (Earlier Release)

### New Features
- Added Apple OAuth authentication provider
- Updated authentication configuration
- Added Social Login extension with Google, Facebook, and GitHub support

### Improvements
- Removed unused imports for cleaner code
- Replaced PermissionRepository with centralized PermissionManager
- Added superuser role bypass for permissions
- Implemented centralized permission management with caching and debug support
- Enhanced authentication documentation
- Grouped file and resource routes with authentication requirement
- Implemented PSR-15 Middleware System with CORS, Logger, Rate Limiter, and Security Headers