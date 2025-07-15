# Validation System

Glueful provides a powerful validation system built on Symfony Validator with a clean, framework-specific interface. This guide covers everything you need to know about validating data in Glueful.

## Table of Contents

- [Overview](#overview)
- [Basic Usage](#basic-usage)
- [Built-in Constraints](#built-in-constraints)
- [Validation Groups](#validation-groups)
- [Sanitization](#sanitization)
- [Advanced Features](#advanced-features)
- [Extension Constraints](#extension-constraints)
- [Performance Optimization](#performance-optimization)
- [API Reference](#api-reference)

## Overview

The Glueful validation system provides:

- **Modern PHP 8+ Attributes**: Clean, declarative validation rules
- **Comprehensive Constraints**: Built-in and custom validation rules
- **Data Sanitization**: Automatic data cleaning before validation
- **Validation Groups**: Context-aware validation scenarios
- **Extension Support**: Extensions can define custom constraints
- **Performance Optimized**: Lazy loading and caching for production

## Basic Usage

### Simple Validation

Define validation rules using PHP attributes on your DTOs:

```php
use Glueful\Validation\Constraints\{Required, Email, StringLength};

class UserDTO {
    #[Required]
    #[StringLength(min: 3, max: 50)]
    public string $username;
    
    #[Required]
    #[Email]
    public string $email;
}
```

Validate the DTO:

```php
use Glueful\Validation\Validator;

$validator = container()->get(Validator::class);
$userDTO = new UserDTO();
$userDTO->username = 'jo';  // Too short
$userDTO->email = 'invalid-email';

if (!$validator->validate($userDTO)) {
    $errors = $validator->getErrors();
    // ['username' => ['String length must be between 3 and 50 characters']]
    // ['email' => ['This value is not a valid email address']]
}
```

### Getting Validation Errors

```php
// Get all errors as array
$errors = $validator->getErrors();

// Get first error for a field
$usernameError = $validator->getFirstError('username');

// Get all errors as flat array
$flatErrors = $validator->getFlatErrors();

// Check if specific field has errors
if ($validator->hasError('email')) {
    // Handle email error
}
```

## Built-in Constraints

### Required

Ensures a value is not null or empty:

```php
#[Required(message: 'This field is required')]
public string $name;
```

### StringLength

Validates string length:

```php
#[StringLength(
    min: 3,
    max: 50,
    minMessage: 'Username must be at least {{ limit }} characters',
    maxMessage: 'Username cannot exceed {{ limit }} characters'
)]
public string $username;
```

### Email

Validates email addresses:

```php
#[Email(
    message: 'Please provide a valid email address',
    mode: 'strict'  // 'loose' or 'strict' validation
)]
public string $email;
```

### Choice

Validates value is one of allowed choices:

```php
#[Choice(
    choices: ['admin', 'user', 'guest'],
    message: 'Invalid role selected'
)]
public string $role;

// Multiple choices allowed
#[Choice(
    choices: ['read', 'write', 'delete'],
    multiple: true,
    min: 1,
    max: 3
)]
public array $permissions;
```

### Range

Validates numeric ranges:

```php
#[Range(
    min: 18,
    max: 99,
    notInRangeMessage: 'Age must be between {{ min }} and {{ max }}'
)]
public int $age;

// Date ranges
#[Range(
    min: 'today',
    max: '+1 year',
    minMessage: 'Date cannot be in the past'
)]
public \DateTime $eventDate;
```

### Unique (Database Validation)

Ensures value is unique in database:

```php
#[Unique(
    table: 'users',
    column: 'email',
    message: 'This email is already registered'
)]
public string $email;

// With additional conditions
#[Unique(
    table: 'users',
    column: 'username',
    conditions: ['status' => 'active'],
    excludeColumn: 'id',
    excludeValue: $userId  // Exclude current user when updating
)]
public string $username;
```

### Exists (Database Validation)

Ensures value exists in database:

```php
#[Exists(
    table: 'categories',
    column: 'id',
    message: 'Invalid category selected'
)]
public int $categoryId;

// With conditions
#[Exists(
    table: 'users',
    column: 'id',
    conditions: ['status' => 'active', 'role' => 'manager']
)]
public int $managerId;
```

### ConditionalRequired

Makes field required based on conditions:

```php
class OrderDTO {
    #[Choice(['pickup', 'delivery'])]
    public string $orderType;
    
    #[ConditionalRequired(
        field: 'orderType',
        value: 'delivery',
        message: 'Delivery address is required for delivery orders'
    )]
    public ?string $deliveryAddress;
}
```

### FieldsMatch

Ensures two fields have the same value:

```php
class PasswordResetDTO {
    #[Required]
    #[StringLength(min: 8)]
    public string $password;
    
    #[Required]
    #[FieldsMatch(
        field1: 'password',
        field2: 'passwordConfirmation',
        message: 'Passwords do not match'
    )]
    public string $passwordConfirmation;
}
```

## Validation Groups

Use validation groups for context-aware validation:

```php
use Glueful\Validation\Groups;

class UserDTO {
    #[Required(groups: [Groups::CREATE])]
    #[StringLength(min: 8, groups: [Groups::CREATE])]
    public ?string $password;
    
    #[Required(groups: [Groups::CREATE, Groups::UPDATE])]
    #[Email]
    public string $email;
    
    #[Required(groups: [Groups::UPDATE])]
    public ?int $id;
}

// Validate for creation
$validator->validate($userDTO, [Groups::CREATE]);

// Validate for update
$validator->validate($userDTO, [Groups::UPDATE]);

// Custom groups
$validator->validate($userDTO, ['registration', 'profile_update']);
```

## Sanitization

Automatically clean data before validation using the `#[Sanitize]` attribute:

```php
use Glueful\Validation\Attributes\Sanitize;

class ArticleDTO {
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    public string $title;
    
    #[Sanitize('email')]
    #[Email]
    public string $email;
    
    #[Sanitize(['trim', 'uppercase'])]
    public string $code;
    
    #[Sanitize('json_encode')]
    public array $metadata;
}
```

### Available Sanitization Filters

- `trim` - Remove whitespace from beginning and end
- `strip_tags` - Remove HTML/PHP tags
- `email` - Sanitize email address
- `url` - Sanitize URL
- `integer` - Convert to integer
- `float` - Convert to float
- `boolean` - Convert to boolean
- `uppercase` - Convert to uppercase
- `lowercase` - Convert to lowercase
- `ucfirst` - Uppercase first character
- `ucwords` - Uppercase first character of each word
- `escape_html` - Escape HTML entities
- `remove_whitespace` - Remove all whitespace
- `normalize_whitespace` - Replace multiple spaces with single space
- `json_encode` - Encode as JSON
- `json_decode` - Decode from JSON

### Manual Sanitization

```php
$sanitizedDTO = $validator->sanitize($dto);
```

## Advanced Features

### Custom Validation Messages

```php
#[Required(message: 'Please enter your {{ field }} name')]
#[StringLength(
    min: 3,
    max: 50,
    minMessage: '{{ field }} must be at least {{ limit }} characters',
    maxMessage: '{{ field }} cannot exceed {{ limit }} characters'
)]
public string $username;
```

### Constraint Builder

Build constraints programmatically:

```php
use Glueful\Validation\ConstraintBuilder;

$builder = new ConstraintBuilder();

// Fluent interface
$builder->required()
    ->stringLength(min: 3, max: 50)
    ->pattern('/^[a-zA-Z0-9_]+$/', 'Only alphanumeric and underscore allowed');

// Apply to value
$violations = $builder->validate($value);

// For collections
$collectionBuilder = new CollectionBuilder();
$collectionBuilder->fields([
    'username' => $builder->required()->stringLength(min: 3),
    'email' => $builder->required()->email(),
    'age' => $builder->range(min: 18, max: 99)
]);
```

### Conditional Validation

```php
use Glueful\Validation\ConditionalBuilder;

$conditional = new ConditionalBuilder();

// When field equals value
$conditional->when('userType', 'business')
    ->require('companyName')
    ->require('taxId');

// When field is in array
$conditional->whenIn('plan', ['premium', 'enterprise'])
    ->require('billingAddress')
    ->require('paymentMethod');

// Custom condition
$conditional->whenCallback(
    function($dto) { return $dto->age >= 18; },
    function($builder) {
        $builder->require('driverLicense');
    }
);
```

### Creating Custom Constraints

Create your own validation constraints:

```php
namespace App\Validation\Constraints;

use Glueful\Validation\Constraints\AbstractConstraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class PhoneNumber extends AbstractConstraint
{
    public function __construct(
        public string $message = 'Invalid phone number format',
        public ?string $countryCode = null,
        ?array $groups = null
    ) {
        parent::__construct($groups);
    }
    
    public function getDefaultMessage(): string
    {
        return $this->message;
    }
    
    public function getType(): string
    {
        return 'property';
    }
}
```

Create the validator:

```php
namespace App\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PhoneNumberValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (null === $value || '' === $value) {
            return;
        }
        
        // Validation logic
        if (!$this->isValidPhoneNumber($value, $constraint->countryCode)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
    
    private function isValidPhoneNumber($value, $countryCode): bool
    {
        // Implementation
    }
}
```

## Extension Constraints

Extensions can define custom validation constraints that are automatically discovered:

### Extension Structure

```
extensions/MyExtension/
├── src/
│   └── Validation/
│       ├── Constraints/
│       │   └── MyConstraint.php
│       └── ConstraintValidators/
│           └── MyConstraintValidator.php
└── manifest.json
```

### Example: RBAC Extension Constraints

```php
namespace Glueful\Extensions\RBAC\Validation\Constraints;

use Glueful\Validation\Constraints\AbstractConstraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class HasPermission extends AbstractConstraint
{
    public function __construct(
        public string $permission,
        public string $message = 'User does not have required permission: {{ permission }}',
        ?array $groups = null
    ) {
        parent::__construct($groups);
    }
}
```

Usage in DTOs:

```php
use Glueful\Extensions\RBAC\Validation\Constraints\HasPermission;
use Glueful\Extensions\RBAC\Validation\Constraints\ValidRole;

class AdminActionDTO {
    #[HasPermission('admin.users.delete')]
    public int $userId;
    
    #[ValidRole]
    public string $roleToAssign;
}
```

## Performance Optimization

### Configuration

Configure performance settings in `config/validation.php`:

```php
return [
    // Enable constraint compilation cache
    'enable_cache' => env('VALIDATION_CACHE_ENABLED', true),
    'cache_ttl' => 3600,
    
    // Enable lazy loading
    'lazy_loading' => true,
    
    // Enable constraint compilation
    'enable_compilation' => env('APP_ENV') === 'production',
];
```

### Cache Warmup

Preload frequently used DTOs for better performance:

```php
// Via code
$validator->warmupValidationCache([
    UserDTO::class,
    OrderDTO::class,
    ProductDTO::class,
]);

// Via CLI
php glueful validation:cache warmup --all
```

### Performance Monitoring

```php
$stats = $validator->getPerformanceStatistics();
// [
//     'lazy_provider_enabled' => true,
//     'loaded_classes' => 5,
//     'cached_constraints' => 5,
//     'cache_memory_usage' => ['total_memory' => 2048, ...]
// ]
```

## API Reference

### Validator Class

```php
namespace Glueful\Validation;

class Validator {
    // Validate object
    public function validate(object $dto, ?array $groups = null): bool;
    
    // Get validation errors
    public function getErrors(): array;
    public function getFirstError(string $field): ?string;
    public function getFlatErrors(): array;
    public function hasError(string $field): bool;
    public function hasErrors(): bool;
    
    // Sanitization
    public function sanitize(object $dto): object;
    
    // Performance
    public function getPerformanceStatistics(): array;
    public function warmupValidationCache(array $dtoClasses): array;
    public function clearValidationCache(?string $className = null): bool;
}
```

### Built-in Constraint Reference

| Constraint | Description | Key Parameters |
|------------|-------------|----------------|
| `Required` | Value cannot be null or empty | `message` |
| `StringLength` | String length validation | `min`, `max`, `minMessage`, `maxMessage` |
| `Email` | Email format validation | `message`, `mode` ('loose'/'strict') |
| `Choice` | Value must be in allowed list | `choices`, `multiple`, `min`, `max` |
| `Range` | Numeric/date range validation | `min`, `max`, `notInRangeMessage` |
| `Unique` | Database uniqueness check | `table`, `column`, `conditions`, `excludeColumn` |
| `Exists` | Database existence check | `table`, `column`, `conditions` |
| `ConditionalRequired` | Conditional requirement | `field`, `value`, `message` |
| `FieldsMatch` | Two fields must match | `field1`, `field2`, `message` |

### Validation Groups

```php
namespace Glueful\Validation;

class Groups {
    const CREATE = 'create';
    const UPDATE = 'update';
    const DELETE = 'delete';
}
```

### Console Commands

```bash
# Warm up validation cache
php glueful validation:cache warmup

# Clear validation cache  
php glueful validation:cache clear

# Show validation statistics
php glueful validation:cache stats
```

## Best Practices

1. **Use DTOs**: Always validate DTOs, not raw request data
2. **Group Validation**: Use groups for different contexts (create/update)
3. **Sanitize First**: Apply sanitization before validation
4. **Cache in Production**: Enable compilation and caching for performance
5. **Custom Messages**: Provide user-friendly error messages
6. **Database Validation**: Use Unique/Exists for database checks
7. **Extension Constraints**: Follow naming conventions for auto-discovery

## Examples

### User Registration DTO

```php
use Glueful\Validation\Constraints\*;
use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Groups;

class UserRegistrationDTO {
    #[Sanitize(['trim', 'lowercase'])]
    #[Required(groups: [Groups::CREATE])]
    #[StringLength(min: 3, max: 30)]
    #[Unique(table: 'users', column: 'username')]
    public string $username;
    
    #[Sanitize('email')]
    #[Required]
    #[Email(mode: 'strict')]
    #[Unique(table: 'users', column: 'email')]
    public string $email;
    
    #[Required(groups: [Groups::CREATE])]
    #[StringLength(min: 8, max: 128)]
    public string $password;
    
    #[Required(groups: [Groups::CREATE])]
    #[FieldsMatch(field1: 'password', field2: 'passwordConfirmation')]
    public string $passwordConfirmation;
    
    #[Required]
    #[Range(min: 13, max: 120)]
    public int $age;
    
    #[Required]
    #[Choice(['user', 'moderator'])]
    public string $role = 'user';
    
    #[ConditionalRequired(field: 'age', value: 17, operator: '<=')]
    public ?string $parentalConsent;
}
```

### E-commerce Order DTO

```php
class OrderDTO {
    #[Required]
    #[Exists(table: 'users', column: 'id')]
    public int $userId;
    
    #[Required]
    #[Choice(['pending', 'processing', 'shipped', 'delivered'])]
    public string $status = 'pending';
    
    #[Required]
    #[Range(min: 0.01, max: 999999.99)]
    public float $totalAmount;
    
    #[Required]
    public array $items;
    
    #[ConditionalRequired(field: 'status', value: 'shipped')]
    public ?string $trackingNumber;
    
    #[Sanitize('trim')]
    public ?string $notes;
}
```

## Troubleshooting

### Common Issues

1. **Constraint not found**: Ensure proper namespace imports
2. **Extension constraints not working**: Check extension is enabled and path structure is correct
3. **Performance issues**: Enable caching and warm up frequently used DTOs
4. **Database validation slow**: Add indexes to validated columns

### Debug Mode

Enable debug mode for detailed validation information:

```php
// In development
$validator = container()->get(Validator::class);
$stats = $validator->getPerformanceStatistics();
```

## Summary

The Glueful validation system provides a powerful, extensible, and performant way to validate data in your applications. With built-in constraints, extension support, and performance optimizations, it handles everything from simple field validation to complex business rules.