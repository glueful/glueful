<?php

declare(strict_types=1);

namespace Glueful\Http\Builders;

use Glueful\Http\Client;
use Glueful\Http\Authentication\AuthenticationMethods;

/**
 * OAuth Client Builder
 *
 * Specialized builder for OAuth 2.0 flows with provider-specific configurations
 * and common OAuth patterns like authorization code, client credentials, etc.
 */
class OAuthClientBuilder
{
    private array $options = [];

    public function __construct(private Client $baseClient)
    {
        // Set OAuth-specific defaults
        $this->options = [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ];
    }

    /**
     * Configure for Google OAuth
     */
    public function forGoogle(): self
    {
        return $this->baseUri('https://oauth2.googleapis.com')
            ->userAgent('Glueful-Google-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for Facebook OAuth
     */
    public function forFacebook(): self
    {
        return $this->baseUri('https://graph.facebook.com')
            ->userAgent('Glueful-Facebook-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for GitHub OAuth
     */
    public function forGitHub(): self
    {
        return $this->baseUri('https://github.com')
            ->userAgent('Glueful-GitHub-OAuth/1.0')
            ->header('Accept', 'application/vnd.github.v3+json');
    }

    /**
     * Configure for Discord OAuth
     */
    public function forDiscord(): self
    {
        return $this->baseUri('https://discord.com/api')
            ->userAgent('Glueful-Discord-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for Apple OAuth
     */
    public function forApple(): self
    {
        return $this->baseUri('https://appleid.apple.com')
            ->userAgent('Glueful-Apple-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for Microsoft OAuth
     */
    public function forMicrosoft(): self
    {
        return $this->baseUri('https://login.microsoftonline.com')
            ->userAgent('Glueful-Microsoft-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for LinkedIn OAuth
     */
    public function forLinkedIn(): self
    {
        return $this->baseUri('https://www.linkedin.com')
            ->userAgent('Glueful-LinkedIn-OAuth/1.0')
            ->header('Accept', 'application/json');
    }

    /**
     * Set client credentials
     */
    public function clientCredentials(string $clientId, string $clientSecret): self
    {
        $auth = AuthenticationMethods::basicAuth($clientId, $clientSecret);
        if (isset($auth['auth_basic'])) {
            $this->options['auth_basic'] = $auth['auth_basic'];
        }
        return $this;
    }

    /**
     * Set client ID for authorization code flow
     */
    public function clientId(string $clientId): self
    {
        $this->options['client_id'] = $clientId;
        return $this;
    }

    /**
     * Set redirect URI
     */
    public function redirectUri(string $redirectUri): self
    {
        $this->options['redirect_uri'] = $redirectUri;
        return $this;
    }

    /**
     * Set scopes
     */
    public function scopes(array $scopes): self
    {
        $this->options['scopes'] = $scopes;
        return $this;
    }

    /**
     * Set state parameter for CSRF protection
     */
    public function state(string $state): self
    {
        $this->options['state'] = $state;
        return $this;
    }

    /**
     * Set base URI
     */
    public function baseUri(string $uri): self
    {
        $this->options['base_uri'] = $uri;
        return $this;
    }

    /**
     * Set timeout
     */
    public function timeout(int $seconds): self
    {
        $this->options['timeout'] = $seconds;
        return $this;
    }

    /**
     * Add header
     */
    public function header(string $name, string $value): self
    {
        $this->options['headers'][$name] = $value;
        return $this;
    }

    /**
     * Set User-Agent
     */
    public function userAgent(string $userAgent): self
    {
        return $this->header('User-Agent', $userAgent);
    }

    /**
     * Generate authorization URL
     */
    public function getAuthorizationUrl(string $authEndpoint): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->options['client_id'] ?? '',
            'redirect_uri' => $this->options['redirect_uri'] ?? '',
            'scope' => isset($this->options['scopes']) ? implode(' ', $this->options['scopes']) : '',
            'state' => $this->options['state'] ?? '',
        ];

        $params = array_filter($params); // Remove empty values
        return $authEndpoint . '?' . http_build_query($params);
    }

    /**
     * Build the OAuth client
     */
    public function build(): Client
    {
        // Remove OAuth-specific options that aren't HTTP client options
        $httpOptions = $this->options;
        unset($httpOptions['client_id'], $httpOptions['redirect_uri'], $httpOptions['scopes'], $httpOptions['state']);

        return $this->baseClient->createScopedClient($httpOptions);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->options;
    }
}
