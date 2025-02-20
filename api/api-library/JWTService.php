<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

/**
 * JWT (JSON Web Token) Service
 * 
 * Handles JWT token generation, validation, and management.
 * Provides secure token-based authentication for the API.
 */
class JWTService 
{
    /** @var string JWT secret key */
    private static string $key;
    
    /** @var string Default hashing algorithm */
    private static string $algorithm = 'HS256';
    
    /** @var array Storage for invalidated tokens */
    private static array $invalidatedTokens = [];
    
    /**
     * Initialize JWT service
     * 
     * Sets up JWT secret key from configuration.
     * 
     * @throws \RuntimeException If JWT key is not configured
     */
    private static function initialize(): void 
    {
        if (!isset(self::$key)) {
            self::$key = config('session.jwt_key');
            if (!self::$key) {
                throw new \RuntimeException('JWT key not configured');
            }
        }
    }
    
    /**
     * Generate new JWT token
     * 
     * Creates a signed JWT token with provided payload and expiration.
     * 
     * @param array $payload Token payload data
     * @param int $expiration Token lifetime in seconds
     * @return string Generated JWT token
     */
    public static function generate(array $payload, int $expiration = 900): string 
    {
        self::initialize();

        $header = [
            'typ' => 'JWT',
            'alg' => config('session.jwt_algorithm') ?? self::$algorithm
        ];

        $payload['iat'] = time();  // Issued at
        $payload['exp'] = time() + $expiration;  // Expiration
        $payload['jti'] = bin2hex(random_bytes(16));  // JWT ID

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256', 
            $headerEncoded . '.' . $payloadEncoded, 
            self::$key, 
            true
        );

        $signatureEncoded = self::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Invalidate JWT token
     * 
     * Adds token to invalidation list if it's valid.
     * 
     * @param string $token JWT token to invalidate
     * @return bool True if token was invalidated
     */
    public static function invalidate(string $token): bool 
    {
        if (!self::verify($token)) {
            return false;
        }
        
        self::$invalidatedTokens[$token] = time();
        return true;
    }
    
    /**
     * Decode JWT token
     * 
     * Verifies and decodes JWT token into payload data.
     * 
     * @param string $token JWT token to decode
     * @return array|null Decoded payload or null if invalid
     */
    public static function decode(string $token): ?array 
    {
        self::initialize();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $signature = hash_hmac(
            'sha256', 
            $headerEncoded . '.' . $payloadEncoded, 
            self::$key, 
            true
        );

        $signatureProvided = self::base64UrlDecode($signatureEncoded);
        if (!hash_equals($signature, $signatureProvided)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        // Verify expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }
    
    /**
     * Verify JWT token
     * 
     * Checks if token is valid and not expired.
     * 
     * @param string $token JWT token to verify
     * @return bool True if token is valid
     */
    public static function verify(string $token): bool 
    {
        return self::decode($token) !== null;
    }
    
    /**
     * Base64URL encode
     * 
     * Encodes data for use in URL-safe JWT.
     * 
     * @param string $data Data to encode
     * @return string Base64URL encoded string
     */
    private static function base64UrlEncode(string $data): string 
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64URL decode
     * 
     * Decodes Base64URL encoded JWT components.
     * 
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private static function base64UrlDecode(string $data): string 
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * Extract claims from token
     * 
     * Gets payload claims excluding JWT metadata.
     * 
     * @param string $token JWT token
     * @return array|null Claims or null if invalid
     */
    public static function extractClaims(string $token): ?array 
    {
        $payload = self::decode($token);
        return $payload ? array_diff_key($payload, array_flip(['iat', 'exp', 'jti'])) : null;
    }

    /**
     * Check if token is expired
     * 
     * Verifies token expiration timestamp.
     * 
     * @param string $token JWT token
     * @return bool True if token is expired
     */
    public static function isExpired(string $token): bool 
    {
        $payload = self::decode($token);
        return !$payload || (isset($payload['exp']) && $payload['exp'] < time());
    }
}