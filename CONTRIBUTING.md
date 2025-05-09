# Contributing to Glueful

Thank you for considering contributing to Glueful! This document outlines the process for contributing to the Glueful ecosystem, which consists of multiple repositories:

- [glueful/glueful](https://github.com/glueful/glueful.git) - Main PHP framework
- [glueful/admin](https://github.com/glueful/admin.git) - Admin UI (Vue 3)
- [glueful/docs](https://github.com/glueful/docs.git) - Documentation

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Project Architecture Overview](#project-architecture-overview)
3. [Development Environment Setup](#development-environment-setup)
4. [Contribution Workflow](#contribution-workflow)
5. [Coding Standards](#coding-standards)
6. [Testing Requirements](#testing-requirements)
7. [Documentation Guidelines](#documentation-guidelines)
8. [Extension Development](#extension-development)
9. [Cross-Repository Changes](#cross-repository-changes)
10. [Release Process](#release-process)

## Code of Conduct

The Glueful project is committed to fostering an open and welcoming environment. By participating in this project, you agree to abide by our Code of Conduct (see CODE_OF_CONDUCT.md).

## Project Architecture Overview

Glueful is a modern PHP API framework with a modular architecture:

- **Main Framework (glueful/glueful)**: Core PHP 8.2+ framework with RESTful API capabilities, RBAC, authentication, and more.
- **Admin UI (glueful/admin)**: Vue 3 admin interface that provides a UI for managing the framework and its extensions.
- **Documentation (glueful/docs)**: Project documentation website.
- **Extensions (glueful/extensions)**: Collection of official extensions developed by the Glueful team.

## Development Environment Setup

### Prerequisites

- PHP 8.2 or higher
- MySQL 5.7+ or PostgreSQL 12+
- Node.js 16+ and pnpm (for Admin UI and Docs)
- Git

### Main Framework Setup

```bash
# Clone the repository
git clone https://github.com/glueful/glueful.git
cd glueful

# Install dependencies
composer install

# Set up environment
cp .env.example .env
# Edit .env with your configuration

# Set up database
php glueful db:migrate
```

### Admin UI Setup

```bash
# Clone the repository
git clone https://github.com/glueful/admin.git
cd admin

# Install dependencies
pnpm install

# Set up environment
cp public/env.json.example public/env.json
# Edit env.json with your configuration

# Start development server
pnpm dev
```

### Documentation Setup

```bash
# Clone the repository
git clone https://github.com/glueful/docs.git
cd docs

# Install dependencies
pnpm install

# Start development server
pnpm dev
```

## Contribution Workflow

We follow a standard fork and pull request workflow:

### 1. Fork and Clone

1. Fork the appropriate repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/REPOSITORY_NAME.git
   ```
3. Add the original repository as an upstream remote:
   ```bash
   git remote add upstream https://github.com/glueful/REPOSITORY_NAME.git
   ```

### 2. Branch

Create a new branch for your work:

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/issue-description
```

Branch naming conventions:
- `feature/` - New features or enhancements
- `fix/` - Bug fixes
- `docs/` - Documentation changes
- `refactor/` - Code refactoring with no functionality changes
- `test/` - Adding or updating tests

### 3. Develop

Make your changes following our [coding standards](#coding-standards).

### 4. Test

Ensure your changes meet our [testing requirements](#testing-requirements):

- For PHP code: `composer test`
- For Vue code: `pnpm test`

### 5. Commit

Follow these commit message guidelines:

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters
- Reference issues and pull requests in the description

Example:
```
feat: add user role selection in admin panel

- Adds dropdown component for role selection
- Implements role filtering capabilities
- Updates user creation workflow

Fixes #123
```

We follow conventional commit format:
- `feat:` - A new feature
- `fix:` - A bug fix
- `docs:` - Documentation changes
- `style:` - Changes that don't affect code functionality (formatting, etc.)
- `refactor:` - Code changes that neither fix a bug nor add a feature
- `test:` - Adding or updating tests
- `chore:` - Changes to build process, etc.

### 6. Push and Pull Request

1. Push your branch to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

2. Open a pull request from your fork to the original repository
3. Fill out the PR template with all required information

## Coding Standards

### PHP Code (Main Framework)

- Follow PSR-12 coding standards
- Use type declarations for parameters and return types
- Document all public methods with PHPDoc comments
- Use dependency injection where appropriate
- Avoid global state and static methods

### Vue/TypeScript Code (Admin UI)

- Follow the Vue Style Guide (Priority A and B rules)
- Use TypeScript with strict type checking
- Prefer Composition API over Options API
- Use PascalCase for component names
- Use kebab-case for custom element names

### General Guidelines

- Keep functions/methods small and focused
- Write self-documenting code with clear variable/function names
- Add comments for complex logic
- Follow the DRY (Don't Repeat Yourself) principle
- Follow SOLID principles for OOP code

## Testing Requirements

### Main Framework

- All new features must include unit tests
- Use PHPUnit for testing
- Maintain at least 70% code coverage
- Include integration tests for API endpoints

Run tests:
```bash
composer test
```

### Admin UI

- All components should have unit tests
- Use Vitest for unit testing
- Include E2E tests for critical user journeys

Run tests:
```bash
pnpm test:unit
pnpm test:e2e
```

### Documentation

- Verify all code examples work as described
- Check links for broken references

## Documentation Guidelines

- Use clear, concise language
- Include code examples for all features
- Follow Markdown best practices
- Add diagrams for complex concepts
- Document API endpoints with OpenAPI/Swagger
- Update CHANGELOG.md for all significant changes

## Extension Development

Extensions are a key part of the Glueful ecosystem. Follow these guidelines when developing extensions:

### Extension Structure

Extensions follow a standardized directory structure within the Glueful application:

```
glueful/
├── extensions/                 # Main extensions directory
│   ├── ExtensionName/          # Individual extension directory (PascalCase)
│   │   ├── ExtensionName.php   # Main extension class (same name as folder)
│   │   ├── config.php          # Extension configuration
│   │   ├── routes.php          # Extension routes
│   │   ├── README.md           # Documentation
│   │   ├── migrations/         # Database migrations (if needed)
│   │   ├── Providers/          # Service providers
│   │   └── ...                 # Other extension files
│   └── ...
└── config/
    └── extensions.php          # Extension configuration
```

All extensions are stored in the `/extensions` directory, with each extension having its own subdirectory. The main extension class must have the same name as its directory and be located at the root of that directory.

### Extension Manifest

Every extension must implement a `getMetadata()` method that returns:

```php
public static function getMetadata(): array
{
    return [
        'name' => 'ExtensionName',
        'description' => 'Description of what the extension does',
        'version' => '1.0.0',
        'author' => 'Your Name <your.email@example.com>',
        'requires' => [
            'glueful' => '>=1.0.0',
            'php' => '>=8.1.0',
            'extensions' => [] // List of required extensions
        ]
    ];
}
```

### Extension Development Workflow

1. Create a new extension:
   ```bash
   php glueful extensions create MyExtension
   ```

2. Develop your extension following our coding standards

3. Test your extension locally:
   ```bash
   php glueful extensions test MyExtension
   ```

4. Submit your extension to the extensions repository via pull request

## Cross-Repository Changes

Some features may require changes across multiple repositories. In these cases:

1. Create a tracking issue in the main repository describing all required changes
2. Create separate PRs in each affected repository
3. Reference the tracking issue in each PR
4. Note dependencies between PRs in the description

Example workflow for a feature requiring changes to both main framework and admin UI:
1. Open tracking issue in glueful/glueful
2. Create backend implementation and submit PR to glueful/glueful
3. Create frontend implementation and submit PR to glueful/admin
4. Reference tracking issue in both PRs
5. After review, changes will be coordinated for simultaneous merge

## Release Process

Glueful follows semantic versioning (MAJOR.MINOR.PATCH):

- MAJOR: Incompatible API changes
- MINOR: Backward-compatible new features
- PATCH: Backward-compatible bug fixes

### Release Workflow

1. Version bumps are proposed via PR
2. Changelog is updated with all significant changes
3. After approval, a release tag is created
4. CI/CD pipeline builds and publishes releases

### Deprecation Policy

- Features are marked as deprecated at least one minor version before removal
- Deprecated features show deprecation warnings
- Major version changes include a migration guide

## Getting Help

If you need help with contributing:

- Join our [Discord community](https://discord.gg/glueful)
- Post questions with the "contributing" tag on [our forum](https://forum.glueful.com)
- Check existing GitHub issues for similar questions

Thank you for contributing to Glueful!