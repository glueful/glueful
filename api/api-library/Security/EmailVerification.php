<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

use Glueful\Api\Extensions\Push\Email;
use Glueful\Api\Library\CacheEngine;
use Glueful\Api\Library\{APIEngine};

class EmailVerification
{
    public const OTP_LENGTH = 6;
    public const OTP_EXPIRY_MINUTES = 15;
    private const OTP_PREFIX = 'email_verification:';
    private const ATTEMPTS_PREFIX = 'email_verification_attempts:';
    private const MAX_ATTEMPTS = 3;
    private const COOLDOWN_MINUTES = 30;
    private const MAX_DAILY_REQUESTS = 10;
    private const REQUESTS_PREFIX = 'email_verification_requests:';

    public function __construct()
    {
        // Initialize CacheEngine with Redis driver
        CacheEngine::initialize('Glueful:', config('cache.default'));
    }

    public function generateOTP(): string
    {
        return OTP::generateNumeric(self::OTP_LENGTH);
    }

    public function sendVerificationEmail(string $email, string $otp): bool
    {
        try {
            if ($this->isRateLimited($email)) {
                error_log("Rate limit exceeded for email: $email");
                return false;
            }

            // Increment daily request counter
            if (!$this->incrementDailyRequests($email)) {
                error_log("Daily limit exceeded for email: $email");
                return false;
            }

            // Store OTP in Redis before sending email
            $hashedOTP = OTP::hashOTP($otp);
            $stored = $this->storeOTP($email, $hashedOTP);

            if (!$stored) {
                error_log("Failed to store OTP in cache for email: $email");
                return false;
            }

            $emailData = [
                'from' => $_ENV['MAIL_FROM_ADDRESS'],
                'to' => $email,
                'subject' => 'Email Verification Code',
                'message' => $this->getEmailTemplate($otp)
            ];

            $response = Email::process([], $emailData);
            return isset($response['SUCCESS']);
            
        } catch (\Exception $e) {
            error_log("Email verification failed: " . $e->getMessage());
            return false;
        }
    }

    private function storeOTP(string $email, string $hashedOTP): bool
    {
        $key = self::OTP_PREFIX . $email;
        return CacheEngine::set($key, [
            'otp' => $hashedOTP,
            'timestamp' => time()
        ], self::OTP_EXPIRY_MINUTES * 60);
    }

    public function verifyOTP(string $email, string $providedOTP): bool
    {
        $key = self::OTP_PREFIX . $email;
        $stored = CacheEngine::get($key);

        if (!$stored || empty($stored['otp']) || empty($stored['timestamp'])) {
            $this->incrementAttempts($email);
            return false;
        }

        $isValid = OTP::verifyHashedOTP($providedOTP, $stored['otp']);
        
        if ($isValid) {
            // Clear rate limiting on success
            $this->clearAttempts($email);
            CacheEngine::delete($key);
            return true;
        }

        // Increment failed attempts
        $this->incrementAttempts($email);
        return false;
    }

    private function isRateLimited(string $email): bool
    {
        $attempts = CacheEngine::get(self::ATTEMPTS_PREFIX . $email) ?? 0;
        return $attempts >= self::MAX_ATTEMPTS;
    }

    private function incrementAttempts(string $email): void
    {
        $key = self::ATTEMPTS_PREFIX . $email;
        $attempts = (int)(CacheEngine::get($key) ?? 0) + 1;
        CacheEngine::set($key, $attempts, self::COOLDOWN_MINUTES * 60);

        if ($attempts >= self::MAX_ATTEMPTS) {
            error_log("Account temporary locked for email: $email");
        }
    }

    private function clearAttempts(string $email): void
    {
        CacheEngine::delete(self::ATTEMPTS_PREFIX . $email);
    }

    private function incrementDailyRequests(string $email): bool
    {
        $key = self::REQUESTS_PREFIX . $email . ':' . date('Y-m-d');
        $requests = (int)(CacheEngine::get($key) ?? 0) + 1;
        
        if ($requests > self::MAX_DAILY_REQUESTS) {
            return false;
        }

        // Set expiry to end of day
        $endOfDay = strtotime('tomorrow') - time();
        CacheEngine::set($key, $requests, $endOfDay);
        return true;
    }

    private function getEmailTemplate(string $otp): string
    {
        return "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2>Email Verification</h2>
                <p>Your verification code is: <strong>{$otp}</strong></p>
                <p>This code will expire in " . self::OTP_EXPIRY_MINUTES . " minutes.</p>
                <p>If you didn't request this code, please ignore this email.</p>
            </div>
        ";
    }

    public function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function sendPasswordResetEmail(string $email): array 
    {
        try {
            $verifier = new self();
            
            if (!$verifier->isValidEmail($email)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format'
                ];
            }

            // Check if email exists in users table
            $userData = APIEngine::getData('users', 'list', ['email' => $email]);
            if (empty($userData)) {
                return [
                    'success' => false,
                    'message' => 'Email address not found'
                ];
            }

            // Check rate limiting
            if ($verifier->isRateLimited($email)) {
                return [
                    'success' => false,
                    'message' => 'Too many attempts. Please try again later.'
                ];
            }

            // Generate OTP
            $otp = $verifier->generateOTP();

            // Prepare email data
            $emailData = [
                'from' => config('mail.from.address'),
                'to' => $email,
                'subject' => 'Password Reset Code',
                'message' => str_replace(
                    ['{name}', '{otp}', '{expiry_minutes}'],
                    [
                        $userData[0]['first_name'], 
                        $otp,
                        self::OTP_EXPIRY_MINUTES
                    ],
                    $verifier->getEmailTemplate($otp)
                )
            ];

            // Send email using Email::process
            $emailResult = Email::process([], $emailData);
            if (!isset($emailResult['SUCCESS'])) {
                throw new \RuntimeException('Failed to send email');
            }

            // Store OTP only after email is sent successfully
            if (!$verifier->sendVerificationEmail($email, $otp)) {
                throw new \RuntimeException('Failed to initialize password reset');
            }

            return [
                'success' => true,
                'message' => 'Password reset code sent to your email',
                'email' => $email,
                'expires_in' => self::OTP_EXPIRY_MINUTES * 60
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
