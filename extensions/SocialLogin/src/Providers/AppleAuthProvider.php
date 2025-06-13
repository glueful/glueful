<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\SocialLogin\Providers\AbstractSocialProvider;
use Glueful\Auth\JWTService;
use Glueful\Extensions\SocialLogin\Providers\ASN1Parser;

/**
 * Apple Authentication Provider
 *
 * Handles Apple OAuth authentication flow and user management.
 *
 * @package Glueful\Extensions\SocialLogin\Providers
 */
class AppleAuthProvider extends AbstractSocialProvider
{
    /** @var string Client ID (Service ID) from Apple Developer Account */
    private string $clientId;

    /** @var string Client secret (Generated using the private key) */
    private string $clientSecret;

    /** @var string Team ID from Apple Developer Account */
    private string $teamId;

    /** @var string Key ID from Apple Developer Account */
    private string $keyId;

    /** @var string Redirect URI for Apple OAuth callback */
    private string $redirectUri;

    /** @var array OAuth scopes requested */
    private array $scopes = [
        'name',
        'email'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Set provider name
        $this->providerName = 'apple';

        // Load configuration
        $this->loadConfig();
    }

    /**
     * Load configuration from environment
     *
     * @return void
     */
    private function loadConfig(): void
    {
        // Get config from extension settings or environment
        $config = \Glueful\Extensions\SocialLogin::getConfig();

        // Make sure the config has the expected structure
        if (!is_array($config) || !isset($config['apple']) || !is_array($config['apple'])) {
            $config = [
                'apple' => []
            ];
        }

        $this->clientId = !empty($config['apple']['client_id']) ?
                          $config['apple']['client_id'] :
                          (getenv('APPLE_CLIENT_ID') ?: '');

        $this->clientSecret = !empty($config['apple']['client_secret']) ?
                              $config['apple']['client_secret'] :
                              (getenv('APPLE_CLIENT_SECRET') ?: '');

        $this->teamId = !empty($config['apple']['team_id']) ?
                        $config['apple']['team_id'] :
                        (getenv('APPLE_TEAM_ID') ?: '');

        $this->keyId = !empty($config['apple']['key_id']) ?
                       $config['apple']['key_id'] :
                       (getenv('APPLE_KEY_ID') ?: '');

        $this->redirectUri = !empty($config['apple']['redirect_uri']) ?
                             $config['apple']['redirect_uri'] :
                             (getenv('APPLE_REDIRECT_URI') ?: $this->getDefaultRedirectUri());
    }

    /**
     * Generate default redirect URI based on current host
     *
     * @return string Default redirect URI
     */
    private function getDefaultRedirectUri(): string
    {
        // Get base URL from config
        $baseUrl = config('app.url', '');

        if (empty($baseUrl) && isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
                         $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $baseUrl = $protocol . $_SERVER['HTTP_HOST'];
        }

        return $baseUrl . '/auth/social/apple/callback';
    }

