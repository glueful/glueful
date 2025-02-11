<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class JWTService 
{
    private const ALGORITHM = 'HS256';
    private static string $secret;
    private static array $invalidatedTokens = [];
    
    public static function initialize(string $secret): void 
    {
        self::$secret = $secret;
    }
    
    public static function generate(array $payload, int $expireInSeconds = 3600): string 
    {
        $header = [
            'typ' => 'JWT',
            'alg' => self::ALGORITHM
        ];
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $expireInSeconds;
        
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac(
            'sha256', 
            "$headerEncoded.$payloadEncoded", 
            self::$secret, 
            true
        );
        
        return "$headerEncoded.$payloadEncoded." . self::base64UrlEncode($signature);
    }
    
    public static function invalidate(string $token): bool 
    {
        if (!self::verify($token)) {
            return false;
        }
        
        self::$invalidatedTokens[$token] = time();
        return true;
    }
    
    public static function verify(string $token): ?array 
    {
        if (isset(self::$invalidatedTokens[$token])) {
            return null;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac(
            'sha256', 
            "$headerEncoded.$payloadEncoded", 
            self::$secret, 
            true
        );
        
        if (!hash_equals($signature, $expectedSignature)) {
            return null;
        }
        
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        
        if ($payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    private static function base64UrlEncode(string $data): string 
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode(string $data): string 
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}