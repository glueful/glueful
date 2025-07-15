# Validation API Reference

This document provides a comprehensive API reference for the Glueful validation system.

## Table of Contents

- [Validator Class](#validator-class)
- [Constraints](#constraints)
- [Sanitization](#sanitization)
- [Performance Classes](#performance-classes)
- [Extension Classes](#extension-classes)
- [Console Commands](#console-commands)

## Validator Class

### `Glueful\Validation\Validator`

The main validation facade providing a clean interface for validation operations.

#### Constructor

```php
public function __construct(
    ValidatorInterface $symfonyValidator,
    SanitizationProcessor $sanitizationProcessor,
    ?LazyValidationProvider $lazyProvider = null
)
```

#### Methods

##### `validate(object $dto, ?array $groups = null): bool`

Validates a DTO object with optional validation groups.

**Parameters:**
- `$dto`: The DTO object to validate
- `$groups`: Optional array of validation groups (default: null)

**Returns:** `bool` - True if validation passes, false otherwise

**Example:**
```php
$isValid = $validator->validate($userDTO, ['create']);
```

##### `getErrors(): array`

Returns all validation errors grouped by field.

**Returns:** `array<string, string[]>` - Array of field names with their error messages

**Example:**
```php
$errors = $validator->getErrors();
// ['username' => ['Username is required'], 'email' => ['Invalid email format']]
```

##### `getFirstError(string $field): ?string`

Returns the first error message for a specific field.

**Parameters:**
- `$field`: The field name

**Returns:** `?string` - First error message or null if no errors

**Example:**
```php
$usernameError = $validator->getFirstError('username');
```

##### `getFlatErrors(): array`

Returns all error messages as a flat array.

**Returns:** `string[]` - Array of all error messages

**Example:**
```php
$allErrors = $validator->getFlatErrors();
// ['Username is required', 'Invalid email format']
```

##### `hasError(string $field): bool`

Checks if a specific field has validation errors.

**Parameters:**
- `$field`: The field name

**Returns:** `bool` - True if field has errors

**Example:**
```php
if ($validator->hasError('email')) {
    // Handle email error
}
```

##### `hasErrors(): bool`

Checks if there are any validation errors.

**Returns:** `bool` - True if there are errors

**Example:**
```php
if ($validator->hasErrors()) {
    // Handle validation errors
}
```

##### `sanitize(object $dto): object`

Sanitizes a DTO object without validation.

**Parameters:**
- `$dto`: The DTO object to sanitize

**Returns:** `object` - The sanitized DTO object

**Example:**
```php
$cleanDTO = $validator->sanitize($userDTO);
```

##### `getPerformanceStatistics(): array`

Returns performance statistics from the validation system.

**Returns:** `array` - Performance metrics

**Example:**
```php
$stats = $validator->getPerformanceStatistics();
// [
//     'lazy_provider_enabled' => true,
//     'lazy_provider_stats' => [...],
//     'cache_memory_usage' => [...]
// ]
```

##### `warmupValidationCache(array $dtoClasses): array`

Preloads validation constraints for specified DTO classes.

**Parameters:**
- `$dtoClasses`: Array of DTO class names

**Returns:** `array` - Warm-up results

**Example:**
```php
$results = $validator->warmupValidationCache([
    UserDTO::class,
    OrderDTO::class
]);
```

##### `clearValidationCache(?string $className = null): bool`

Clears validation cache for all or specific class.

**Parameters:**
- `$className`: Optional class name to clear specific cache

**Returns:** `bool` - True if cache was cleared

**Example:**
```php
$validator->clearValidationCache(); // Clear all
$validator->clearValidationCache(UserDTO::class); // Clear specific
```

## Constraints

### Abstract Base Class

#### `Glueful\Validation\Constraints\AbstractConstraint`

Base class for all Glueful constraints.

```php
abstract class AbstractConstraint extends Constraint
{
    public function __construct(?array $groups = null);
    abstract public function getDefaultMessage(): string;
    abstract public function getType(): string;
    public function getMetadata(): array;
    public function validateConfiguration(): void;
}
```

### Built-in Constraints

#### `Required`

Ensures a value is not null or empty.

```php
#[Required(
    message: 'This field is required',
    groups: ['create', 'update']
)]
public string $field;
```

**Properties:**
- `message`: Error message (default: "This field is required")
- `groups`: Validation groups

#### `StringLength`

Validates string length within specified bounds.

```php
#[StringLength(
    min: 3,
    max: 50,
    minMessage: 'Must be at least {{ limit }} characters',
    maxMessage: 'Cannot exceed {{ limit }} characters',
    groups: ['create']
)]
public string $field;
```

**Properties:**
- `min`: Minimum length (default: 0)
- `max`: Maximum length (default: null)
- `minMessage`: Message for minimum length violation
- `maxMessage`: Message for maximum length violation
- `groups`: Validation groups

#### `Email`

Validates email format.

```php
#[Email(
    message: 'Please enter a valid email address',
    mode: 'strict',
    groups: ['create', 'update']
)]
public string $email;
```

**Properties:**
- `message`: Error message
- `mode`: Validation mode ('loose' or 'strict')
- `groups`: Validation groups

#### `Choice`

Validates value is in allowed choices.

```php
#[Choice(
    choices: ['admin', 'user', 'guest'],
    message: 'Invalid choice',
    multiple: false,
    min: 1,
    max: 3,
    groups: ['create']
)]
public string $field;
```

**Properties:**
- `choices`: Array of allowed values
- `message`: Error message
- `multiple`: Allow multiple selections (default: false)
- `min`: Minimum number of selections (for multiple)
- `max`: Maximum number of selections (for multiple)
- `groups`: Validation groups

#### `Range`

Validates numeric or date ranges.

```php
#[Range(
    min: 18,
    max: 99,
    notInRangeMessage: 'Must be between {{ min }} and {{ max }}',
    groups: ['create']
)]
public int $age;
```

**Properties:**
- `min`: Minimum value
- `max`: Maximum value
- `notInRangeMessage`: Message for out-of-range values
- `groups`: Validation groups

#### `Unique`

Validates uniqueness in database.

```php
#[Unique(
    table: 'users',
    column: 'email',
    message: 'This email is already taken',
    conditions: ['status' => 'active'],
    excludeColumn: 'id',
    excludeValue: null,
    groups: ['create']
)]
public string $email;
```

**Properties:**
- `table`: Database table name
- `column`: Column to check
- `message`: Error message
- `conditions`: Additional WHERE conditions
- `excludeColumn`: Column to exclude from check
- `excludeValue`: Value to exclude from check
- `groups`: Validation groups

#### `Exists`

Validates existence in database.

```php
#[Exists(
    table: 'categories',
    column: 'id',
    message: 'Category not found',
    conditions: ['status' => 'active'],
    groups: ['create']
)]
public int $categoryId;
```

**Properties:**
- `table`: Database table name
- `column`: Column to check
- `message`: Error message
- `conditions`: Additional WHERE conditions
- `groups`: Validation groups

#### `ConditionalRequired`

Makes field required based on conditions.

```php
#[ConditionalRequired(
    field: 'orderType',
    value: 'delivery',
    message: 'Required for delivery orders',
    groups: ['create']
)]
public ?string $deliveryAddress;
```

**Properties:**
- `field`: Field name to check
- `value`: Value that triggers requirement
- `message`: Error message
- `groups`: Validation groups

#### `FieldsMatch`

Ensures two fields have the same value.

```php
#[FieldsMatch(
    field1: 'password',
    field2: 'passwordConfirmation',
    message: 'Passwords do not match',
    groups: ['create']
)]
public string $passwordConfirmation;
```

**Properties:**
- `field1`: First field name
- `field2`: Second field name
- `message`: Error message
- `groups`: Validation groups

## Sanitization

### `Glueful\Validation\Attributes\Sanitize`

Attribute for automatic data sanitization.

```php
#[Sanitize(
    filters: ['trim', 'strip_tags'],
    options: []
)]
public string $field;
```

**Properties:**
- `filters`: Array of filter names or single filter string
- `options`: Optional filter options

### `Glueful\Validation\SanitizationProcessor`

Processes sanitization attributes.

#### Methods

##### `sanitize(object $dto): object`

Sanitizes all properties with Sanitize attributes.

**Parameters:**
- `$dto`: The DTO object to sanitize

**Returns:** `object` - The sanitized DTO object

##### `sanitizeProperty(object $dto, string $propertyName): void`

Sanitizes a specific property.

**Parameters:**
- `$dto`: The DTO object
- `propertyName`: Name of the property to sanitize

##### `getAvailableFilters(): array`

Returns array of available sanitization filters.

**Returns:** `string[]` - Array of filter names

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

## Performance Classes

### `Glueful\Validation\ConstraintCompiler`

Compiles and caches validation constraints.

#### Methods

##### `compileConstraints(string $className): array`

Compiles constraints for a class.

**Parameters:**
- `$className`: Class name to compile

**Returns:** `array` - Compiled constraints

##### `precompileConstraints(array $classNames): array`

Precompiles constraints for multiple classes.

**Parameters:**
- `$classNames`: Array of class names

**Returns:** `array` - Compilation results

##### `clearCache(?string $className = null): bool`

Clears compilation cache.

**Parameters:**
- `$className`: Optional class name

**Returns:** `bool` - True if cleared

##### `getStatistics(): array`

Returns compiler statistics.

**Returns:** `array` - Statistics

### `Glueful\Validation\LazyValidationProvider`

Provides lazy loading of validation constraints.

#### Methods

##### `getConstraintsFor(string $className): array`

Gets constraints for a class (lazy loaded).

**Parameters:**
- `$className`: Class name

**Returns:** `array` - Constraints

##### `preloadConstraints(array $classNames): array`

Preloads constraints for multiple classes.

**Parameters:**
- `$classNames`: Array of class names

**Returns:** `array` - Preload results

##### `clearCache(?string $className = null): bool`

Clears constraint cache.

**Parameters:**
- `$className`: Optional class name

**Returns:** `bool` - True if cleared

##### `getStatistics(): array`

Returns provider statistics.

**Returns:** `array` - Statistics

##### `getCacheMemoryUsage(): array`

Returns memory usage information.

**Returns:** `array` - Memory usage data

## Extension Classes

### `Glueful\Validation\ExtensionConstraintRegistry`

Manages extension validation constraints.

#### Methods

##### `registerConstraint(string $constraintClass, string $validatorClass, string $extensionName): void`

Registers a constraint from an extension.

**Parameters:**
- `$constraintClass`: Constraint class name
- `$validatorClass`: Validator class name
- `$extensionName`: Extension name

##### `discoverConstraintsFromExtension(string $extensionPath, string $extensionName): int`

Auto-discovers constraints from extension.

**Parameters:**
- `$extensionPath`: Extension directory path
- `$extensionName`: Extension name

**Returns:** `int` - Number of constraints discovered

##### `getConstraintsByExtension(string $extensionName): array`

Gets constraints for an extension.

**Parameters:**
- `$extensionName`: Extension name

**Returns:** `array` - Extension constraints

##### `getStatistics(): array`

Returns registry statistics.

**Returns:** `array` - Statistics

### `Glueful\Validation\ValidationExtensionLoader`

Loads validation constraints from extensions.

#### Methods

##### `loadExtensionConstraints(string $extensionName, string $extensionPath): array`

Loads constraints from an extension.

**Parameters:**
- `$extensionName`: Extension name
- `$extensionPath`: Extension path

**Returns:** `array` - Load results

##### `loadAllExtensionConstraints(array $extensions): array`

Loads constraints from all extensions.

**Parameters:**
- `$extensions`: Extension name => path mapping

**Returns:** `array` - Load results

##### `getLoadingStatistics(): array`

Returns loading statistics.

**Returns:** `array` - Statistics

## Console Commands

### `validation:cache` Command

Manages validation cache.

#### Usage

```bash
php glueful validation:cache {action} [--classes=] [--all]
```

#### Arguments

- `action`: Action to perform (`warmup`, `clear`, `stats`)

#### Options

- `--classes`: Comma-separated list of classes to warm up
- `--all`: Warm up all discovered DTO classes

#### Examples

```bash
# Warm up cache for all DTOs
php glueful validation:cache warmup --all

# Warm up specific classes
php glueful validation:cache warmup --classes="UserDTO,OrderDTO"

# Clear cache
php glueful validation:cache clear

# Show statistics
php glueful validation:cache stats
```

## Validation Groups

### `Glueful\Validation\Groups`

Predefined validation groups.

#### Constants

- `CREATE` - For creation operations
- `UPDATE` - For update operations
- `DELETE` - For deletion operations

#### Usage

```php
use Glueful\Validation\Groups;

#[Required(groups: [Groups::CREATE])]
public string $field;
```

## Configuration

### Validation Configuration

Configuration file: `config/validation.php`

#### Key Settings

```php
return [
    // Cache settings
    'cache_dir' => '/path/to/cache',
    'enable_cache' => true,
    'cache_ttl' => 3600,
    
    // Performance settings
    'enable_compilation' => true,
    'lazy_loading' => true,
    'constraint_caching' => true,
    
    // Extension settings
    'extension_constraints' => [
        'auto_discovery' => true,
        'cache_extension_constraints' => true,
    ],
    
    // Database validation
    'database_validation' => [
        'connection' => 'default',
        'cache_queries' => true,
        'query_timeout' => 5,
    ],
];
```

## Error Handling

### Exception Classes

- `Glueful\Validation\ValidationException` - General validation errors
- `Glueful\Validation\ConstraintException` - Constraint-specific errors
- `Glueful\Validation\ExtensionConstraintException` - Extension constraint errors

### Error Response Format

```php
[
    'field_name' => [
        'Error message 1',
        'Error message 2'
    ],
    'another_field' => [
        'Error message'
    ]
]
```

## Type Definitions

### ValidationResult

```php
interface ValidationResult
{
    public function isValid(): bool;
    public function getErrors(): array;
    public function getFirstError(string $field): ?string;
    public function hasError(string $field): bool;
}
```

### ConstraintMetadata

```php
interface ConstraintMetadata
{
    public function getConstraintClass(): string;
    public function getValidatorClass(): string;
    public function getTarget(): string;
    public function getGroups(): array;
    public function getOptions(): array;
}
```

### PerformanceMetrics

```php
interface PerformanceMetrics
{
    public function getLoadTime(): float;
    public function getCacheHitRate(): float;
    public function getMemoryUsage(): int;
    public function getConstraintCount(): int;
}
```

## Best Practices

1. **Use type hints**: Always use proper type hints for better IDE support
2. **Group validation**: Use validation groups for different contexts
3. **Performance**: Enable caching in production environments
4. **Error handling**: Provide meaningful error messages
5. **Extension constraints**: Follow naming conventions for auto-discovery
6. **Database validation**: Use appropriate indexes for validated columns

## Examples

### Complete DTO Example

```php
use Glueful\Validation\Constraints\*;
use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Groups;

class UserRegistrationDTO
{
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
    #[StringLength(min: 8)]
    public string $password;
    
    #[Required(groups: [Groups::CREATE])]
    #[FieldsMatch(field1: 'password', field2: 'passwordConfirmation')]
    public string $passwordConfirmation;
    
    #[Range(min: 13, max: 120)]
    public int $age;
    
    #[Choice(['user', 'admin'])]
    public string $role = 'user';
}
```

### Validation with Error Handling

```php
$validator = container()->get(Validator::class);
$userDTO = new UserRegistrationDTO();

// Populate DTO from request
$userDTO->username = $request->get('username');
$userDTO->email = $request->get('email');
$userDTO->password = $request->get('password');
$userDTO->passwordConfirmation = $request->get('password_confirmation');

// Validate
if (!$validator->validate($userDTO, [Groups::CREATE])) {
    $errors = $validator->getErrors();
    
    return response()->json([
        'success' => false,
        'message' => 'Validation failed',
        'errors' => $errors
    ], 422);
}

// Proceed with registration
$user = $userService->createUser($userDTO);
```

This API reference provides comprehensive documentation for all validation system components, methods, and usage patterns.