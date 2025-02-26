<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Security\EmailVerification;
use Glueful\Auth\AuthenticationService;

class AuthController {
    private EmailVerification $verifier;
    private AuthenticationService $authService;
    
    public function __construct() {
        $this->verifier = new EmailVerification();
        $this->authService = new AuthenticationService();
    }

    /**
     * User login
     * 
     * Authenticates user with credentials and returns tokens.
     * 
     * @return Response HTTP response
     */
    public function login(): Response
    {
        $credentials = Request::getPostData();
        $result = $this->authService->authenticate($credentials);
        
        if (!$result) {
            return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }
        
        return Response::ok($result, 'Login successful');
    }
    
    /**
     * User logout
     * 
     * Terminates user session and invalidates tokens.
     * 
     * @return Response HTTP response
     */
    public function logout(): Response
    {
        $token = $this->authService->extractTokenFromRequest();
        
        if (!$token) {
            return Response::error('No token provided', Response::HTTP_BAD_REQUEST);
        }
        
        $success = $this->authService->terminateSession($token);
        
        if ($success) {
            return Response::ok(null, 'Logged out successfully');
        }
        
        return Response::error('Logout failed', Response::HTTP_BAD_REQUEST);
    }
    
    /**
     * Verify email for registration/password reset
     * 
     * @return Response HTTP response
     */
    public function verifyEmail(): Response
    {
        $postData = Request::getPostData();
        if (!isset($postData['email'])) {
            return Response::error('Email address is required', Response::HTTP_BAD_REQUEST);
        }

        $otp = $this->verifier->generateOTP();

        // Send verification email
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
            
        if (!$result) {
            return Response::error('Failed to send verification email', Response::HTTP_BAD_REQUEST);
        }

        return Response::ok([
            'data' => [
                'email' => $postData['email'],
                'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
            ]
        ], 'Verification code has been sent to your email');
    }

    /**
     * Verify OTP code
     * 
     * @return Response HTTP response
     */
    public function verifyOtp(): Response
    {
        $postData = Request::getPostData();
        if (!isset($postData['email']) || !isset($postData['otp'])) {
            return Response::error('Email and OTP are required', Response::HTTP_BAD_REQUEST);
        }
       
        $isValid = $this->verifier->verifyOTP($postData['email'], $postData['otp']);
                
        if (!$isValid) {
            return Response::error('Invalid or expired OTP', Response::HTTP_BAD_REQUEST);
        }

        return Response::ok([
            'data' => [
                'email' => $postData['email'],
                'verified' => true,
                'verified_at' => date('Y-m-d\TH:i:s\Z')
            ]
        ], 'OTP verified successfully');
    }

    /**
     * Resend OTP code
     * 
     * @return Response HTTP response
     */
    public function resendOtp(): Response
    {
        $postData = Request::getPostData();
        if (!isset($postData['email'])) {
            return Response::error('Email address is required', Response::HTTP_BAD_REQUEST);
        }

        $otp = $this->verifier->generateOTP();

        // Send verification email
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
            
        if (!$result) {
            return Response::error('Failed to send verification email', Response::HTTP_BAD_REQUEST);
        }

        return Response::ok([
            'data' => [
                'email' => $postData['email'],
                'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
            ]
        ], 'Verification code has been resent to your email');
    }

    /**
     * Refresh user permissions
     * 
     * Updates the session with fresh user permissions and returns a new token.
     * This endpoint is useful after role/permission changes for a user.
     * 
     * @return Response HTTP response
     */
    public function refreshPermissions(): Response
    {
        $token = $this->authService->extractTokenFromRequest();
        
        if (!$token) {
            return Response::error('No token provided', Response::HTTP_BAD_REQUEST);
        }
        
        $result = $this->authService->refreshPermissions($token);

        if (!$result) {
            return Response::error('Failed to refresh permissions', Response::HTTP_BAD_REQUEST);
        }
        
        return Response::ok($result, 'Permissions refreshed successfully');
    }

