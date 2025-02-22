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
    public const CHARSET_NANOID = '_-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    
    /** @var string Alias for NanoID charset */
    public const CHARSET_ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    /** @var string Numeric characters only */
    public const CHARSET_NUMERIC = '0123456789';
    
    /** @var string Alphabetic characters (upper and lower case) */
    public const CHARSET_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    /** @var string Lowercase alphabetic characters */
    public const CHARSET_ALPHA_LOWER = 'abcdefghijklmnopqrstuvwxyz';
    
    /** @var string Uppercase alphabetic characters */
    public const CHARSET_ALPHA_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    
    /** @var int Binary mask for 64-character alphabet */
    private const MASK = 63; // 0b00111111

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

        $result = '';
        $bytes = random_bytes($length);
        $charsetLength = strlen($charset);
        
        for ($i = 0; $i < $length; $i++) {
            $index = (int) (ord($bytes[$i]) % $charsetLength);
            $result .= $charset[$index];
        }
        
        return $result;
    }
}