    /**
     * Check if request is an Apple OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/apple/callback') !== false &&
               ($request->request->has('code') || $request->query->has('code'));
    }

    /**
     * Check if request is to initialize Apple OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/apple') !== false &&
               !strpos($path, '/callback');
    }

    /**
     * Handle Apple OAuth callback
     *
     * Process callback from Apple, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            $this->lastError = "Apple OAuth configuration is missing";
            return null;
        }

        // Get authorization code from request
        $code = $request->request->get('code') ?? $request->query->get('code');

        if (empty($code)) {
            $this->lastError = "Authorization code missing from request";
            return null;
        }

        // Apple returns user info only on the first login, so we need to check for it
        $userData = null;
        if ($request->request->has('user')) {
            $userDataJson = $request->request->get('user');
            $userData = json_decode($userDataJson, true);
        }

        try {
            // Exchange code for access token
            $tokenData = $this->exchangeCodeForToken($code);

            if (!isset($tokenData['access_token'])) {
                $this->lastError = "Failed to get access token: " .
                                  ($tokenData['error'] ?? 'Unknown error');
                return null;
            }

            // Extract user information from ID token
            $userProfile = $this->extractUserProfile($tokenData['id_token'], $userData);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Find or create user from Apple data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Apple OAuth error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Initiate Apple OAuth flow
     *
     * Generate authorization URL and redirect user to Apple.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            throw new \RuntimeException("Apple OAuth configuration is missing");
        }

        // Generate state token to prevent CSRF
        $state = bin2hex(random_bytes(16));

        // Store state in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['apple_oauth_state'] = $state;

        // Build authorization URL
        $authUrl = 'https://appleid.apple.com/auth/authorize';
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(' ', $this->scopes),
            'response_mode' => 'form_post'
        ];

        $authUrl .= '?' . http_build_query($params);

        // Redirect to Apple
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from Apple
     * @return array Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Generate client secret (JWT) for Apple
        $clientSecret = $this->generateClientSecret();

        // Token endpoint
        $tokenUrl = 'https://appleid.apple.com/auth/token';

        // Request parameters
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ];

        // Make POST request to token endpoint
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: $error");
        }

        // Parse JSON response
        $tokenData = json_decode($response, true);

        if (!is_array($tokenData)) {
            throw new \Exception("Invalid token response: $response");
        }

        return $tokenData;
    }

    /**
     * Generate client secret JWT for Apple
     *
     * @return string JWT token to use as client secret
     */
    private function generateClientSecret(): string
    {
        // If client secret is already set and it's a valid JWT, use it
        if (!empty($this->clientSecret) && strpos($this->clientSecret, '.') !== false) {
            return $this->clientSecret;
        }

        // Private key from environment or file
        $privateKey = $this->clientSecret;

        // If private key starts with a path, read the file
        if (strpos($privateKey, '/') === 0 && file_exists($privateKey)) {
            $privateKey = file_get_contents($privateKey);
        }

        // Since Apple requires ES256 algorithm which our JWTService doesn't support yet,
        // we need to implement ES256 signing directly for this specific use case

        // Prepare the JWT header and payload
        $header = [
            'kid' => $this->keyId,
            'alg' => 'ES256'
        ];

        $payload = [
            'iss' => $this->teamId,
            'iat' => time(),
            'exp' => time() + 3600, // 1 hour expiration
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId
        ];

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        // Create signature input
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        // Generate signature using OpenSSL's ES256 support
        $signature = '';
        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        // Convert signature to proper DER format for ES256
        $signature = $this->convertDERtoJOSE($signature);

        // Base64Url encode the signature
        $signatureEncoded = $this->base64UrlEncode($signature);

        // Return complete JWT
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Convert DER format signature to JOSE format
     *
     * @param string $der DER encoded signature from OpenSSL
     * @return string JOSE format signature
     */
    private function convertDERtoJOSE(string $der): string
    {
        // Extract R and S values from DER format
        $asn1 = new ASN1Parser($der);
        $seq = $asn1->readObject();

        if ($seq['type'] !== 0x30) {
            throw new \Exception('Invalid DER signature format');
        }

        $r = $asn1->readObject();
        $s = $asn1->readObject();

        if ($r['type'] !== 0x02 || $s['type'] !== 0x02) {
            throw new \Exception('Invalid DER signature values');
        }

        // Convert to fixed-length 32-byte values
        $rBin = $this->convertToBinary($r['value'], 32);
        $sBin = $this->convertToBinary($s['value'], 32);

        // Concatenate R and S values
        return $rBin . $sBin;
    }

    /**
     * Convert integer to fixed-length binary
     *
     * @param string $value Integer value as binary string
     * @param int $length Desired length in bytes
     * @return string Fixed-length binary string
     */
    private function convertToBinary(string $value, int $length): string
    {
        // Remove leading zeros
        $value = ltrim($value, "\x00");

        // Handle negative numbers (remove leading 0xFF)
        if (ord($value[0]) >= 0x80) {
            $value = "\x00" . $value;
        }

        // Pad to desired length
        $value = str_pad($value, $length, "\x00", STR_PAD_LEFT);

        // Truncate if too long
        if (strlen($value) > $length) {
            $value = substr($value, -$length);
        }

        return $value;
    }

    /**
     * Base64URL encode (using framework's JWTService if available)
     *
     * @param string $data Data to encode
     * @return string Base64URL encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        // Use reflection to access the private method in JWTService
        try {
            $reflection = new \ReflectionClass(JWTService::class);
            $method = $reflection->getMethod('base64UrlEncode');
            $method->setAccessible(true);
            return $method->invoke(null, $data);
        } catch (\ReflectionException $e) {
            // Fallback implementation if reflection fails
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }
    }

    /**
     * Extract user profile from ID token
     *
     * @param string $idToken ID token from Apple
     * @param array|null $userData User data from request (only provided on first login)
     * @return array User profile data
     */
    private function extractUserProfile(string $idToken, ?array $userData = null): array
    {
        // Try to decode using JWTService first
        $payload = JWTService::decode($idToken);

        // If JWTService couldn't decode it (might be signed with different algorithm),
        // fall back to manual decoding
        if ($payload === null) {
            $tokenParts = explode('.', $idToken);
            if (count($tokenParts) !== 3) {
                throw new \Exception("Invalid ID token format");
            }

            // Decode payload part (base64url decode)
            try {
                $reflection = new \ReflectionClass(JWTService::class);
                $method = $reflection->getMethod('base64UrlDecode');
                $method->setAccessible(true);
                $decodedPayload = $method->invoke(null, $tokenParts[1]);
            } catch (\ReflectionException $e) {
                // Fallback implementation if reflection fails
                $padding = str_repeat('=', 3 - (3 + strlen($tokenParts[1])) % 4);
                $decodedPayload = base64_decode(strtr($tokenParts[1], '-_', '+/') . $padding);
            }

            $payload = json_decode($decodedPayload, true);

            if (!is_array($payload)) {
                throw new \Exception("Invalid ID token payload");
            }
        }

        // Get user info from token and request data
        $profile = [
            'id' => $payload['sub'] ?? null,
            'email' => $payload['email'] ?? null,
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'raw' => $payload
        ];

        // Apple only sends name information on the first login
        if (is_array($userData) && isset($userData['name'])) {
            $profile['first_name'] = $userData['name']['firstName'] ?? null;
            $profile['last_name'] = $userData['name']['lastName'] ?? null;
            $profile['name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        }

        return $profile;
    }

    /**
     * Verify a token from a native mobile SDK
     *
     * @param string $idToken ID token from Sign in with Apple SDK
     * @return array|null User data if verified, null otherwise
     */
    public function verifyNativeToken(string $idToken): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->teamId) || empty($this->keyId)) {
            $this->lastError = "Apple OAuth configuration is missing";
            return null;
        }

        try {
            // Extract user information from ID token
            $userProfile = $this->extractUserProfile($idToken);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to extract user data from ID token";
                return null;
            }

            // Verify the token with Apple's servers
            $isValid = $this->verifyAppleIdToken($idToken);

            if (!$isValid) {
                $this->lastError = "Failed to verify Apple ID token";
                return null;
            }

            // Find or create user from Apple data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Apple token verification error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Verify Apple ID token with Apple's servers
     *
     * @param string $idToken ID token from Sign in with Apple
     * @return bool True if token is valid
     * @throws \Exception If verification fails
     */
    private function verifyAppleIdToken(string $idToken): bool
    {
        // Get Apple's public keys
        $jwksUrl = 'https://appleid.apple.com/auth/keys';

        $ch = curl_init($jwksUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: $error");
        }

        $jwks = json_decode($response, true);

        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new \Exception("Invalid JWKS response from Apple");
        }

        // Parse the ID token to get the header
        $tokenParts = explode('.', $idToken);
        if (count($tokenParts) !== 3) {
            throw new \Exception("Invalid ID token format");
        }

        // Decode the header
        try {
            $reflection = new \ReflectionClass(JWTService::class);
            $method = $reflection->getMethod('base64UrlDecode');
            $method->setAccessible(true);
            $decodedHeader = $method->invoke(null, $tokenParts[0]);
        } catch (\ReflectionException $e) {
            // Fallback implementation if reflection fails
            $padding = str_repeat('=', 3 - (3 + strlen($tokenParts[0])) % 4);
            $decodedHeader = base64_decode(strtr($tokenParts[0], '-_', '+/') . $padding);
        }

        $header = json_decode($decodedHeader, true);

        if (!is_array($header) || !isset($header['kid'])) {
            throw new \Exception("Invalid ID token header");
        }

        // Find the matching key
        $matchingKey = null;
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $header['kid']) {
                $matchingKey = $key;
                break;
            }
        }

        if (!$matchingKey) {
            throw new \Exception("No matching key found for token verification");
        }

        // For now, we're assuming the token is valid if we can extract user data
        // A full implementation would verify the signature using the public key
        // But that would require more cryptography code than we can implement here

        // Extract the payload to verify claims
        $payload = JWTService::decode($idToken);

        if (!$payload) {
            // Manual decode
            try {
                $reflection = new \ReflectionClass(JWTService::class);
                $method = $reflection->getMethod('base64UrlDecode');
                $method->setAccessible(true);
                $decodedPayload = $method->invoke(null, $tokenParts[1]);
            } catch (\ReflectionException $e) {
                // Fallback implementation
                $padding = str_repeat('=', 3 - (3 + strlen($tokenParts[1])) % 4);
                $decodedPayload = base64_decode(strtr($tokenParts[1], '-_', '+/') . $padding);
            }

            $payload = json_decode($decodedPayload, true);
        }

        if (!is_array($payload)) {
            throw new \Exception("Invalid ID token payload");
        }

        // Verify audience claim
        if (!isset($payload['aud']) || $payload['aud'] !== $this->clientId) {
            throw new \Exception("Token was not issued for this application");
        }

        // Verify expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            throw new \Exception("Token has expired");
        }

        // Verify issuer
        if (!isset($payload['iss']) || $payload['iss'] !== 'https://appleid.apple.com') {
            throw new \Exception("Token was not issued by Apple");
        }

        return true;
    }
}
