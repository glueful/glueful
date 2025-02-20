<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

/**
 * One-Time Password Generator and Validator
 * 
 * Generates and validates secure OTPs for authentication.
 * Supports both numeric and alphanumeric codes with expiration.
 */
class OTP
{
    /** @var int Default OTP length */
    private const DEFAULT_LENGTH = 6;
    
    /** @var int Default expiration time in seconds (15 minutes) */
    private const DEFAULT_EXPIRY = 900;
    
    /** @var string Characters used for alphanumeric OTPs */
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate numeric OTP
     * 
     * Creates random numeric code of specified length.
     * 
     * @param int $length Code length
     * @return string Generated OTP
     * @throws \InvalidArgumentException If length is invalid
     */
    public static function generateNumeric(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('OTP length must be positive');
        }
        
        return str_pad((string)random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Generate alphanumeric OTP
     * 
     * Creates random alphanumeric code of specified length.
     * 
     * @param int $length Code length
     * @return string Generated OTP
     * @throws \InvalidArgumentException If length is invalid
     */
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

    /**
     * Verify OTP validity
     * 
     * Checks if provided OTP matches stored value and hasn't expired.
     * 
     * @param string $storedOTP Reference OTP
     * @param string $providedOTP OTP to verify
     * @param int $timestamp Creation timestamp
     * @param int $expiry Expiration period in seconds
     * @return bool True if OTP is valid
     */
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

    /**
     * Hash OTP value
     * 
     * Creates secure hash of OTP for storage.
     * 
     * @param string $otp OTP to hash
     * @return string Hashed OTP
     */
    public static function hashOTP(string $otp): string
    {
        return Hash::generate($otp, 'bcrypt');
    }

    /**
     * Verify hashed OTP
     * 
     * Compares provided OTP against stored hash.
     * 
     * @param string $providedOTP OTP to verify
     * @param string $hashedOTP Stored hash
     * @return bool True if OTP matches
     */
    public static function verifyHashedOTP(string $providedOTP, string $hashedOTP): bool
    {
        return Hash::verify($providedOTP, $hashedOTP, 'bcrypt');
    }

    /**
     * Check OTP expiration
     * 
     * Verifies if OTP has expired based on timestamp.
     * 
     * @param int $timestamp OTP creation time
     * @param int $expiry Expiration period in seconds
     * @return bool True if OTP has expired
     */
    public static function isExpired(int $timestamp, int $expiry = self::DEFAULT_EXPIRY): bool
    {
        return (time() - $timestamp) > $expiry;
    }
}
