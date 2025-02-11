<?php

declare(strict_types=1);

namespace Mapi\Api\Library\Security;

class RandomStringGenerator
{
    private const DEFAULT_RANDOM_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const DEFAULT_LENGTH = 32;

    /**
     * Generates a random string using a custom alphabet
     */
    public static function RandomStringWithAlphabet(int $length = self::DEFAULT_LENGTH, string $alphabet = self::DEFAULT_RANDOM_ALPHABET): string
    {
        $alphabetLength = strlen($alphabet);
        $result = '';

        try {
            for ($i = 0; $i < $length; $i++) {
                $result .= $alphabet[random_int(0, $alphabetLength - 1)];
            }
        } catch (\Exception $e) {
            // Fallback to less secure method if random_int fails
            for ($i = 0; $i < $length; $i++) {
                $result .= $alphabet[mt_rand(0, $alphabetLength - 1)];
            }
        }

        return $result;
    }

    /**
     * Generates a random string using the default alphabet
     */
    public static function RandomString(int $length = self::DEFAULT_LENGTH): string
    {
        return self::RandomStringWithAlphabet($length);
    }

    /**
     * Generates a pseudo-random string with custom alphabet (less secure, but faster)
     */
    public static function PseudorandomStringWithAlphabet(int $length = self::DEFAULT_LENGTH, string $alphabet = self::DEFAULT_RANDOM_ALPHABET): string
    {
        $alphabetLength = strlen($alphabet);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[mt_rand(0, $alphabetLength - 1)];
        }

        return $result;
    }

    /**
     * Generates a URL-safe random string
     */
    public static function URLSafeString(int $length = self::DEFAULT_LENGTH): string
    {
        return self::RandomStringWithAlphabet($length, 
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_');
    }

    /**
     * Generates a numeric-only random string
     */
    public static function NumericString(int $length = self::DEFAULT_LENGTH): string
    {
        return self::RandomStringWithAlphabet($length, '0123456789');
    }

    /**
     * Validates if a string matches the given alphabet
     */
    public static function ValidateAlphabet(string $input, string $alphabet = self::DEFAULT_RANDOM_ALPHABET): bool
    {
        return strlen($input) === strspn($input, $alphabet);
    }

}
