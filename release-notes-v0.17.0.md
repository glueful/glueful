# Glueful v0.17.0

## Release Date: April 23, 2025

We're excited to announce the release of Glueful v0.17.0, featuring powerful new authentication options and significant infrastructure improvements.

## 🚀 New Features

### Social Authentication
- Added Apple OAuth authentication provider
- Implemented Social Login extension with support for:
  - Google
  - Facebook
  - GitHub

### Authorization & Access Control
- Implemented centralized permission management system with:
  - Permission caching for improved performance
  - Debug support for easier development
  - Superuser role bypass for administrative tasks

### Infrastructure Upgrades
- Implemented PSR-15 compatible middleware system with:
  - CORS handling
  - Advanced logging
  - Rate limiting
  - Security headers

## 🔧 Improvements & Refactoring
- Grouped file and resource routes with consistent authentication requirements
- Enhanced documentation in README with detailed authentication model
- Removed redundant code and unused imports
- Replaced PermissionRepository with centralized PermissionManager

## 📝 Documentation
- Expanded documentation for authentication configuration options
- Updated API references to include new authentication providers

## 📦 Upgrading from v0.16.x
This release adds new functionality without breaking changes. Upgrade safely by running:

```
composer update glueful/framework
```

## 🙏 Acknowledgements
Thanks to all contributors who made this release possible!