<?php

declare(strict_types=1);

namespace Glueful\Http\Authentication;

/**
 * Authentication Methods
 *
 * Helper class providing common HTTP authentication patterns and header
 * configurations for API clients, OAuth flows, and external services.
 */
class AuthenticationMethods
{
    /**
     * Create Bearer token authentication headers
     */
    public static function bearerToken(string $token): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ];
    }

    /**
     * Create Basic authentication configuration
     */
    public static function basicAuth(string $username, string $password): array
    {
        return [
            'auth_basic' => [$username, $password]
        ];
    }

    /**
     * Create API key authentication headers
     */
    public static function apiKey(string $key, string $headerName = 'X-API-Key'): array
    {
        return [
            'headers' => [
                $headerName => $key
            ]
        ];
    }

    /**
     * Create OAuth2 Bearer token authentication (alias for bearerToken)
     */
    public static function oauth2(string $accessToken): array
    {
        return self::bearerToken($accessToken);
    }

    /**
     * Create custom header authentication
     */
    public static function customHeader(string $name, string $value): array
    {
        return [
            'headers' => [
                $name => $value
            ]
        ];
    }

    /**
     * Create JWT token authentication
     */
    public static function jwt(string $token): array
    {
        return self::bearerToken($token);
    }

    /**
     * Create API key in query parameter
     */
    public static function apiKeyQuery(string $key, string $paramName = 'api_key'): array
    {
        return [
            'query' => [
                $paramName => $key
            ]
        ];
    }

    /**
     * Create AWS Signature V4 authentication headers
     */
    public static function awsSignature(string $accessKey, string $secretKey, string $service, string $region): array
    {
        // This would typically use AWS SDK for proper signing
        // Simplified example - in production use AWS SDK
        return [
            'headers' => [
                'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$service}/{$region}/aws4_request",
                'X-Amz-Date' => gmdate('Ymd\THis\Z'),
            ]
        ];
    }

    /**
     * Create OAuth 1.0 authentication headers
     */
    public static function oauth1(
        string $consumerKey,
        string $consumerSecret,
        ?string $token = null,
        string $tokenSecret = null
    ): array {
        $oauth = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_version' => '1.0',
        ];

        if ($token) {
            $oauth['oauth_token'] = $token;
        }

        // Note: This is a simplified example
        // In production, use a proper OAuth 1.0 library for signature generation
        $signature = 'placeholder_signature';
        $oauth['oauth_signature'] = $signature;

        $authHeader = 'OAuth ';
        $pairs = [];
        foreach ($oauth as $key => $value) {
            $pairs[] = urlencode($key) . '="' . urlencode($value) . '"';
        }
        $authHeader .= implode(', ', $pairs);

        return [
            'headers' => [
                'Authorization' => $authHeader
            ]
        ];
    }

    /**
     * Create GitHub App authentication
     */
    public static function githubApp(string $appId, string $privateKey): array
    {
        // In production, use proper JWT library for GitHub App authentication
        $jwt = 'placeholder_jwt_token'; // Generate proper JWT with private key

        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Glueful-GitHub-App/1.0'
            ]
        ];
    }

    /**
     * Create Slack Bot authentication
     */
    public static function slackBot(string $botToken): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $botToken,
                'Content-Type' => 'application/json; charset=utf-8'
            ]
        ];
    }

    /**
     * Create Discord Bot authentication
     */
    public static function discordBot(string $botToken): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bot ' . $botToken,
                'Content-Type' => 'application/json'
            ]
        ];
    }

    /**
     * Create Stripe API authentication
     */
    public static function stripe(string $apiKey): array
    {
        return [
            'auth_basic' => [$apiKey, ''],
            'headers' => [
                'Stripe-Version' => '2020-08-27'
            ]
        ];
    }

    /**
     * Create PayPal API authentication
     */
    public static function paypal(string $clientId, string $clientSecret): array
    {
        return [
            'auth_basic' => [$clientId, $clientSecret],
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US'
            ]
        ];
    }

    /**
     * Create Twilio authentication
     */
    public static function twilio(string $accountSid, string $authToken): array
    {
        return [
            'auth_basic' => [$accountSid, $authToken]
        ];
    }

    /**
     * Create SendGrid authentication
     */
    public static function sendGrid(string $apiKey): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ]
        ];
    }

    /**
     * Create Mailgun authentication
     */
    public static function mailgun(string $apiKey): array
    {
        return [
            'auth_basic' => ['api', $apiKey]
        ];
    }

    /**
     * Merge multiple authentication methods
     */
    public static function merge(array ...$authMethods): array
    {
        $merged = [
            'headers' => [],
            'query' => [],
        ];

        foreach ($authMethods as $method) {
            if (isset($method['headers'])) {
                $merged['headers'] = array_merge($merged['headers'], $method['headers']);
            }
            if (isset($method['query'])) {
                $merged['query'] = array_merge($merged['query'], $method['query']);
            }
            if (isset($method['auth_basic'])) {
                $merged['auth_basic'] = $method['auth_basic'];
            }
        }

        // Remove empty arrays
        return array_filter($merged, function ($value) {
            return !empty($value);
        });
    }
}
