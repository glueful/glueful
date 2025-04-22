<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Authentication Manager
 * 
 * Central manager for authentication that supports multiple authentication strategies.
 * Provides a clean, unified interface for authentication across the application.
 * 
 * Features:
 * - Multiple authentication provider support
 * - Provider fallback/chaining
 * - Separation of routing and authentication logic
 * - Consistent error handling
 */
class AuthenticationManager
{
    /** @var AuthenticationProviderInterface[] */
    private array $providers = [];
    
    /** @var AuthenticationProviderInterface|null */
    private ?AuthenticationProviderInterface $defaultProvider = null;
    
    /** @var string|null Last authentication error */
    private ?string $lastError = null;
    
    /**
     * Create a new authentication manager
     * 
     * @param AuthenticationProviderInterface|null $defaultProvider Default authentication provider
     */
    public function __construct(?AuthenticationProviderInterface $defaultProvider = null)
    {
        if ($defaultProvider) {
            $this->setDefaultProvider($defaultProvider);
        } else {
            // Create default JWT provider if none specified
            $this->setDefaultProvider(new JwtAuthenticationProvider());
        }
    }
    
    /**
     * Set the default authentication provider
     * 
     * @param AuthenticationProviderInterface $provider The provider to set as default
     * @return self
     */
    public function setDefaultProvider(AuthenticationProviderInterface $provider): self
    {
        $this->defaultProvider = $provider;
        return $this;
    }
    
    /**
     * Register an authentication provider with a name
     * 
     * @param string $name Provider identifier
     * @param AuthenticationProviderInterface $provider The provider instance
     * @return self
     */
    public function registerProvider(string $name, AuthenticationProviderInterface $provider): self
    {
        $this->providers[$name] = $provider;
        return $this;
    }
    
    /**
     * Get a registered provider by name
     * 
     * @param string $name Provider identifier
     * @return AuthenticationProviderInterface|null The provider or null if not found
     */
    public function getProvider(string $name): ?AuthenticationProviderInterface
    {
        return $this->providers[$name] ?? null;
    }
    
    /**
     * Get all registered providers
     * 
     * @return AuthenticationProviderInterface[] Array of all registered providers
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
    
    /**
     * Authenticate a request
     * 
     * Attempts authentication using the default provider.
     * 
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(Request $request): ?array
    {
        return $this->authenticateWith($this->defaultProvider, $request);
    }
    
    /**
     * Authenticate a request using a specific provider
     * 
     * @param string $providerName The name of the provider to use
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticateWithProvider(string $providerName, Request $request): ?array
    {
        $provider = $this->getProvider($providerName);
        
        if (!$provider) {
            $this->lastError = "Authentication provider '$providerName' not found";
            return null;
        }
        
        return $this->authenticateWith($provider, $request);
    }
    
    /**
     * Authenticate a request with multiple providers in sequence
     * 
     * Tries each provider in order until one succeeds or all fail.
     * 
     * @param array $providerNames Names of providers to try
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated by any provider, null otherwise
     */
    public function authenticateWithProviders(array $providerNames, Request $request): ?array
    {
        foreach ($providerNames as $name) {
            $result = $this->authenticateWithProvider($name, $request);
            if ($result) {
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * Check if a user has admin privileges
     * 
     * @param array $userData User data from authentication
     * @return bool True if user has admin privileges
     */
    public function isAdmin(array $userData): bool
    {
        return $this->defaultProvider->isAdmin($userData);
    }
    
    /**
     * Get the current authentication error
     * 
     * @return string|null Error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }
    
    /**
     * Authenticate with a specific provider instance
     * 
     * @param AuthenticationProviderInterface $provider The provider to use
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticateWith(AuthenticationProviderInterface $provider, Request $request): ?array
    {
        $userData = $provider->authenticate($request);
        
        if (!$userData) {
            $this->lastError = $provider->getError();
        }
        
        return $userData;
    }

    /**
     * Log successful authentication access
     * 
     * Records authentication events for audit and monitoring purposes.
     * 
     * @param array $userData User data from authentication
     * @param Request $request The HTTP request
     * @return void
     */
    public function logAccess(array $userData, Request $request): void
    {
        if (!isset($userData['uuid'])) {
            return;
        }
        
        $logData = [
            'user_uuid' => $userData['uuid'],
            'ip_address' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => date('Y-m-d H:i:s'),
            'request_uri' => $request->getRequestUri(),
            'method' => $request->getMethod()
        ];
        
        // Log to file if Logging system is available
        if (class_exists('\\Glueful\\Logging\\Logger')) {
            try {
                call_user_func(['\\Glueful\\Logging\\Logger', 'info'], 
                    'Authentication success', 
                    $logData
                );
            } catch (\Throwable $e) {
                // Silently fail if logging fails
            }
        }
        
        // Store in database if needed
        try {
            if (class_exists('\\Glueful\\Database\\Connection')) {
                $connection = new \Glueful\Database\Connection();
                $pdo = $connection->getPDO();
                
                // Only log to database if auth_sessions table exists
                $stmt = $pdo->prepare("
                    INSERT INTO auth_sessions (
                        user_uuid, ip_address, user_agent, created_at, request_path, request_method
                    ) VALUES (
                        :user_uuid, :ip_address, :user_agent, :timestamp, :request_uri, :method
                    )
                ");
                
                $stmt->execute([
                    'user_uuid' => $logData['user_uuid'],
                    'ip_address' => $logData['ip_address'],
                    'user_agent' => $logData['user_agent'],
                    'timestamp' => $logData['timestamp'],
                    'request_uri' => $logData['request_uri'],
                    'method' => $logData['method']
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail if database logging fails
            // This ensures authentication still works even if logging fails
        }
    }
}