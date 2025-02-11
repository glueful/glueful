<?php

declare(strict_types=1);

namespace Mapi\Api\Library\Security;

use Mapi\Api\Extensions\Push\Email;
use Mapi\Api\Library\CacheEngine;

class EmailVerification
{
    private const OTP_LENGTH = 6;
    private const OTP_EXPIRY_MINUTES = 15;
    private const OTP_PREFIX = 'email_verification:';

    public function __construct()
    {
        // Initialize CacheEngine with Redis driver
        CacheEngine::initialize('mapi:', 'redis');
    }

    public function generateOTP(): string
    {
        return OTP::generateNumeric(self::OTP_LENGTH);
    }

    public function sendVerificationEmail(string $email, string $otp): bool
    {
        try {
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
            return false;
        }

        $isValid = OTP::verifyHashedOTP($providedOTP, $stored['otp']);
        
        if ($isValid) {
            // Remove OTP after successful verification
            CacheEngine::delete($key);
        }

        return $isValid && !OTP::isExpired($stored['timestamp'], self::OTP_EXPIRY_MINUTES * 60);
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
}
