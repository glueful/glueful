<?php

declare(strict_types=1);

namespace Glueful\Http\Builders;

use Glueful\Http\Client;
use Glueful\Http\Authentication\AuthenticationMethods;

/**
 * Notification Client Builder
 *
 * Specialized builder for notification service integrations including
 * email, SMS, push notifications, and chat platforms.
 */
class NotificationClientBuilder
{
    private array $options = [];

    public function __construct(private Client $baseClient)
    {
        // Set notification-specific defaults
        $this->options = [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
        ];
    }

    /**
     * Configure for SendGrid email
     */
    public function forSendGrid(string $apiKey): self
    {
        $auth = AuthenticationMethods::sendGrid($apiKey);

        return $this->baseUri('https://api.sendgrid.com')
            ->auth($auth)
            ->userAgent('Glueful-SendGrid/1.0');
    }

    /**
     * Configure for Mailgun email
     */
    public function forMailgun(string $apiKey, string $domain): self
    {
        $auth = AuthenticationMethods::mailgun($apiKey);

        return $this->baseUri("https://api.mailgun.net/v3/{$domain}")
            ->auth($auth)
            ->userAgent('Glueful-Mailgun/1.0');
    }

    /**
     * Configure for Twilio SMS
     */
    public function forTwilio(string $accountSid, string $authToken): self
    {
        $auth = AuthenticationMethods::twilio($accountSid, $authToken);

        return $this->baseUri("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}")
            ->auth($auth)
            ->userAgent('Glueful-Twilio/1.0');
    }

    /**
     * Configure for Slack notifications
     */
    public function forSlack(string $botToken): self
    {
        $auth = AuthenticationMethods::slackBot($botToken);

        return $this->baseUri('https://slack.com/api')
            ->auth($auth)
            ->userAgent('Glueful-Slack/1.0');
    }

    /**
     * Configure for Discord notifications
     */
    public function forDiscord(string $botToken): self
    {
        $auth = AuthenticationMethods::discordBot($botToken);

        return $this->baseUri('https://discord.com/api/v10')
            ->auth($auth)
            ->userAgent('Glueful-Discord/1.0');
    }

    /**
     * Configure for Firebase push notifications
     */
    public function forFirebase(string $serverKey): self
    {
        return $this->baseUri('https://fcm.googleapis.com')
            ->bearerAuth($serverKey)
            ->userAgent('Glueful-Firebase/1.0');
    }

    /**
     * Configure for AWS SNS
     */
    public function forAWSSNS(string $accessKey, string $secretKey, string $region): self
    {
        $auth = AuthenticationMethods::awsSignature($accessKey, $secretKey, 'sns', $region);

        return $this->baseUri("https://sns.{$region}.amazonaws.com")
            ->auth($auth)
            ->userAgent('Glueful-SNS/1.0');
    }

    /**
     * Configure for Pusher push notifications
     */
    public function forPusher(string $appId, string $key, string $secret, string $cluster): self
    {
        return $this->baseUri("https://api-{$cluster}.pusherapp.com/apps/{$appId}")
            ->userAgent('Glueful-Pusher/1.0');
    }

    /**
     * Configure for OneSignal push notifications
     */
    public function forOneSignal(string $apiKey, string $appId): self
    {
        return $this->baseUri('https://onesignal.com/api/v1')
            ->apiKey($apiKey, 'Authorization')
            ->header('Authorization', 'Basic ' . $apiKey)
            ->userAgent('Glueful-OneSignal/1.0');
    }

    /**
     * Configure for Microsoft Teams
     */
    public function forMicrosoftTeams(string $webhookUrl): self
    {
        return $this->baseUri($webhookUrl)
            ->userAgent('Glueful-Teams/1.0')
            ->timeout(10);
    }

    /**
     * Configure for Telegram Bot
     */
    public function forTelegram(string $botToken): self
    {
        return $this->baseUri("https://api.telegram.org/bot{$botToken}")
            ->userAgent('Glueful-Telegram/1.0');
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
     * Set User-Agent
     */
    public function userAgent(string $userAgent): self
    {
        return $this->header('User-Agent', $userAgent);
    }

    /**
     * Build the notification client
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
