<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

final class Hash 
{
    private const MD5_LENGTH = 32;
    private const SHA1_LENGTH = 40;
    private const SHA256_LENGTH = 64;
    private const SHA512_LENGTH = 128;
    private const BCRYPT_LENGTH = 60;

    private const SUPPORTED_ALGORITHMS = [
        'md5' => self::MD5_LENGTH,
        'sha1' => self::SHA1_LENGTH,
        'sha256' => self::SHA256_LENGTH,
        'sha512' => self::SHA512_LENGTH,
        'bcrypt' => self::BCRYPT_LENGTH
    ];

    public static function generate(string $input, string $algorithm = 'sha256'): string 
    {
        $algorithm = strtolower($algorithm);
        
        if (!isset(self::SUPPORTED_ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException(
                "Unsupported algorithm. Use one of: " . implode(', ', array_keys(self::SUPPORTED_ALGORITHMS))
            );
        }

        $hash = match($algorithm) {
            'md5' => md5($input),
            'sha1' => sha1($input),
            'sha256' => hash('sha256', $input),
            'sha512' => hash('sha512', $input),
            'bcrypt' => password_hash($input, PASSWORD_BCRYPT),
            default => throw new \InvalidArgumentException("Invalid hash algorithm")
        };

        if (!self::validateHash($hash, $algorithm)) {
            throw new \RuntimeException("Hash generation failed");
        }

        return $hash;
    }

    public static function verify(string $input, string $hash, string $algorithm = 'sha256'): bool 
    {
        if ($algorithm === 'bcrypt') {
            return password_verify($input, $hash);
        }

        return hash_equals(self::generate($input, $algorithm), $hash);
    }

    private static function validateHash(string $hash, string $algorithm): bool 
    {
        $expectedLength = self::SUPPORTED_ALGORITHMS[$algorithm];
        return strlen($hash) === $expectedLength;
    }

    public static function getInfo(string $hash): array 
    {
        return [
            'length' => strlen($hash),
            'algorithm' => self::detectAlgorithm($hash),
            'timestamp' => time()
        ];
    }

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
