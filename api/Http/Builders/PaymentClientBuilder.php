<?php

declare(strict_types=1);

namespace Glueful\Http\Builders;

use Glueful\Http\Client;
use Glueful\Http\Authentication\AuthenticationMethods;

/**
 * Payment Client Builder
 *
 * Specialized builder for payment gateway integrations with security-focused
 * configurations and provider-specific settings.
 */
class PaymentClientBuilder
{
    private array $options = [];

    public function __construct(private Client $baseClient)
    {
        // Set payment-specific defaults (security-focused)
        $this->options = [
            'timeout' => 30,
            'verify_peer' => true,
            'verify_host' => true,
            'http_version' => '2.0',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Payment/1.0',
            ]
        ];
    }

    /**
     * Configure for Stripe
     */
    public function forStripe(string $apiKey): self
    {
        $auth = AuthenticationMethods::stripe($apiKey);

        return $this->baseUri('https://api.stripe.com')
            ->auth($auth)
            ->header('Stripe-Version', '2020-08-27');
    }

    /**
     * Configure for PayPal
     */
    public function forPayPal(string $clientId, string $clientSecret, bool $sandbox = false): self
    {
        $baseUri = $sandbox
            ? 'https://api.sandbox.paypal.com'
            : 'https://api.paypal.com';

        $auth = AuthenticationMethods::paypal($clientId, $clientSecret);

        return $this->baseUri($baseUri)
            ->auth($auth)
            ->header('Accept-Language', 'en_US');
    }

    /**
     * Configure for Square
     */
    public function forSquare(string $accessToken, bool $sandbox = false): self
    {
        $baseUri = $sandbox
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';

        return $this->baseUri($baseUri)
            ->bearerAuth($accessToken)
            ->header('Square-Version', '2023-10-18');
    }

    /**
     * Configure for Authorize.Net
     */
    public function forAuthorizeNet(string $apiLoginId, string $transactionKey, bool $sandbox = false): self
    {
        $baseUri = $sandbox
            ? 'https://apitest.authorize.net'
            : 'https://api.authorize.net';

        return $this->baseUri($baseUri)
            ->header('Accept', 'application/json')
            ->header('Content-Type', 'application/json');
    }

    /**
     * Configure for Braintree
     */
    public function forBraintree(string $merchantId, string $publicKey, string $privateKey, bool $sandbox = false): self
    {
        $baseUri = $sandbox
            ? 'https://api.sandbox.braintreegateway.com'
            : 'https://api.braintreegateway.com';

        return $this->baseUri($baseUri)
            ->basicAuth($publicKey, $privateKey)
            ->header('Accept', 'application/xml')
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Configure for Adyen
     */
    public function forAdyen(string $apiKey, bool $test = false): self
    {
        $baseUri = $test
            ? 'https://checkout-test.adyen.com'
            : 'https://checkout-live.adyen.com';

        return $this->baseUri($baseUri)
            ->apiKey($apiKey, 'X-API-Key')
            ->header('Accept', 'application/json');
    }

    /**
     * Configure for Razorpay
     */
    public function forRazorpay(string $keyId, string $keySecret): self
    {
        return $this->baseUri('https://api.razorpay.com')
            ->basicAuth($keyId, $keySecret)
            ->header('Accept', 'application/json');
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
     * Set timeout (typically higher for payments)
     */
    public function timeout(int $seconds): self
    {
        $this->options['timeout'] = $seconds;
        return $this;
    }

    /**
     * Add authentication
     */
    public function auth(array $authConfig): self
    {
        if (isset($authConfig['headers'])) {
            $this->options['headers'] = array_merge($this->options['headers'], $authConfig['headers']);
        }
        if (isset($authConfig['auth_basic'])) {
            $this->options['auth_basic'] = $authConfig['auth_basic'];
        }
        return $this;
    }

    /**
     * Add Bearer token authentication
     */
    public function bearerAuth(string $token): self
    {
        $auth = AuthenticationMethods::bearerToken($token);
        return $this->auth($auth);
    }

    /**
     * Add Basic authentication
     */
    public function basicAuth(string $username, string $password): self
    {
        $auth = AuthenticationMethods::basicAuth($username, $password);
        return $this->auth($auth);
    }

    /**
     * Add API key authentication
     */
    public function apiKey(string $key, string $header = 'X-API-Key'): self
    {
        $auth = AuthenticationMethods::apiKey($key, $header);
        return $this->auth($auth);
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
     * Enable/disable SSL verification (should always be true for payments)
     */
    public function verifySsl(bool $verify = true): self
    {
        $this->options['verify_peer'] = $verify;
        $this->options['verify_host'] = $verify;
        return $this;
    }

    /**
     * Set idempotency key for safe retries
     */
    public function idempotencyKey(string $key): self
    {
        return $this->header('Idempotency-Key', $key);
    }

    /**
     * Build the payment client
     */
    public function build(): Client
    {
        return $this->baseClient->createScopedClient($this->options);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->options;
    }
}
