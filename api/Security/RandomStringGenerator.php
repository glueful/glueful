<?php

declare(strict_types=1);

namespace Glueful\Security;

/**
 * Random String Generator
 *
 * Generates cryptographically secure random strings using various character sets.
 * Optimized for NanoID-style generation with efficient bit operations.
 */
class RandomStringGenerator
{
    /** @var string Character set for NanoID-compatible strings */
    public const CHARSET_NANOID = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

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
     * Generate random string with improved efficiency
     *
     * Creates cryptographically secure random string using specified charset.
     * This method is optimized for maximum entropy using bit masking.
     *
     * @param int $length Desired string length
     * @param string $charset Character set to use
     * @return string Generated random string
     * @throws \InvalidArgumentException If length is invalid
     */
    public static function generate(
        int $length = 21,
        string $charset = self::CHARSET_NANOID
    ): string {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Length must be greater than zero');
        }

        $result = '';
        $charsetLength = strlen($charset);

        // Find the largest mask that fits within charset length
        $mask = 1;
        while ($mask < $charsetLength) {
            $mask = ($mask << 1) | 1;
        }

        // Determine how many random bytes we need
        $bytes = random_bytes(max(32, $length * 2));
        $pos = 0;

        for ($i = 0; $i < $length; $i++) {
            // If we've used most of our bytes, generate more
            if ($pos >= strlen($bytes) - 4) {
                $bytes .= random_bytes(32);
            }

            // Get a random index using bit masking for maximum efficiency
            $idx = ord($bytes[$pos]) & $mask;
            $pos++;

            // If index is beyond charset length, get another one
            while ($idx >= $charsetLength) {
                $idx = ord($bytes[$pos]) & $mask;
                $pos++;
            }

            $result .= $charset[$idx];
        }

        return $result;
    }
}
