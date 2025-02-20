<?php
declare(strict_types=1);

namespace Glueful\Api\Library\Security;

/**
 * Random String Generator
 * 
 * Generates cryptographically secure random strings using various character sets.
 * Optimized for NanoID-style generation with efficient bit operations.
 */
class RandomStringGenerator 
{
    /** @var string Character set for NanoID-compatible strings */
    public const CHARSET_NANOID = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    
    /** @var string Alias for NanoID charset */
    public const CHARSET_ALPHANUMERIC = self::CHARSET_NANOID;
    
    /** @var string Numeric characters only */
    public const CHARSET_NUMERIC = '0123456789';
    
    /** @var string Alphabetic characters (upper and lower case) */
    public const CHARSET_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    /** @var string Lowercase alphabetic characters */
    public const CHARSET_ALPHA_LOWER = 'abcdefghijklmnopqrstuvwxyz';
    
    /** @var string Uppercase alphabetic characters */
    public const CHARSET_ALPHA_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    
    /** @var int Binary mask for 64-character alphabet */
    private const MASK = 63; // 2^6 - 1

    /**
     * Generate random string
     * 
     * Creates cryptographically secure random string using specified charset.
     * 
     * @param int $length Desired string length
     * @param string $charset Character set to use
     * @return string Generated random string
     * @throws \InvalidArgumentException If length is invalid
     */
    public static function generate(
        int $length = 21,
        string $charset = self::CHARSET_NANOID
    ): string 
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Length must be greater than zero');
        }

        // For nanoid-style IDs, use optimized path
        if ($charset === self::CHARSET_NANOID) {
            return self::generateNanoStyle($length);
        }

        return self::generateWithCharset($length, $charset);
    }

    /**
     * Generate NanoID-style string
     * 
     * Optimized generation for 64-character alphabet using bit operations.
     * 
     * @param int $length Desired string length
     * @return string Generated string
     */
    private static function generateNanoStyle(int $length): string 
    {
        $result = '';
        $bytes = random_bytes($length);
        
        // Optimized for 64-character alphabet (6 bits per character)
        for ($i = 0; $i < $length; $i++) {
            $result .= self::CHARSET_NANOID[ord($bytes[$i]) & self::MASK];
        }
        
        return $result;
    }

    /**
     * Generate with custom charset
     * 
     * Uses rejection sampling for unbiased random string generation.
     * 
     * @param int $length Desired string length
     * @param string $charset Custom character set
     * @return string Generated string
     * @throws \InvalidArgumentException If charset is too short
     */
    private static function generateWithCharset(int $length, string $charset): string 
    {
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
