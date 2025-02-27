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
     * @return mixed HTTP response
     */
    public function login()
    {
        try {
            $credentials = Request::getPostData();
            $result = $this->authService->authenticate($credentials);
            
            if (!$result) {
                return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            return Response::ok($result, 'Login successful')->send();
        } catch (\Exception $e) {
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
        try {
            $token = $this->authService->extractTokenFromRequest();
            
            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }
            
            $success = $this->authService->terminateSession($token);
            
            if ($success) {
                return Response::ok(null, 'Logged out successfully')->send();
            }
            
            return Response::error('Logout failed', Response::HTTP_BAD_REQUEST)->send();
        } catch (\Exception $e) {
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

            // Send verification email
            $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
                
            if (!$result) {
                return Response::error('Failed to send verification email', Response::HTTP_BAD_REQUEST)->send();
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

            // Send verification email
            $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
                
            if (!$result) {
                return Response::error('Failed to send verification email', Response::HTTP_BAD_REQUEST)->send();
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
            $token = $this->authService->extractTokenFromRequest();
            
            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }
            
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

    public function validateToken()
    {
        try {
            $token = $this->authService->extractTokenFromRequest();
            
            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }
            
            $result = $this->authService->validateAccessToken($token);

            if (!$result) {
                return Response::error('Invalid token', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            return Response::ok($result, 'Token is valid')->send();
        } catch (\Exception $e) {
            return Response::unauthorized('Invalid or expired token')->send();
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
                return Response::error('User not found with the provided email address', Response::HTTP_NOT_FOUND)->send();
            }

            // Send verification email
            $result = $this->verifier->sendPasswordResetEmail($postData['email']);
                
            if (!$result['success']) {
                return Response::error($result['message'] ?? 'Failed to send reset email', Response::HTTP_BAD_REQUEST)->send();
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
                return Response::error('User not found with the provided email address', Response::HTTP_NOT_FOUND)->send();
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