<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

class OTP
{
    private const DEFAULT_LENGTH = 6;
    private const DEFAULT_EXPIRY = 900; // 15 minutes in seconds
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function generateNumeric(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('OTP length must be positive');
        }
        
        return str_pad((string)random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    public static function generateAlphanumeric(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('OTP length must be positive');
        }

        $chars = str_split(self::ALPHANUMERIC_CHARS);
        $otp = '';
        
        for ($i = 0; $i < $length; $i++) {
            $otp .= $chars[random_int(0, strlen(self::ALPHANUMERIC_CHARS) - 1)];
        }

        return $otp;
    }

    public static function verifyOTP(string $storedOTP, string $providedOTP, int $timestamp, int $expiry = self::DEFAULT_EXPIRY): bool
    {
        if (empty($storedOTP) || empty($providedOTP)) {
            return false;
        }

        // Check expiration
        if (self::isExpired($timestamp, $expiry)) {
            return false;
        }

        // Case-insensitive comparison for alphanumeric OTPs
        return hash_equals(
            strtoupper($storedOTP),
            strtoupper($providedOTP)
        );
    }

    public static function hashOTP(string $otp): string
    {
        return Hash::generate($otp, 'bcrypt');
    }

    public static function verifyHashedOTP(string $providedOTP, string $hashedOTP): bool
    {
        return Hash::verify($providedOTP, $hashedOTP, 'bcrypt');
    }

    public static function isExpired(int $timestamp, int $expiry = self::DEFAULT_EXPIRY): bool
    {
        return (time() - $timestamp) > $expiry;
    }
}
