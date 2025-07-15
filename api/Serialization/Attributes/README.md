# Glueful Serialization Attributes

This directory contains Glueful-specific serialization attributes that provide a clean, framework-friendly interface for Symfony Serializer functionality.

## Available Attributes

### Core Attributes

#### `#[Groups]`
Define serialization groups to control which properties are included in different contexts.

```php
use Glueful\Serialization\Attributes\Groups;

class User {
    #[Groups(['public', 'user:read'])]
    public string $name;
    
    #[Groups(['admin:read'])]
    public string $internalNotes;
}
```

#### `#[SerializedName]`
Change the property name in the serialized output.

```php
use Glueful\Serialization\Attributes\SerializedName;

class User {
    #[SerializedName('created_at')]
    public DateTime $createdAt;
}
```

#### `#[Ignore]`
Exclude properties from serialization entirely.

```php
use Glueful\Serialization\Attributes\Ignore;

class User {
    #[Ignore]
    public string $password;
}
```

#### `#[MaxDepth]`
Limit serialization depth for nested objects.

```php
use Glueful\Serialization\Attributes\MaxDepth;

class User {
    #[MaxDepth(2)]
    public ?User $manager;
}
```

#### `#[DateFormat]`
Specify custom date formatting for DateTime properties.

```php
use Glueful\Serialization\Attributes\DateFormat;

class User {
    #[DateFormat('Y-m-d H:i:s')]
    public DateTime $createdAt;
    
    #[DateFormat('c', 'UTC')]
    public DateTime $updatedAt;
}
```

### Advanced Attributes

#### `#[Context]`
Apply context-specific serialization parameters.

```php
use Glueful\Serialization\Attributes\Context;

class User {
    #[Context(['datetime_format' => 'Y-m-d'], ['admin'])]
    public DateTime $lastLogin;
}
```

#### `#[DiscriminatorMap]`
Enable polymorphic serialization for inheritance hierarchies.

```php
use Glueful\Serialization\Attributes\DiscriminatorMap;

#[DiscriminatorMap('type', [
    'user' => RegularUser::class,
    'admin' => AdminUser::class
])]
abstract class User {
    public string $type;
}
```

#### `#[SerializedPath]`
Map nested properties to flat serialized structures.

```php
use Glueful\Serialization\Attributes\SerializedPath;

class User {
    #[SerializedPath('[profile][avatar]')]
    public string $avatarUrl;
}
```

## Usage Examples

### Basic DTO with Groups

```php
use Glueful\Serialization\Attributes\{Groups, SerializedName, Ignore, DateFormat};

class UserDTO {
    #[Groups(['user:read', 'user:write'])]
    public string $name;
    
    #[Groups(['user:read', 'user:write'])]
    public string $email;
    
    #[Groups(['user:read'])]
    #[SerializedName('created_at')]
    #[DateFormat('Y-m-d H:i:s')]
    public DateTime $createdAt;
    
    #[Ignore]
    public string $password;
    
    #[Groups(['admin:read'])]
    public ?string $internalNotes = null;
}
```

### Serialization with Context

```php
use Glueful\Serialization\Context\SerializationContext;

$context = SerializationContext::create()
    ->withGroups(['user:read'])
    ->withDateFormat('Y-m-d');

$json = $serializer->serialize($user, 'json', $context);
```

## Benefits

1. **Clean API**: No need to import Symfony annotations directly
2. **Framework Integration**: Designed to work seamlessly with Glueful's architecture
3. **Type Safety**: Full PHP 8+ attribute support with proper typing
4. **Extensible**: Easy to add custom attributes for specific use cases
5. **Documented**: Well-documented with examples and use cases

## Best Practices

1. **Use Groups Consistently**: Define clear group naming conventions (e.g., `entity:action`)
2. **Secure by Default**: Use `#[Ignore]` for sensitive data like passwords
3. **Date Formatting**: Use consistent date formats across your API
4. **Nested Objects**: Use `#[MaxDepth]` to prevent infinite recursion
5. **API Versioning**: Use groups to support different API versions