    public function validateToken(): Response
    {
        $token = $this->authService->extractTokenFromRequest();
        
        if (!$token) {
            return Response::error('No token provided', Response::HTTP_BAD_REQUEST);
        }
        
        $result = $this->authService->validateAccessToken($token);

        if (!$result) {
            return Response::error('Invalid token', Response::HTTP_UNAUTHORIZED);
        }
        
        return Response::ok($result, 'Token is valid');
    }

    public function forgotPassword(): Response
    {
        $postData = Request::getPostData();
        if (!isset($postData['email'])) {
            return Response::error('Email address is required', Response::HTTP_BAD_REQUEST);
        }

         // Check if user exists before attempting password reset
         if (!$this->authService->userExists($postData['email'], 'email')) {
            return Response::error('User not found with the provided email address', Response::HTTP_NOT_FOUND);
        }

        // Send verification email
        $result = $this->verifier->sendPasswordResetEmail($postData['email']);
            
        if (!$result['success']) {
            return Response::error($result['message'] ?? 'Failed to send reset email', Response::HTTP_BAD_REQUEST);
        }

        return Response::ok([
            'data' => [
                'email' => $postData['email'],
                'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
            ]
        ], 'Password reset instructions have been sent to your email');
    }

    /**
     * Reset user password
     * 
     * Securely changes a user's password after verification.
     * This endpoint:
     * - Validates the required input (email and new password)
     * - Checks that the user exists before attempting password change
     * - Securely hashes the new password using PHP's password_hash()
     * - Updates the password in the database
     * - Returns appropriate success or error messages
     * 
     * Request parameters:
     * - email: User's email address
     * - new_password: New password (plaintext, will be hashed)
     * - uuid: (Optional) User's UUID if email is not provided
     * 
     * Success response:
     * - 200 OK with confirmation message and timestamp
     * 
     * Error responses:
     * - 400 Bad Request if parameters are missing or invalid
     * - 404 Not Found if user doesn't exist
     * - 500 Internal Server Error for other failures
     * 
     * @return Response HTTP response
     */
    public function resetPassword(): Response
    {
        try {
            $postData = Request::getPostData();

            if (!isset($postData['email']) || !isset($postData['new_password'])) {
                return Response::error('Email and new password are required', Response::HTTP_BAD_REQUEST);
            }

            // Check if user exists before attempting password reset
            if (!$this->authService->userExists($postData['email'], 'email')) {
                return Response::error('User not found with the provided email address', Response::HTTP_NOT_FOUND);
            }

            // Hash the new password
            $hashedPassword = password_hash($postData['new_password'], PASSWORD_DEFAULT);
            
            // Use the service method to update the password
            $success = $this->authService->updatePassword(
                $postData['email'],
                $hashedPassword
            );
            
            if (!$success) {
                return Response::error('Failed to update password', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return Response::ok([
                'data' => [
                    'updated_at' => date('Y-m-d\TH:i:s\Z')
                ]
            ], 'Password has been reset successfully');
            
        } catch (\Exception $e) {
            return Response::error(
                'Password reset failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        
    }

    /**
     * Refresh authentication token
     * 
     * Generates a new access token using a valid refresh token.
     * This endpoint allows clients to obtain a new access token without
     * requiring the user to re-authenticate when their current token expires.
     * 
     * Request parameters:
     * - refresh_token: The refresh token provided during login or previous refresh
     * 
     * Success response:
     * - 200 OK with new access token and refresh token pair
     * 
     * Error responses:
     * - 400 Bad Request if refresh token is missing
     * - 401 Unauthorized if refresh token is invalid or expired
     * - 500 Internal Server Error for other failures
     * 
     * @return Response HTTP response
     */
    public function refreshToken(): Response
    {
        try {
            $postData = Request::getPostData();
            
            if (!isset($postData['refresh_token'])) {
                return Response::error('Refresh token is required', Response::HTTP_BAD_REQUEST);
            }
            
            $refreshToken = $postData['refresh_token'];
            $result = $this->authService->refreshTokens($refreshToken);
            
            if (!$result) {
                return Response::error('Invalid or expired refresh token', Response::HTTP_UNAUTHORIZED);
            }
            
            return Response::ok($result, 'Token refreshed successfully');
            
        } catch (\Exception $e) {
            return Response::error(
                'Token refresh failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}