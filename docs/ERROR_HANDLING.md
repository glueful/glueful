# Error Handling Guide

This guide provides comprehensive examples and best practices for error handling in Glueful applications, covering both server-side and client-side scenarios.

## Table of Contents

1. [Error Response Format](#error-response-format)
2. [Server-Side Error Handling](#server-side-error-handling)
3. [Client-Side Error Handling](#client-side-error-handling)
4. [Custom Exception Types](#custom-exception-types)
5. [Validation Errors](#validation-errors)
6. [Authentication Errors](#authentication-errors)
7. [Rate Limiting Errors](#rate-limiting-errors)
8. [Database Errors](#database-errors)
9. [File Upload Errors](#file-upload-errors)
10. [Logging and Debugging](#logging-and-debugging)
11. [Error Recovery Strategies](#error-recovery-strategies)
12. [Testing Error Scenarios](#testing-error-scenarios)

## Error Response Format

Glueful uses a standardized error response format across all API endpoints:

### Standard Error Response

```json
{
  "success": false,
  "message": "Human-readable error message",
  "code": 400,
  "error": {
    "type": "VALIDATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123def456",
    "details": {
      "field": "username",
      "constraint": "required"
    }
  },
  "data": []
}
```

### Error Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always `false` for error responses |
| `message` | string | Human-readable error description |
| `code` | integer | HTTP status code |
| `error.type` | string | Machine-readable error type |
| `error.timestamp` | string | ISO 8601 timestamp |
| `error.request_id` | string | Unique request identifier for tracking |
| `error.details` | object | Additional error-specific information |
| `data` | array | Empty array for consistency |

## Server-Side Error Handling

### Custom Exception Handler

```php
<?php
// api/Exceptions/ApiExceptionHandler.php

namespace Glueful\Exceptions;

use Throwable;
use Glueful\Logging\LogManager;

class ApiExceptionHandler
{
    private LogManager $logger;
    
    public function __construct()
    {
        $this->logger = new LogManager('errors');
    }
    
    public function handle(Throwable $exception): array
    {
        $requestId = $this->generateRequestId();
        
        // Log the exception with context
        $this->logException($exception, $requestId);
        
        // Determine response based on exception type
        return match (true) {
            $exception instanceof ValidationException => $this->handleValidationError($exception, $requestId),
            $exception instanceof AuthenticationException => $this->handleAuthError($exception, $requestId),
            $exception instanceof NotFoundException => $this->handleNotFoundError($exception, $requestId),
            $exception instanceof RateLimitExceededException => $this->handleRateLimitError($exception, $requestId),
            $exception instanceof DatabaseException => $this->handleDatabaseError($exception, $requestId),
            default => $this->handleGenericError($exception, $requestId)
        };
    }
    
    private function handleValidationError(ValidationException $e, string $requestId): array
    {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'code' => 400,
            'error' => [
                'type' => 'VALIDATION_ERROR',
                'timestamp' => date('c'),
                'request_id' => $requestId,
                'details' => $e->getValidationErrors()
            ],
            'data' => []
        ];
    }
    
    private function handleAuthError(AuthenticationException $e, string $requestId): array
    {
        return [
            'success' => false,
            'message' => 'Authentication failed',
            'code' => 401,
            'error' => [
                'type' => 'AUTHENTICATION_ERROR',
                'timestamp' => date('c'),
                'request_id' => $requestId,
                'details' => [
                    'reason' => $e->getReason(),
                    'suggested_action' => 'Please login again'
                ]
            ],
            'data' => []
        ];
    }
    
    private function handleNotFoundError(NotFoundException $e, string $requestId): array
    {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'code' => 404,
            'error' => [
                'type' => 'NOT_FOUND_ERROR',
                'timestamp' => date('c'),
                'request_id' => $requestId,
                'details' => [
                    'resource' => $e->getResource(),
                    'identifier' => $e->getIdentifier()
                ]
            ],
            'data' => []
        ];
    }
    
    private function logException(Throwable $exception, string $requestId): void
    {
        $context = [
            'request_id' => $requestId,
            'exception_type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $this->logger->error($exception->getMessage(), $context);
    }
    
    private function generateRequestId(): string
    {
        return 'req_' . uniqid() . bin2hex(random_bytes(4));
    }
}
```

### Controller Error Handling

```php
<?php
// api/Controllers/UserController.php

namespace Glueful\Controllers;

use Glueful\Exceptions\{ValidationException, NotFoundException};
use Glueful\Validation\Validator;

class UserController
{
    public function create(): array
    {
        try {
            // Validate input
            $data = $this->validateCreateUser();
            
            // Create user
            $user = $this->userService->create($data);
            
            return [
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user,
                'code' => 201
            ];
            
        } catch (ValidationException $e) {
            // Validation errors are automatically handled by ExceptionHandler
            throw $e;
            
        } catch (DatabaseException $e) {
            // Log database errors and convert to generic error
            $this->logger->error('Database error during user creation', [
                'error' => $e->getMessage(),
                'user_data' => $data ?? null
            ]);
            
            throw new \Exception('Failed to create user. Please try again.');
            
        } catch (\Throwable $e) {
            // Catch any unexpected errors
            $this->logger->critical('Unexpected error in user creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('An unexpected error occurred');
        }
    }
    
    public function update(string $userId): array
    {
        try {
            // Find user
            $user = $this->userService->findById($userId);
            if (!$user) {
                throw new NotFoundException('User not found', 'user', $userId);
            }
            
            // Validate update data
            $data = $this->validateUpdateUser();
            
            // Update user
            $updatedUser = $this->userService->update($user, $data);
            
            return [
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $updatedUser,
                'code' => 200
            ];
            
        } catch (NotFoundException $e) {
            throw $e; // Re-throw to be handled by exception handler
            
        } catch (ValidationException $e) {
            throw $e;
            
        } catch (\Throwable $e) {
            $this->logger->error('Error updating user', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to update user');
        }
    }
    
    private function validateCreateUser(): array
    {
        $validator = new Validator($_POST);
        
        $rules = [
            'username' => 'required|string|min:3|max:50|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|password_strength',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100'
        ];
        
        if (!$validator->validate($rules)) {
            throw new ValidationException('Validation failed', $validator->getErrors());
        }
        
        return $validator->getData();
    }
}
```

## Client-Side Error Handling

### JavaScript/TypeScript Examples

```typescript
// Frontend error handling utility
class ApiClient {
    private baseUrl: string;
    
    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
    }
    
    async request<T>(endpoint: string, options: RequestOptions = {}): Promise<ApiResponse<T>> {
        try {
            const response = await fetch(`${this.baseUrl}${endpoint}`, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...options.headers
                },
                ...options
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new ApiError(data);
            }
            
            return data;
            
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }
            
            // Network or other errors
            throw new ApiError({
                success: false,
                message: 'Network error occurred',
                code: 0,
                error: {
                    type: 'NETWORK_ERROR',
                    timestamp: new Date().toISOString(),
                    request_id: 'unknown'
                }
            });
        }
    }
    
    // Specific methods with error handling
    async createUser(userData: CreateUserRequest): Promise<User> {
        try {
            const response = await this.request<User>('/users', {
                method: 'POST',
                body: JSON.stringify(userData)
            });
            
            return response.data;
            
        } catch (error) {
            if (error instanceof ApiError) {
                // Handle specific error types
                switch (error.type) {
                    case 'VALIDATION_ERROR':
                        throw new ValidationError(error.message, error.details);
                    case 'AUTHENTICATION_ERROR':
                        // Redirect to login
                        window.location.href = '/login';
                        throw error;
                    default:
                        throw error;
                }
            }
            throw error;
        }
    }
}

// Custom error classes
class ApiError extends Error {
    public readonly type: string;
    public readonly code: number;
    public readonly requestId: string;
    public readonly details: any;
    
    constructor(errorResponse: ApiErrorResponse) {
        super(errorResponse.message);
        this.name = 'ApiError';
        this.type = errorResponse.error.type;
        this.code = errorResponse.code;
        this.requestId = errorResponse.error.request_id;
        this.details = errorResponse.error.details;
    }
}

class ValidationError extends ApiError {
    public readonly fieldErrors: Record<string, string[]>;
    
    constructor(message: string, validationDetails: any) {
        super({
            success: false,
            message,
            code: 400,
            error: {
                type: 'VALIDATION_ERROR',
                timestamp: new Date().toISOString(),
                request_id: 'client',
                details: validationDetails
            }
        });
        
        this.fieldErrors = this.parseFieldErrors(validationDetails);
    }
    
    private parseFieldErrors(details: any): Record<string, string[]> {
        const fieldErrors: Record<string, string[]> = {};
        
        if (details && Array.isArray(details)) {
            details.forEach(error => {
                if (error.field && error.message) {
                    if (!fieldErrors[error.field]) {
                        fieldErrors[error.field] = [];
                    }
                    fieldErrors[error.field].push(error.message);
                }
            });
        }
        
        return fieldErrors;
    }
}

// Usage in React component
const UserRegistration: React.FC = () => {
    const [formData, setFormData] = useState<CreateUserRequest>({});
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [loading, setLoading] = useState(false);
    
    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        
        try {
            const user = await apiClient.createUser(formData);
            // Handle success
            console.log('User created:', user);
            
        } catch (error) {
            if (error instanceof ValidationError) {
                setErrors(error.fieldErrors);
            } else if (error instanceof ApiError) {
                // Handle other API errors
                switch (error.type) {
                    case 'RATE_LIMIT_EXCEEDED':
                        alert('Too many requests. Please try again later.');
                        break;
                    case 'SERVER_ERROR':
                        alert('Server error occurred. Please try again.');
                        break;
                    default:
                        alert(error.message);
                }
            } else {
                alert('An unexpected error occurred');
            }
        } finally {
            setLoading(false);
        }
    };
    
    return (
        <form onSubmit={handleSubmit}>
            <input
                type="text"
                placeholder="Username"
                value={formData.username || ''}
                onChange={(e) => setFormData({...formData, username: e.target.value})}
            />
            {errors.username && (
                <div className="error">
                    {errors.username.map(error => <p key={error}>{error}</p>)}
                </div>
            )}
            
            <button type="submit" disabled={loading}>
                {loading ? 'Creating...' : 'Create User'}
            </button>
        </form>
    );
};
```

### jQuery/Vanilla JavaScript Examples

```javascript
// jQuery AJAX error handling
$.ajaxSetup({
    beforeSend: function(xhr) {
        xhr.setRequestHeader('Accept', 'application/json');
    },
    error: function(xhr, status, error) {
        handleApiError(xhr.responseJSON || {
            success: false,
            message: 'Network error occurred',
            code: xhr.status || 0
        });
    }
});

function handleApiError(errorResponse) {
    const errorType = errorResponse.error?.type;
    
    switch (errorType) {
        case 'VALIDATION_ERROR':
            displayValidationErrors(errorResponse.error.details);
            break;
            
        case 'AUTHENTICATION_ERROR':
            alert('Please login again');
            window.location.href = '/login';
            break;
            
        case 'RATE_LIMIT_EXCEEDED':
            alert('Too many requests. Please slow down.');
            break;
            
        case 'NOT_FOUND_ERROR':
            alert('Resource not found');
            break;
            
        default:
            alert(errorResponse.message || 'An error occurred');
    }
}

function displayValidationErrors(details) {
    // Clear previous errors
    $('.error-message').remove();
    
    if (Array.isArray(details)) {
        details.forEach(error => {
            const field = $(`[name="${error.field}"]`);
            if (field.length) {
                field.after(`<div class="error-message">${error.message}</div>`);
            }
        });
    }
}

// Vanilla JavaScript fetch with error handling
async function createUser(userData) {
    try {
        const response = await fetch('/api/v1/users', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(userData)
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(JSON.stringify(data));
        }
        
        return data.data;
        
    } catch (error) {
        let errorData;
        try {
            errorData = JSON.parse(error.message);
        } catch {
            errorData = {
                success: false,
                message: 'Network error occurred',
                code: 0
            };
        }
        
        handleApiError(errorData);
        throw error;
    }
}
```

## Custom Exception Types

### Creating Custom Exceptions

```php
<?php
// api/Exceptions/ValidationException.php

namespace Glueful\Exceptions;

class ValidationException extends \Exception
{
    private array $validationErrors;
    
    public function __construct(string $message, array $validationErrors = [], int $code = 400)
    {
        parent::__construct($message, $code);
        $this->validationErrors = $validationErrors;
    }
    
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
    
    public function addError(string $field, string $message): void
    {
        if (!isset($this->validationErrors[$field])) {
            $this->validationErrors[$field] = [];
        }
        $this->validationErrors[$field][] = $message;
    }
}

// api/Exceptions/AuthenticationException.php
class AuthenticationException extends \Exception
{
    private string $reason;
    
    public function __construct(string $message, string $reason = 'invalid_credentials', int $code = 401)
    {
        parent::__construct($message, $code);
        $this->reason = $reason;
    }
    
    public function getReason(): string
    {
        return $this->reason;
    }
}

// api/Exceptions/NotFoundException.php
class NotFoundException extends \Exception
{
    private string $resource;
    private string $identifier;
    
    public function __construct(string $message, string $resource = '', string $identifier = '', int $code = 404)
    {
        parent::__construct($message, $code);
        $this->resource = $resource;
        $this->identifier = $identifier;
    }
    
    public function getResource(): string
    {
        return $this->resource;
    }
    
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}

// api/Exceptions/RateLimitExceededException.php
class RateLimitExceededException extends \Exception
{
    private int $retryAfter;
    private string $limitType;
    
    public function __construct(string $message, int $retryAfter = 60, string $limitType = 'general', int $code = 429)
    {
        parent::__construct($message, $code);
        $this->retryAfter = $retryAfter;
        $this->limitType = $limitType;
    }
    
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
    
    public function getLimitType(): string
    {
        return $this->limitType;
    }
}
```

## Validation Errors

### Server-Side Validation

```php
<?php
// api/Validation/UserValidator.php

namespace Glueful\Validation;

use Glueful\Exceptions\ValidationException;

class UserValidator
{
    public function validateRegistration(array $data): array
    {
        $errors = [];
        
        // Username validation
        if (empty($data['username'])) {
            $errors['username'][] = 'Username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'][] = 'Username must be at least 3 characters';
        } elseif (strlen($data['username']) > 50) {
            $errors['username'][] = 'Username cannot exceed 50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $errors['username'][] = 'Username can only contain letters, numbers, and underscores';
        } elseif ($this->usernameExists($data['username'])) {
            $errors['username'][] = 'Username is already taken';
        }
        
        // Email validation
        if (empty($data['email'])) {
            $errors['email'][] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Invalid email format';
        } elseif ($this->emailExists($data['email'])) {
            $errors['email'][] = 'Email is already registered';
        }
        
        // Password validation
        if (empty($data['password'])) {
            $errors['password'][] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'][] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $data['password'])) {
            $errors['password'][] = 'Password must contain uppercase, lowercase, number, and special character';
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $this->formatErrors($errors));
        }
        
        return $data;
    }
    
    private function formatErrors(array $errors): array
    {
        $formatted = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $formatted[] = [
                    'field' => $field,
                    'message' => $message,
                    'code' => 'validation_failed'
                ];
            }
        }
        return $formatted;
    }
}

// Example response for validation errors:
/*
{
  "success": false,
  "message": "Validation failed",
  "code": 400,
  "error": {
    "type": "VALIDATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": [
      {
        "field": "username",
        "message": "Username is already taken",
        "code": "validation_failed"
      },
      {
        "field": "password",
        "message": "Password must contain uppercase, lowercase, number, and special character",
        "code": "validation_failed"
      }
    ]
  },
  "data": []
}
*/
```

### Client-Side Validation Display

```html
<!-- HTML form with error display -->
<form id="registration-form">
    <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
        <div class="error-container" data-field="username"></div>
    </div>
    
    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>
        <div class="error-container" data-field="email"></div>
    </div>
    
    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <div class="error-container" data-field="password"></div>
    </div>
    
    <button type="submit">Register</button>
</form>

<script>
function displayValidationErrors(errors) {
    // Clear previous errors
    document.querySelectorAll('.error-container').forEach(container => {
        container.innerHTML = '';
        container.parentElement.classList.remove('has-error');
    });
    
    // Display new errors
    errors.forEach(error => {
        const container = document.querySelector(`[data-field="${error.field}"]`);
        if (container) {
            const errorElement = document.createElement('div');
            errorElement.className = 'error-message';
            errorElement.textContent = error.message;
            container.appendChild(errorElement);
            container.parentElement.classList.add('has-error');
        }
    });
}

// CSS for error styling
const style = document.createElement('style');
style.textContent = `
    .has-error input {
        border-color: #dc3545;
    }
    
    .error-message {
        color: #dc3545;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }
`;
document.head.appendChild(style);
</script>
```

## Authentication Errors

### JWT Token Errors

```php
<?php
// api/Auth/JwtAuthenticationProvider.php

namespace Glueful\Auth;

use Glueful\Exceptions\AuthenticationException;

class JwtAuthenticationProvider
{
    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, $this->getKey(), ['HS256']);
            return (array) $decoded;
            
        } catch (ExpiredException $e) {
            throw new AuthenticationException(
                'Token has expired',
                'token_expired'
            );
            
        } catch (SignatureInvalidException $e) {
            throw new AuthenticationException(
                'Invalid token signature',
                'invalid_signature'
            );
            
        } catch (BeforeValidException $e) {
            throw new AuthenticationException(
                'Token not yet valid',
                'token_not_yet_valid'
            );
            
        } catch (\Exception $e) {
            throw new AuthenticationException(
                'Invalid token',
                'invalid_token'
            );
        }
    }
}

// Example authentication error responses:
/*
Token expired:
{
  "success": false,
  "message": "Token has expired",
  "code": 401,
  "error": {
    "type": "AUTHENTICATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": {
      "reason": "token_expired",
      "suggested_action": "Please login again"
    }
  }
}

Invalid credentials:
{
  "success": false,
  "message": "Invalid username or password",
  "code": 401,
  "error": {
    "type": "AUTHENTICATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": {
      "reason": "invalid_credentials",
      "suggested_action": "Please check your credentials"
    }
  }
}
*/
```

### Client-Side Token Handling

```javascript
// Token management with automatic refresh
class TokenManager {
    constructor() {
        this.accessToken = localStorage.getItem('access_token');
        this.refreshToken = localStorage.getItem('refresh_token');
    }
    
    async makeAuthenticatedRequest(url, options = {}) {
        try {
            return await this.request(url, options);
            
        } catch (error) {
            if (error.type === 'AUTHENTICATION_ERROR' && error.details.reason === 'token_expired') {
                // Try to refresh token
                try {
                    await this.refreshAccessToken();
                    return await this.request(url, options); // Retry original request
                    
                } catch (refreshError) {
                    // Refresh failed, redirect to login
                    this.logout();
                    window.location.href = '/login';
                    throw refreshError;
                }
            }
            throw error;
        }
    }
    
    async request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'Authorization': `Bearer ${this.accessToken}`,
                'Content-Type': 'application/json',
                ...options.headers
            }
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new ApiError(data);
        }
        
        return data;
    }
    
    async refreshAccessToken() {
        const response = await fetch('/api/v1/auth/refresh-token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                refresh_token: this.refreshToken
            })
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new ApiError(data);
        }
        
        this.accessToken = data.data.tokens.access_token;
        this.refreshToken = data.data.tokens.refresh_token;
        
        localStorage.setItem('access_token', this.accessToken);
        localStorage.setItem('refresh_token', this.refreshToken);
    }
    
    logout() {
        this.accessToken = null;
        this.refreshToken = null;
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
    }
}
```

## Rate Limiting Errors

### Server-Side Rate Limiting

```php
<?php
// Example rate limit error response:
/*
{
  "success": false,
  "message": "Rate limit exceeded",
  "code": 429,
  "error": {
    "type": "RATE_LIMIT_EXCEEDED",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": {
      "limit_type": "per_user",
      "limit": 100,
      "window": "3600",
      "retry_after": 1800,
      "reset_at": "2024-01-15T11:30:00Z"
    }
  }
}
*/
```

### Client-Side Rate Limit Handling

```javascript
// Rate limit aware request handler
class RateLimitAwareClient {
    constructor() {
        this.rateLimitResets = new Map();
    }
    
    async request(url, options = {}) {
        // Check if we're in a rate limit timeout
        const resetTime = this.rateLimitResets.get(url);
        if (resetTime && Date.now() < resetTime) {
            const waitTime = Math.ceil((resetTime - Date.now()) / 1000);
            throw new Error(`Rate limited. Try again in ${waitTime} seconds.`);
        }
        
        try {
            const response = await fetch(url, options);
            const data = await response.json();
            
            if (response.status === 429) {
                // Store rate limit reset time
                const retryAfter = data.error.details.retry_after * 1000;
                this.rateLimitResets.set(url, Date.now() + retryAfter);
                
                throw new RateLimitError(data);
            }
            
            return data;
            
        } catch (error) {
            if (error instanceof RateLimitError) {
                // Show user-friendly message
                this.showRateLimitMessage(error);
            }
            throw error;
        }
    }
    
    showRateLimitMessage(error) {
        const retryAfter = error.details.retry_after;
        const message = `Too many requests. Please wait ${retryAfter} seconds before trying again.`;
        
        // Show toast notification or modal
        this.showNotification(message, 'warning');
    }
}

class RateLimitError extends Error {
    constructor(errorResponse) {
        super(errorResponse.message);
        this.details = errorResponse.error.details;
    }
}
```

## Database Errors

### Database Error Handling

```php
<?php
// api/Database/ErrorHandler.php

namespace Glueful\Database;

use Glueful\Exceptions\DatabaseException;

class DatabaseErrorHandler
{
    public function handlePDOException(\PDOException $e): never
    {
        $errorCode = $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        
        // Log the actual database error for debugging
        error_log("Database Error: " . $e->getMessage());
        
        // Convert to user-friendly messages
        $message = match ($errorCode) {
            '23000' => $this->handleIntegrityConstraintViolation($errorInfo),
            '42S02' => 'Resource not found',
            '42S22' => 'Invalid field specified',
            '08006' => 'Database connection failed',
            default => 'Database operation failed'
        };
        
        throw new DatabaseException($message, (int) $errorCode);
    }
    
    private function handleIntegrityConstraintViolation(array $errorInfo): string
    {
        $errorMessage = $errorInfo[2] ?? '';
        
        if (str_contains($errorMessage, 'Duplicate entry')) {
            if (str_contains($errorMessage, 'users.username')) {
                return 'Username already exists';
            }
            if (str_contains($errorMessage, 'users.email')) {
                return 'Email already registered';
            }
            return 'Duplicate entry detected';
        }
        
        if (str_contains($errorMessage, 'foreign key constraint')) {
            return 'Referenced resource does not exist';
        }
        
        return 'Data integrity violation';
    }
}

// Example database error responses:
/*
Duplicate entry:
{
  "success": false,
  "message": "Username already exists",
  "code": 400,
  "error": {
    "type": "DATABASE_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": {
      "error_code": "23000",
      "constraint": "unique_username"
    }
  }
}

Connection error:
{
  "success": false,
  "message": "Database temporarily unavailable",
  "code": 503,
  "error": {
    "type": "DATABASE_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": {
      "error_code": "08006",
      "suggested_action": "Please try again in a few moments"
    }
  }
}
*/
```

## File Upload Errors

### File Upload Error Handling

```php
<?php
// api/Controllers/FileController.php

namespace Glueful\Controllers;

use Glueful\Exceptions\{ValidationException, FileUploadException};

class FileController
{
    public function upload(): array
    {
        try {
            // Validate file upload
            $this->validateFileUpload();
            
            // Process upload
            $file = $this->processUpload();
            
            return [
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $file,
                'code' => 201
            ];
            
        } catch (FileUploadException $e) {
            throw $e;
            
        } catch (\Exception $e) {
            throw new FileUploadException('File upload failed: ' . $e->getMessage());
        }
    }
    
    private function validateFileUpload(): void
    {
        $errors = [];
        
        // Check if file was uploaded
        if (empty($_FILES['file'])) {
            $errors[] = ['field' => 'file', 'message' => 'No file uploaded'];
        } else {
            $file = $_FILES['file'];
            
            // Check upload errors
            switch ($file['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = ['field' => 'file', 'message' => 'File too large'];
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = ['field' => 'file', 'message' => 'File upload incomplete'];
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = ['field' => 'file', 'message' => 'No file selected'];
                    break;
                default:
                    $errors[] = ['field' => 'file', 'message' => 'File upload failed'];
            }
            
            // Validate file size
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                $errors[] = ['field' => 'file', 'message' => 'File exceeds maximum size of 10MB'];
            }
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            
            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = ['field' => 'file', 'message' => 'Invalid file type. Allowed: JPEG, PNG, GIF, PDF'];
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('File validation failed', $errors);
        }
    }
}

// Custom file upload exception
class FileUploadException extends \Exception
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}

// Example file upload error responses:
/*
File too large:
{
  "success": false,
  "message": "File validation failed",
  "code": 400,
  "error": {
    "type": "VALIDATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": [
      {
        "field": "file",
        "message": "File exceeds maximum size of 10MB"
      }
    ]
  }
}

Invalid file type:
{
  "success": false,
  "message": "File validation failed",
  "code": 400,
  "error": {
    "type": "VALIDATION_ERROR",
    "timestamp": "2024-01-15T10:30:00Z",
    "request_id": "req_abc123",
    "details": [
      {
        "field": "file",
        "message": "Invalid file type. Allowed: JPEG, PNG, GIF, PDF"
      }
    ]
  }
}
*/
```

### Client-Side File Upload Error Handling

```javascript
// File upload with progress and error handling
class FileUploader {
    constructor(options = {}) {
        this.maxSize = options.maxSize || 10 * 1024 * 1024; // 10MB
        this.allowedTypes = options.allowedTypes || ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    }
    
    async uploadFile(file, progressCallback) {
        try {
            // Client-side validation
            this.validateFile(file);
            
            // Create form data
            const formData = new FormData();
            formData.append('file', file);
            
            // Upload with progress tracking
            return await this.performUpload(formData, progressCallback);
            
        } catch (error) {
            throw error;
        }
    }
    
    validateFile(file) {
        const errors = [];
        
        // Check file size
        if (file.size > this.maxSize) {
            errors.push(`File size (${this.formatFileSize(file.size)}) exceeds maximum allowed size (${this.formatFileSize(this.maxSize)})`);
        }
        
        // Check file type
        if (!this.allowedTypes.includes(file.type)) {
            errors.push(`File type "${file.type}" is not allowed`);
        }
        
        if (errors.length > 0) {
            throw new ValidationError('File validation failed', errors.map(message => ({ field: 'file', message })));
        }
    }
    
    async performUpload(formData, progressCallback) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && progressCallback) {
                    const progress = (e.loaded / e.total) * 100;
                    progressCallback(progress);
                }
            });
            
            xhr.addEventListener('load', () => {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(response);
                    } else {
                        reject(new ApiError(response));
                    }
                } catch (error) {
                    reject(new Error('Invalid response format'));
                }
            });
            
            xhr.addEventListener('error', () => {
                reject(new Error('Upload failed due to network error'));
            });
            
            xhr.addEventListener('timeout', () => {
                reject(new Error('Upload timed out'));
            });
            
            xhr.open('POST', '/api/v1/files');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.timeout = 300000; // 5 minutes
            xhr.send(formData);
        });
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}

// Usage example
const uploader = new FileUploader({
    maxSize: 5 * 1024 * 1024, // 5MB
    allowedTypes: ['image/jpeg', 'image/png']
});

document.getElementById('file-input').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    
    const progressBar = document.getElementById('progress-bar');
    const statusMessage = document.getElementById('status-message');
    
    try {
        statusMessage.textContent = 'Uploading...';
        
        const result = await uploader.uploadFile(file, (progress) => {
            progressBar.style.width = progress + '%';
            progressBar.textContent = Math.round(progress) + '%';
        });
        
        statusMessage.textContent = 'Upload successful!';
        console.log('Uploaded file:', result.data);
        
    } catch (error) {
        progressBar.style.width = '0%';
        
        if (error instanceof ValidationError) {
            statusMessage.textContent = error.fieldErrors.file?.join(', ') || 'Validation failed';
        } else if (error instanceof ApiError) {
            statusMessage.textContent = error.message;
        } else {
            statusMessage.textContent = 'Upload failed: ' + error.message;
        }
    }
});
```

## Logging and Debugging

### Structured Error Logging

```php
<?php
// api/Logging/ErrorLogger.php

namespace Glueful\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

class ErrorLogger
{
    private Logger $logger;
    
    public function __construct()
    {
        $this->logger = new Logger('errors');
        
        // File handler for error logs
        $fileHandler = new RotatingFileHandler(
            storage_path('logs/errors.log'),
            7, // Keep 7 days
            Logger::ERROR
        );
        $fileHandler->setFormatter(new JsonFormatter());
        
        // Console handler for development
        if (env('APP_ENV') === 'development') {
            $consoleHandler = new StreamHandler('php://stderr', Logger::DEBUG);
            $this->logger->pushHandler($consoleHandler);
        }
        
        $this->logger->pushHandler($fileHandler);
    }
    
    public function logError(\Throwable $exception, array $context = []): void
    {
        $logContext = array_merge($context, [
            'exception_type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'request_data' => $this->getRequestData(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_address' => $this->getClientIP(),
            'timestamp' => date('c'),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
        
        $this->logger->error($exception->getMessage(), $logContext);
    }
    
    private function getRequestData(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'body' => $this->getRequestBody(),
            'headers' => $this->getRelevantHeaders()
        ];
    }
    
    private function getRequestBody(): string
    {
        $body = file_get_contents('php://input');
        
        // Don't log sensitive data
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                // Remove sensitive fields
                $sensitiveFields = ['password', 'token', 'secret', 'key'];
                foreach ($sensitiveFields as $field) {
                    if (isset($data[$field])) {
                        $data[$field] = '[REDACTED]';
                    }
                }
                return json_encode($data);
            }
        }
        
        return strlen($body) > 1000 ? '[LARGE_BODY]' : $body;
    }
    
    private function getRelevantHeaders(): array
    {
        $headers = [];
        $relevantHeaders = [
            'HTTP_AUTHORIZATION',
            'HTTP_CONTENT_TYPE',
            'HTTP_ACCEPT',
            'HTTP_USER_AGENT',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP'
        ];
        
        foreach ($relevantHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $value = $_SERVER[$header];
                
                // Mask authorization headers
                if ($header === 'HTTP_AUTHORIZATION') {
                    $value = 'Bearer [REDACTED]';
                }
                
                $headers[$header] = $value;
            }
        }
        
        return $headers;
    }
    
    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        
        return 'unknown';
    }
}

// Example error log entry:
/*
{
    "message": "Validation failed",
    "context": {
        "exception_type": "Glueful\\Exceptions\\ValidationException",
        "file": "/var/www/glueful/api/Controllers/UserController.php",
        "line": 45,
        "stack_trace": "#0 /var/www/glueful/api/Controllers/UserController.php(45): Glueful\\Validation\\UserValidator->validateRegistration()\n#1...",
        "request_data": {
            "method": "POST",
            "uri": "/api/v1/users",
            "query_string": "",
            "body": "{\"username\":\"test\",\"email\":\"test@example.com\",\"password\":\"[REDACTED]\"}",
            "headers": {
                "HTTP_CONTENT_TYPE": "application/json",
                "HTTP_AUTHORIZATION": "Bearer [REDACTED]",
                "HTTP_USER_AGENT": "Mozilla/5.0..."
            }
        },
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "ip_address": "192.168.1.100",
        "timestamp": "2024-01-15T10:30:00+00:00",
        "memory_usage": 2097152,
        "memory_peak": 4194304
    },
    "level": 400,
    "level_name": "ERROR",
    "channel": "errors",
    "datetime": {
        "date": "2024-01-15 10:30:00.000000",
        "timezone_type": 1,
        "timezone": "+00:00"
    }
}
*/
```

### Debug Mode Response

```php
<?php
// In development/debug mode, include more detailed error information

class DebugErrorHandler
{
    public function handleException(\Throwable $exception): array
    {
        $response = [
            'success' => false,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode() ?: 500
        ];
        
        if (env('APP_DEBUG', false)) {
            $response['debug'] = [
                'exception_type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'previous' => $exception->getPrevious() ? [
                    'message' => $exception->getPrevious()->getMessage(),
                    'file' => $exception->getPrevious()->getFile(),
                    'line' => $exception->getPrevious()->getLine()
                ] : null
            ];
        }
        
        return $response;
    }
}

// Example debug response:
/*
{
    "success": false,
    "message": "Database connection failed",
    "code": 500,
    "debug": {
        "exception_type": "PDOException",
        "file": "/var/www/glueful/api/Database/Connection.php",
        "line": 45,
        "trace": [
            {
                "file": "/var/www/glueful/api/Database/Connection.php",
                "line": 45,
                "function": "connect",
                "class": "Glueful\\Database\\Connection",
                "type": "->"
            }
        ],
        "previous": {
            "message": "SQLSTATE[HY000] [2002] Connection refused",
            "file": "/var/www/glueful/api/Database/Connection.php",
            "line": 30
        }
    }
}
*/
```

## Error Recovery Strategies

### Automatic Retry Mechanism

```javascript
// Client-side retry logic
class RetryableApiClient {
    constructor(options = {}) {
        this.maxRetries = options.maxRetries || 3;
        this.retryDelay = options.retryDelay || 1000;
        this.retryStatusCodes = options.retryStatusCodes || [500, 502, 503, 504];
    }
    
    async request(url, options = {}) {
        let lastError;
        
        for (let attempt = 0; attempt <= this.maxRetries; attempt++) {
            try {
                const response = await fetch(url, options);
                const data = await response.json();
                
                if (!response.ok) {
                    const error = new ApiError(data);
                    
                    // Don't retry client errors (4xx) except 429
                    if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                        throw error;
                    }
                    
                    // Retry server errors and rate limits
                    if (attempt < this.maxRetries && this.shouldRetry(response.status, error)) {
                        lastError = error;
                        await this.delay(this.calculateDelay(attempt, error));
                        continue;
                    }
                    
                    throw error;
                }
                
                return data;
                
            } catch (error) {
                if (error instanceof ApiError) {
                    throw error;
                }
                
                // Network errors - retry if attempts remaining
                lastError = error;
                if (attempt < this.maxRetries) {
                    await this.delay(this.calculateDelay(attempt));
                    continue;
                }
                
                throw error;
            }
        }
        
        throw lastError;
    }
    
    shouldRetry(statusCode, error) {
        // Retry server errors
        if (this.retryStatusCodes.includes(statusCode)) {
            return true;
        }
        
        // Retry rate limits with backoff
        if (statusCode === 429) {
            return true;
        }
        
        return false;
    }
    
    calculateDelay(attempt, error = null) {
        // Use Retry-After header if available
        if (error && error.details && error.details.retry_after) {
            return error.details.retry_after * 1000;
        }
        
        // Exponential backoff with jitter
        const baseDelay = this.retryDelay * Math.pow(2, attempt);
        const jitter = Math.random() * 0.1 * baseDelay;
        return baseDelay + jitter;
    }
    
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}
```

### Circuit Breaker Pattern

```javascript
// Circuit breaker for failing services
class CircuitBreaker {
    constructor(options = {}) {
        this.failureThreshold = options.failureThreshold || 5;
        this.resetTimeout = options.resetTimeout || 60000; // 1 minute
        this.monitoringPeriod = options.monitoringPeriod || 60000; // 1 minute
        
        this.state = 'CLOSED'; // CLOSED, OPEN, HALF_OPEN
        this.failureCount = 0;
        this.lastFailureTime = null;
        this.successCount = 0;
    }
    
    async call(fn) {
        if (this.state === 'OPEN') {
            if (Date.now() - this.lastFailureTime >= this.resetTimeout) {
                this.state = 'HALF_OPEN';
                this.successCount = 0;
            } else {
                throw new Error('Circuit breaker is OPEN - service unavailable');
            }
        }
        
        try {
            const result = await fn();
            this.onSuccess();
            return result;
            
        } catch (error) {
            this.onFailure();
            throw error;
        }
    }
    
    onSuccess() {
        this.failureCount = 0;
        
        if (this.state === 'HALF_OPEN') {
            this.successCount++;
            if (this.successCount >= 3) {
                this.state = 'CLOSED';
            }
        }
    }
    
    onFailure() {
        this.failureCount++;
        this.lastFailureTime = Date.now();
        
        if (this.failureCount >= this.failureThreshold) {
            this.state = 'OPEN';
        }
    }
    
    getState() {
        return {
            state: this.state,
            failureCount: this.failureCount,
            lastFailureTime: this.lastFailureTime
        };
    }
}

// Usage
const circuitBreaker = new CircuitBreaker({
    failureThreshold: 3,
    resetTimeout: 30000
});

async function callExternalService() {
    return await circuitBreaker.call(async () => {
        const response = await fetch('/api/external-service');
        if (!response.ok) {
            throw new Error('External service failed');
        }
        return response.json();
    });
}
```

## Testing Error Scenarios

### Unit Tests for Error Handling

```php
<?php
// tests/Unit/ErrorHandlingTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Glueful\Exceptions\{ValidationException, AuthenticationException};
use Glueful\Controllers\UserController;

class ErrorHandlingTest extends TestCase
{
    public function testValidationErrorResponse()
    {
        $controller = new UserController();
        
        // Test missing required fields
        $_POST = ['username' => 'test']; // Missing email and password
        
        $response = $controller->create();
        
        $this->assertFalse($response['success']);
        $this->assertEquals(400, $response['code']);
        $this->assertEquals('VALIDATION_ERROR', $response['error']['type']);
        $this->assertIsArray($response['error']['details']);
    }
    
    public function testAuthenticationErrorResponse()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');
        
        $controller = new UserController();
        $controller->authenticateUser('invalid_user', 'wrong_password');
    }
    
    public function testDatabaseErrorHandling()
    {
        // Mock database connection failure
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('connect')
                      ->willThrowException(new \PDOException('Connection failed'));
        
        $this->expectException(DatabaseException::class);
        
        $controller = new UserController($mockConnection);
        $controller->create();
    }
}
```

### Integration Tests

```php
<?php
// tests/Integration/ApiErrorHandlingTest.php

namespace Tests\Integration;

use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    public function testInvalidJsonRequest()
    {
        $response = $this->postJson('/api/v1/users', 'invalid json');
        
        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'type' => 'VALIDATION_ERROR'
                    ]
                ]);
    }
    
    public function testUnauthorizedAccess()
    {
        $response = $this->getJson('/api/v1/users');
        
        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'type' => 'AUTHENTICATION_ERROR'
                    ]
                ]);
    }
    
    public function testNotFoundResource()
    {
        $response = $this->getJson('/api/v1/users/nonexistent-id');
        
        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'type' => 'NOT_FOUND_ERROR'
                    ]
                ]);
    }
    
    public function testRateLimitExceeded()
    {
        // Make requests until rate limit is hit
        for ($i = 0; $i < 100; $i++) {
            $response = $this->postJson('/api/v1/users', [
                'username' => "user{$i}",
                'email' => "user{$i}@example.com"
            ]);
            
            if ($response->getStatusCode() === 429) {
                $response->assertJson([
                    'success' => false,
                    'error' => [
                        'type' => 'RATE_LIMIT_EXCEEDED'
                    ]
                ]);
                return;
            }
        }
        
        $this->fail('Rate limit was not triggered');
    }
}
```

### Frontend Error Testing

```javascript
// tests/error-handling.test.js

describe('Error Handling', () => {
    let apiClient;
    
    beforeEach(() => {
        apiClient = new ApiClient('/api/v1');
    });
    
    test('handles validation errors', async () => {
        // Mock validation error response
        fetch.mockResolvedValueOnce({
            ok: false,
            status: 400,
            json: () => Promise.resolve({
                success: false,
                message: 'Validation failed',
                code: 400,
                error: {
                    type: 'VALIDATION_ERROR',
                    details: [
                        { field: 'email', message: 'Email is required' }
                    ]
                }
            })
        });
        
        try {
            await apiClient.createUser({ username: 'test' });
            fail('Should have thrown an error');
        } catch (error) {
            expect(error).toBeInstanceOf(ValidationError);
            expect(error.fieldErrors.email).toContain('Email is required');
        }
    });
    
    test('handles network errors', async () => {
        fetch.mockRejectedValueOnce(new Error('Network error'));
        
        try {
            await apiClient.createUser({ username: 'test' });
            fail('Should have thrown an error');
        } catch (error) {
            expect(error.message).toBe('Network error occurred');
        }
    });
    
    test('retries on server errors', async () => {
        const retryClient = new RetryableApiClient({ maxRetries: 2 });
        
        // First call fails, second succeeds
        fetch
            .mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: () => Promise.resolve({
                    success: false,
                    message: 'Server error'
                })
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { id: 1, username: 'test' }
                })
            });
        
        const result = await retryClient.request('/users', { method: 'POST' });
        
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(result.success).toBe(true);
    });
});
```

---

This comprehensive error handling guide provides patterns and examples for robust error management in Glueful applications. Implement these patterns consistently across your application to provide a better developer and user experience.