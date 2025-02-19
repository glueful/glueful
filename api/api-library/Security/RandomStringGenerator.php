<?php
declare(strict_types=1);

namespace Glueful\Api\Library\Security;

class RandomStringGenerator {
    // Updated to match SQL function's charset exactly
    public const CHARSET_NANOID = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    public const CHARSET_ALPHANUMERIC = self::CHARSET_NANOID; // Alias for compatibility
    
    // Other charsets
    public const CHARSET_NUMERIC = '0123456789';
    public const CHARSET_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const CHARSET_ALPHA_LOWER = 'abcdefghijklmnopqrstuvwxyz';
    public const CHARSET_ALPHA_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    
    private const MASK = 63; // Binary mask for 64 characters (2^6 - 1)

    public static function generate(
        int $length = 21,
        string $charset = self::CHARSET_NANOID
    ): string {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Length must be greater than zero');
        }

        // For nanoid-style IDs, use optimized path
        if ($charset === self::CHARSET_NANOID) {
            return self::generateNanoStyle($length);
        }

        return self::generateWithCharset($length, $charset);
    }

    private static function generateNanoStyle(int $length): string {
        $result = '';
        $bytes = random_bytes($length);
        
        // Optimized for 64-character alphabet (6 bits per character)
        for ($i = 0; $i < $length; $i++) {
            $result .= self::CHARSET_NANOID[ord($bytes[$i]) & self::MASK];
        }
        
        return $result;
    }

    private static function generateWithCharset(int $length, string $charset): string {
        $charsetLength = strlen($charset);
        if ($charsetLength <= 1) {
            throw new \InvalidArgumentException('Charset must contain at least 2 characters');
        }

        $mask = (2 << (int)floor(log($charsetLength - 1) / log(2))) - 1;
        $step = (int)ceil(1.6 * $mask * $length / $charsetLength);

        $result = '';
        while (true) {
            $bytes = random_bytes($step);
            for ($i = 0; $i < $step; $i++) {
                $byte = ord($bytes[$i]) & $mask;
                if ($byte < $charsetLength) {
                    $result .= $charset[$byte];
                    if (strlen($result) === $length) {
                        return $result;
                    }
                }
            }
        }
    }
}
