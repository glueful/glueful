<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Helpers\Utils;
use Glueful\Security\EmailVerification;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\AuthBootstrap;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AuthController
{
    private EmailVerification $verifier;
    private AuthenticationService $authService;

    public function __construct()
    {
        $this->verifier = new EmailVerification();
        $this->authService = new AuthenticationService();

        // Initialize the authentication system
        AuthBootstrap::initialize();
    }

    /**
     * User login
     *
     * Authenticates user with credentials and returns tokens.
     * Supports different authentication providers.
     *
     * @return mixed HTTP response
     */
    public function login()
    {
        // Create request object for IP and user agent information
        $request = SymfonyRequest::createFromGlobals();

        try {
            // Get credentials using the getPostData method from our Helper Request class
            $credentials = Request::getPostData();

            $clientIp = $request->getClientIp();
            $userAgent = $request->headers->get('User-Agent');

            // Check if a specific provider was requested
            $providerName = null;
            if (isset($credentials['provider'])) {
                $providerName = $credentials['provider'];
            }

            // Authenticate with the specified provider or use default
            $result = $this->authService->authenticate($credentials, $providerName);

            // Get audit logger instance and check if it exists
            $auditLogger = AuditLogger::getInstance();

            // Debugging

            if (!$result) {
                // Log failed login attempt
                $logId = $auditLogger->authEvent(
                    'login_failure',
                    null,
                    [
                        'username' => $credentials['username'] ?? 'unknown',
                        'provider' => $providerName ?? 'default',
                        'ip_address' => $clientIp,
                        'user_agent' => $userAgent,
                        'reason' => 'Invalid credentials'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );

                return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Log successful login
            $userId = $result['user']['uuid'] ?? null;
            $logId = $auditLogger->authEvent(
                'login_success',
                $userId,
                [
                    'username' => $credentials['username'] ?? 'unknown',
                    'provider' => $providerName ?? 'default',
                    'ip_address' => $clientIp,
                    'user_agent' => $userAgent,
                    'session_id' => $result['token'] ?? null
                ],
                AuditEvent::SEVERITY_INFO
            );

            return Response::ok($result, 'Login successful')->send();
        } catch (\Exception $e) {
            // Log login error and the exception details
            error_log("[DEBUG] Login exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            try {
                $auditLogger = AuditLogger::getInstance();
                $auditLogger->authEvent(
                    'login_error',
                    null,
                    [
                        'error_message' => $e->getMessage(),
                        'username' => $credentials['username'] ?? 'unknown',
                        'provider' => $providerName ?? 'default',
                        'ip_address' => $request->getClientIp() ?? 'unknown',
                        'user_agent' => $request->headers->get('User-Agent') ?? 'unknown'
                    ],
                    AuditEvent::SEVERITY_ERROR
                );
            } catch (\Exception $logEx) {
                // Log any audit logging errors
                error_log("[DEBUG] Audit logging exception: " . $logEx->getMessage() . "\n" .
                    $logEx->getTraceAsString());
            }

            return Response::error(
                'Login failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * User logout
     *
     * Terminates user session and invalidates tokens.
     *
     * @return mixed HTTP response
     */
    public function logout()
    {
        // Convert globals to Symfony Request for compatibility with our new system
        $request = SymfonyRequest::createFromGlobals();

        try {
            $token = AuthenticationService::extractTokenFromRequest($request);

            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }

            // Try to get user information for the audit log before terminating the session
            $tokenStorage = new \Glueful\Auth\TokenStorageService();
            $session = $tokenStorage->getSessionByAccessToken($token);
            $userId = $session['user']['uuid'] ?? $session['uuid'] ?? null;

            // Get audit logger instance
            $auditLogger = AuditLogger::getInstance();

            $success = $this->authService->terminateSession($token);

            // Log the logout attempt regardless of success
            $logData = [
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'session_token' => $token,
                'success' => $success
            ];

            if ($success) {
                // Log successful logout
                $auditLogger->authEvent(
                    'logout_success',
                    $userId,
                    $logData,
                    AuditEvent::SEVERITY_INFO
                );

                return Response::ok(null, 'Logged out successfully')->send();
            }

            // Log failed logout
            $auditLogger->authEvent(
                'logout_failure',
                $userId,
                array_merge($logData, ['reason' => 'Session termination failed']),
                AuditEvent::SEVERITY_WARNING
            );

            return Response::error('Logout failed', Response::HTTP_BAD_REQUEST)->send();
        } catch (\Exception $e) {
            // Log logout error
            try {
                $auditLogger = AuditLogger::getInstance();
                $auditLogger->authEvent(
                    'logout_error',
                    $userId ?? null,
                    [
                        'error_message' => $e->getMessage(),
                        'token' => $token ?? 'unknown',
                        'ip_address' => $request->getClientIp() ?? 'unknown',
                        'user_agent' => $request->headers->get('User-Agent') ?? 'unknown'
                    ],
                    AuditEvent::SEVERITY_ERROR
                );
            } catch (\Exception $logEx) {
                // Silently handle logging errors
            }

            return Response::error(
                'Logout failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Verify email for registration/password reset
     *
     * @return mixed HTTP response
     */
    public function verifyEmail()
    {
        try {
            $postData = Request::getPostData();
            if (!isset($postData['email'])) {
                return Response::error('Email address is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $otp = $this->verifier->generateOTP();

            // Send verification email with the new return format (array with status info)
            $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);

            if (!$result['success']) {
                // Use the detailed error message from the verification service
                $errorMessage = $result['message'] ?? 'Failed to send verification email';

                // Map error codes to appropriate HTTP status codes using the utility
                $statusCode = isset($result['error_code'])
                    ? Utils::mapErrorCodeToStatusCode($result['error_code'])
                    : Response::HTTP_BAD_REQUEST;

                return Response::error($errorMessage, $statusCode)->send();
            }

            return Response::ok([
                'data' => [
                    'email' => $postData['email'],
                    'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
                ]
            ], 'Verification code has been sent to your email')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to send verification email: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Verify OTP code
     *
     * @return mixed HTTP response
     */
    public function verifyOtp()
    {
        try {
            $postData = Request::getPostData();
            if (!isset($postData['email']) || !isset($postData['otp'])) {
                return Response::error('Email and OTP are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $isValid = $this->verifier->verifyOTP($postData['email'], $postData['otp']);

            if (!$isValid) {
                return Response::error('Invalid or expired OTP', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok([
                'data' => [
                    'email' => $postData['email'],
                    'verified' => true,
                    'verified_at' => date('Y-m-d\TH:i:s\Z')
                ]
            ], 'OTP verified successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to verify OTP: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Resend OTP code
     *
     * @return mixed HTTP response
     */
    public function resendOtp()
    {
        try {
            $postData = Request::getPostData();
            if (!isset($postData['email'])) {
                return Response::error('Email address is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $otp = $this->verifier->generateOTP();

            // Send verification email with updated return format (array with status info)
            $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);

            if (!$result['success']) {
                // Use the detailed error message from the verification service
                $errorMessage = $result['message'] ?? 'Failed to send verification email';

                // Map error codes to appropriate HTTP status codes using the utility
                $statusCode = isset($result['error_code'])
                    ? Utils::mapErrorCodeToStatusCode($result['error_code'])
                    : Response::HTTP_BAD_REQUEST;

                return Response::error($errorMessage, $statusCode)->send();
            }

            return Response::ok([
                'data' => [
                    'email' => $postData['email'],
                    'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
                ]
            ], 'Verification code has been resent to your email')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to send verification email: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Refresh user permissions
     *
     * Updates the session with fresh user permissions and returns a new token.
     * This endpoint is useful after role/permission changes for a user.
     *
     * @return mixed HTTP response
     */
    public function refreshPermissions()
    {
        try {
            // Convert globals to Symfony Request for compatibility with our new system
            $request = SymfonyRequest::createFromGlobals();
            $token = AuthenticationService::extractTokenFromRequest($request);

            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }

            // Get session to extract user UUID
            $tokenStorage = new \Glueful\Auth\TokenStorageService();
            $session = $tokenStorage->getSessionByAccessToken($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Invalidate the permission cache for this user
            \Glueful\Permissions\PermissionManager::invalidateCache($session['user']['uuid']);

            // Refresh permissions in the session
            $result = $this->authService->refreshPermissions($token);

            if (!$result) {
                return Response::error('Failed to refresh permissions', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok($result, 'Permissions refreshed successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to refresh permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Validate if a token is valid and active
     *
     * Uses the authentication abstraction to verify token validity.
     *
     * @return mixed HTTP response
     */
    public function validateToken()
    {
        try {
            // Convert globals to Symfony Request for compatibility with our new system
            $request = SymfonyRequest::createFromGlobals();

            // Get token from request
            $token = AuthenticationService::extractTokenFromRequest($request);

            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }

            // Use our new authentication system to validate the token
            $authManager = AuthBootstrap::getManager();
            $userData = $authManager->authenticate($request);

            if (!$userData) {
                return Response::error('Invalid token', Response::HTTP_UNAUTHORIZED)->send();
            }

            return Response::ok([
                'user' => $userData,
                'is_valid' => true
            ], 'Token is valid')->send();
        } catch (\Exception $e) {
            return Response::unauthorized('Invalid or expired token: ' . $e->getMessage())->send();
        }
    }

    public function forgotPassword()
    {
        try {
            $postData = Request::getPostData();
            if (!isset($postData['email'])) {
                return Response::error('Email address is required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Check if user exists before attempting password reset
            if (!$this->authService->userExists($postData['email'], 'email')) {
                $errorMsg = 'User not found with the provided email address';
                return Response::error($errorMsg, Response::HTTP_NOT_FOUND)->send();
            }

            // Send verification email
            $result = $this->verifier->sendPasswordResetEmail($postData['email']);

            if (!$result['success']) {
                $errorMsg = $result['message'] ?? 'Failed to send reset email';
                return Response::error($errorMsg, Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok([
                'data' => [
                    'email' => $postData['email'],
                    'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
                ]
            ], 'Password reset instructions have been sent to your email')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to process password reset request: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Reset user password
     *
     * Securely changes a user's password after verification.
     *
     * @return mixed HTTP response
     */
    public function resetPassword()
    {
        try {
            $postData = Request::getPostData();

            if (!isset($postData['email']) || !isset($postData['new_password'])) {
                return Response::error('Email and new password are required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Check if user exists before attempting password reset
            if (!$this->authService->userExists($postData['email'], 'email')) {
                $errorMsg = 'User not found with the provided email address';
                return Response::error($errorMsg, Response::HTTP_NOT_FOUND)->send();
            }

            // Hash the new password
            $hashedPassword = password_hash($postData['new_password'], PASSWORD_DEFAULT);

            // Use the service method to update the password
            $success = $this->authService->updatePassword(
                $postData['email'],
                $hashedPassword
            );

            if (!$success) {
                return Response::error('Failed to update password', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok([
                'data' => [
                    'updated_at' => date('Y-m-d\TH:i:s\Z')
                ]
            ], 'Password has been reset successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Password reset failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Refresh authentication token
     *
     * Generates a new access token using a valid refresh token.
     *
     * @return mixed HTTP response
     */
    public function refreshToken()
    {
        try {
            $postData = Request::getPostData();

            if (!isset($postData['refresh_token'])) {
                return Response::error('Refresh token is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $refreshToken = $postData['refresh_token'];
            $result = $this->authService->refreshTokens($refreshToken);

            if (!$result) {
                return Response::error('Invalid or expired refresh token', Response::HTTP_UNAUTHORIZED)->send();
            }

            return Response::ok($result, 'Token refreshed successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Token refresh failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}
