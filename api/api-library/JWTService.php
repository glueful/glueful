<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

class JWTService 
{
    private static string $key;
    private static string $algorithm = 'HS256';
    private static array $invalidatedTokens = [];
    
    private static function initialize(): void 
    {
        if (!isset(self::$key)) {
            self::$key = config('session.jwt_key');
            if (!self::$key) {
                throw new \RuntimeException('JWT key not configured');
            }
        }
    }
    
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
    
    public static function invalidate(string $token): bool 
    {
        if (!self::verify($token)) {
            return false;
        }
        
        self::$invalidatedTokens[$token] = time();
        return true;
    }
    
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
    
    public static function verify(string $token): bool 
    {
        return self::decode($token) !== null;
    }
    
    private static function base64UrlEncode(string $data): string 
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode(string $data): string 
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    public static function extractClaims(string $token): ?array 
    {
        $payload = self::decode($token);
        return $payload ? array_diff_key($payload, array_flip(['iat', 'exp', 'jti'])) : null;
    }

    public static function isExpired(string $token): bool 
    {
        $payload = self::decode($token);
        return !$payload || (isset($payload['exp']) && $payload['exp'] < time());
    }
}