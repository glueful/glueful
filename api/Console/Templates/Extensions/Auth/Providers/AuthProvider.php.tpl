<?php
declare(strict_types=1);

namespace Glueful\Extensions\{{EXTENSION_NAME}}\Providers;

use Glueful\Auth\AuthProvider;
use Glueful\Auth\UserInterface;
use Glueful\Exceptions\AuthenticationException;

/**
 * {{EXTENSION_NAME}} Authentication Provider
 */
class {{EXTENSION_NAME}}AuthProvider implements AuthProvider
{
    /**
     * Configuration for this provider
     */
    private array $config;
    
    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        // Load configuration or use provided config
        $this->config = $config;
        if (empty($this->config) && file_exists(__DIR__ . '/../config.php')) {
            $this->config = require __DIR__ . '/../config.php';
        }
    }
    
    /**
     * Authenticate a user
     *
     * @param array $credentials User credentials
     * @return array Authentication result with token and user data
     * @throws AuthenticationException If authentication fails
     */
    public function authenticate(array $credentials): array
    {
        // Validate credentials
        if (!isset($credentials['username']) || !isset($credentials['password'])) {
            throw new AuthenticationException('Username and password are required');
        }
        
        // Implement your authentication logic here
        // Example:
        // 1. Check credentials against your database
        // 2. If valid, create a token
        // 3. Return token and user data
        
        // For demonstration only (replace with real implementation)
        if ($credentials['username'] === 'demo' && $credentials['password'] === 'password') {
            $user = [
                'id' => 1,
                'username' => 'demo',
                'email' => 'demo@example.com',
                'name' => 'Demo User'
            ];
            
            // Generate token (replace with your actual token generation)
            $token = md5(uniqid((string)rand(), true));
            
            return [
                'token' => $token,
                'user' => $user
            ];
        }
        
        throw new AuthenticationException('Invalid credentials');
    }
    
    /**
     * Validate an authentication token
     *
     * @param string $token Authentication token
     * @return UserInterface|array User data
     * @throws AuthenticationException If token is invalid
     */
    public function validateToken(string $token): UserInterface|array
    {
        // Implement token validation logic
        // Example:
        // 1. Check token validity
        // 2. Return user data if token is valid
        
        // For demonstration only (replace with real implementation)
        throw new AuthenticationException('Token validation not implemented');
    }
    
    /**
     * Get provider name
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return '{{EXTENSION_NAME}}';
    }
}