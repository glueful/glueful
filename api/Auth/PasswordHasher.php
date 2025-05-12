<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Password Hashing and Verification Service
 *
 * Provides secure password hashing and verification using PHP's password_* functions.
 * Supports different hashing algorithms and automatic rehashing when needed.
 */
class PasswordHasher
{
    /**
     * Default hashing algorithm
     */
    private const DEFAULT_ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Default options for password_hash
     */
    private array $options;

    /**
     * Constructor
     *
     * @param array $options Optional password hashing options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'cost' => 12,
            'memory_cost' => 65536,  // for Argon2
            'time_cost' => 4,        // for Argon2
            'threads' => 2           // for Argon2
        ], $options);
    }

    /**
     * Hash a password
     *
     * Creates a secure hash of the provided password.
     *
     * @param string $password Plain text password to hash
     * @param int|string|null $algorithm Password hashing algorithm constant
     * @return string Hashed password
     */
    public function hash(string $password, $algorithm = null): string
    {
        $algo = $algorithm ?? self::DEFAULT_ALGORITHM;
        return password_hash($password, $algo, $this->options);
    }

    /**
     * Verify a password against a hash
     *
     * Securely compares a plain text password against a hash.
     *
     * @param string $password Plain text password to verify
     * @param string $hash Hashed password to check against
     * @return bool True if password matches the hash
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehashing
     *
     * Determines if a password hash needs to be regenerated due to:
     * - Algorithm changes
     * - Cost parameter changes
     * - Security improvements
     *
     * @param string $hash Password hash to check
     * @param int|string|null $algorithm Password hashing algorithm constant
     * @return bool True if rehashing is needed
     */
    public function needsRehash(string $hash, $algorithm = null): bool
    {
        $algo = $algorithm ?? self::DEFAULT_ALGORITHM;
        return password_needs_rehash($hash, $algo, $this->options);
    }

    /**
     * Get info about a password hash
     *
     * Returns information about the provided hash including:
     * - Algorithm
     * - Options (cost, etc.)
     *
     * @param string $hash Password hash to analyze
     * @return array Hash information
     */
    public function getInfo(string $hash): array
    {
        return password_get_info($hash);
    }
}
