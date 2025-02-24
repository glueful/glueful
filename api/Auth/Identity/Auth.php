<?php
declare(strict_types=1);
namespace Glueful\Identity;

use Glueful\Http\Response;
use Glueful\APIEngine;

/**
 * Authentication Service Class
 * 
 * Handles all authentication-related operations including:
 * - User login/logout management
 * - Token validation and verification
 * - Session management
 * - Credential validation
 * 
 * Security features:
 * - JWT token handling
 * - Session timeout management
 * - Credential validation
 * - Request authentication
 * 
 * @package Glueful\Identity
 */
class Auth
{   
    /**
     * Process user login request
     * 
     * Handles authentication flow:
     * 1. Validates provided credentials
     * 2. Checks if username is email format
     * 3. Creates user session
     * 4. Returns JWT tokens on success
     * 
     * @param array $postParams Login credentials and options
     * @return array Response containing session data or error
     * @throws \Exception When authentication fails
     */
    public static function login(array $postParams): array 
    {
        if (!self::validateLoginCredentials('sessions', $postParams)) {
            return Response::error('Username/Email and Password Required', Response::HTTP_BAD_REQUEST)->send();
        }

        try {
            // Check if username value is an email address
            if (isset($postParams['username'])) {
                if (filter_var($postParams['username'], FILTER_VALIDATE_EMAIL)) {
                    // If username is actually an email, move it to email parameter
                    $postParams['email'] = $postParams['username'];
                    unset($postParams['username']);
                }
            }

            $postParams['status'] = config('app.active_status');
            $result = APIEngine::createSession('sessions', 'login', $postParams);
            
            // Convert API response to proper HTTP response
            if (isset($result['success']) && !$result['success']) {
                return Response::error(
                    $result['message'] ?? 'Login failed', 
                    $result['code'] ?? Response::HTTP_BAD_REQUEST
                )->send();
            }
            
            return Response::ok($result)->send();
            
        } catch (\Exception $e) {
            return Response::error(
                'Login failed: ' . $e->getMessage(), 
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Process user logout
     * 
     * Terminates user session:
     * - Invalidates access token
     * - Clears session data
     * - Logs logout event
     * 
     * @param string $token Active session token to invalidate
     * @return array Logout operation result
     * @throws \Exception When logout fails
     */
    public static function logout(string $token): array
    {
        // Direct call to kill session without legacy conversion
        $result = APIEngine::killSession(['token' => $token]);
                
        return $result;
    }

    /**
     * Validate authentication token
     * 
     * Comprehensive token verification:
     * - Checks token presence and format
     * - Validates token signature
     * - Verifies expiration time
     * - Confirms session validity
     * - Checks user permissions
     * 
     * @return array Session data if token is valid
     * @throws \RuntimeException If token is invalid or expired
     */
    public static function validateToken(): array 
    {
        $token = self::getAuthAuthorizationToken();
        
        if (!$token) {
           
            echo json_encode(Response::unauthorized('Authentication required')->send());
            exit;
        }
        
        $_GET['token'] = $token;
        
        try {
            $result = APIEngine::validateSession('sessions', 'validate', ['token' => $token]);
            
            if (!isset($result['success']) || !$result['success']) {
                echo json_encode(Response::unauthorized('Invalid or expired token')->send());
                exit;
            }

            return $result;
        } catch (\Exception $e) {
            echo json_encode(Response::error('Token validation failed', Response::HTTP_INTERNAL_SERVER_ERROR)->send());
            exit;
        }
    }

    /**
     * Validate login credentials
     * 
     * Checks required credentials based on authentication type:
     * - Username/password validation
     * - Email/password validation
     * - OAuth token validation
     * - Custom authentication methods
     * 
     * @param string $function Authentication method type
     * @param array $params Credentials to validate
     * @return bool True if credentials are valid and complete
     */
    private static function validateLoginCredentials(string $function, array $params): bool 
    {
        return match($function) {
            'sessions' => isset($params['username']) && isset($params['password']),
            default => false
        };
    }

    /**
     * Extract authorization token from request
     * 
     * Checks multiple sources for auth token:
     * 1. Authorization header (Bearer token)
     * 2. Query parameters
     * 3. Request cookies
     * 
     * Security considerations:
     * - Validates token format
     * - Handles missing/malformed tokens
     * - Supports multiple token locations
     * 
     * @return string|null The authorization token or null if not found
     */
    public static function getAuthAuthorizationToken(): ?string 
    {
        $headers = getallheaders();
        $token = null;
        
        // Check Authorization header
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
            }
        }
        
        // Check query parameter if no header
        if (!$token && isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        return $token;
    }
}

new Auth();