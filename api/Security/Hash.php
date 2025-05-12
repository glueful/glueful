<?php

declare(strict_types=1);

namespace Glueful\Security;

/**
 * Hash Generation and Verification System
 *
 * Provides secure hash generation and verification using multiple algorithms.
 * Supports MD5, SHA1, SHA256, SHA512, and BCrypt with length validation.
 */
final class Hash
{
    /** @var int Standard MD5 hash length */
    private const MD5_LENGTH = 32;

    /** @var int Standard SHA1 hash length */
    private const SHA1_LENGTH = 40;

    /** @var int Standard SHA256 hash length */
    private const SHA256_LENGTH = 64;

    /** @var int Standard SHA512 hash length */
    private const SHA512_LENGTH = 128;

    /** @var int Standard BCrypt hash length */
    private const BCRYPT_LENGTH = 60;

    /** @var array<string, int> Supported algorithms and their hash lengths */
    private const SUPPORTED_ALGORITHMS = [
        'md5' => self::MD5_LENGTH,
        'sha1' => self::SHA1_LENGTH,
        'sha256' => self::SHA256_LENGTH,
        'sha512' => self::SHA512_LENGTH,
        'bcrypt' => self::BCRYPT_LENGTH
    ];

    /**
     * Generate secure hash
     *
     * Creates hash using specified algorithm with length validation.
     *
     * @param string $input Input string to hash
     * @param string $algorithm Hash algorithm to use
     * @return string Generated hash
     * @throws \InvalidArgumentException If algorithm is not supported
     * @throws \RuntimeException If hash generation fails
     */
    public static function generate(string $input, string $algorithm = 'sha256'): string
    {
        $algorithm = strtolower($algorithm);

        if (!isset(self::SUPPORTED_ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException(
                "Unsupported algorithm. Use one of: " . implode(', ', array_keys(self::SUPPORTED_ALGORITHMS))
            );
        }

        $hash = match ($algorithm) {
            'md5' => md5($input),
            'sha1' => sha1($input),
            'sha256' => hash('sha256', $input),
            'sha512' => hash('sha512', $input),
            'bcrypt' => password_hash($input, PASSWORD_BCRYPT)
        };

        if (!self::validateHash($hash, $algorithm)) {
            throw new \RuntimeException("Hash generation failed");
        }

        return $hash;
    }

    /**
     * Verify hash match
     *
     * Compares input against stored hash using specified algorithm.
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $input Input to verify
     * @param string $hash Hash to compare against
     * @param string $algorithm Hash algorithm to use
     * @return bool True if hash matches
     */
    public static function verify(string $input, string $hash, string $algorithm = 'sha256'): bool
    {
        if ($algorithm === 'bcrypt') {
            return password_verify($input, $hash);
        }

        return hash_equals(self::generate($input, $algorithm), $hash);
    }

    /**
     * Validate hash format
     *
     * Checks if hash length matches expected length for algorithm.
     *
     * @param string $hash Hash to validate
     * @param string $algorithm Algorithm to validate against
     * @return bool True if hash is valid
     */
    private static function validateHash(string $hash, string $algorithm): bool
    {
        $expectedLength = self::SUPPORTED_ALGORITHMS[$algorithm];
        return strlen($hash) === $expectedLength;
    }

    /**
     * Get hash information
     *
     * Returns metadata about a hash including algorithm and length.
     *
     * @param string $hash Hash to analyze
     * @return array{length: int, algorithm: ?string, timestamp: int} Hash information
     */
    public static function getInfo(string $hash): array
    {
        return [
            'length' => strlen($hash),
            'algorithm' => self::detectAlgorithm($hash),
            'timestamp' => time()
        ];
    }

    /**
     * Detect hash algorithm
     *
     * Attempts to identify algorithm based on hash length.
     *
     * @param string $hash Hash to analyze
     * @return string|null Detected algorithm or null if unknown
     */
    private static function detectAlgorithm(string $hash): ?string
    {
        $length = strlen($hash);
        foreach (self::SUPPORTED_ALGORITHMS as $algo => $len) {
            if ($length === $len) {
                return $algo;
            }
        }
        return null;
    }
}
