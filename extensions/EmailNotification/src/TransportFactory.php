<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;

/**
 * Transport Factory for Symfony Mailer
 *
 * Creates appropriate transport instances based on configuration
 * Supports SMTP, SendGrid, Mailgun, SES, and failover transports
 *
 * @package Glueful\Extensions\EmailNotification
 */
class TransportFactory
{
    /**
     * Create a transport based on configuration
     *
     * @param array $config Mail configuration
     * @return TransportInterface The configured transport
     * @throws \InvalidArgumentException If transport type is not supported
     */
    public static function create(array $config): TransportInterface
    {
        // Get the default mailer configuration
        $defaultMailer = $config['default'] ?? 'smtp';

        if (!isset($config['mailers'][$defaultMailer])) {
            throw new \InvalidArgumentException("Mailer configuration not found for: {$defaultMailer}");
        }

        $mailerConfig = $config['mailers'][$defaultMailer];

        return self::createTransportFromConfig($mailerConfig);
    }

    /**
     * Auto-detect and suggest available provider bridges
     *
     * @return array List of available provider bridges
     */
    public static function getAvailableProviders(): array
    {
        $providers = [
            'smtp' => ['available' => true, 'description' => 'Generic SMTP (built-in)'],
            'null' => ['available' => true, 'description' => 'Null transport (built-in)'],
            'log' => ['available' => true, 'description' => 'Log transport (built-in)'],
        ];

        // Check for provider bridges
        $bridges = [
            'brevo' => 'Symfony\\Component\\Mailer\\Bridge\\Brevo\\Transport\\BrevoTransportFactory',
            'sendgrid' => 'Symfony\\Component\\Mailer\\Bridge\\Sendgrid\\Transport\\SendgridTransportFactory',
            'mailgun' => 'Symfony\\Component\\Mailer\\Bridge\\Mailgun\\Transport\\MailgunTransportFactory',
            'ses' => 'Symfony\\Component\\Mailer\\Bridge\\Amazon\\Transport\\SesTransportFactory',
            'postmark' => 'Symfony\\Component\\Mailer\\Bridge\\Postmark\\Transport\\PostmarkTransportFactory',
        ];

        foreach ($bridges as $provider => $factoryClass) {
            $providers[$provider] = [
                'available' => class_exists($factoryClass),
                'description' => ucfirst($provider) . ' API bridge',
                'install_command' => "composer require symfony/{$provider}-mailer"
            ];
        }

        return $providers;
    }

    /**
     * Create a failover transport with multiple mailers
     *
     * @param array $config Mail configuration
     * @return TransportInterface The failover transport
     */
    public static function createFailover(array $config): TransportInterface
    {
        if (!isset($config['failover']['mailers']) || empty($config['failover']['mailers'])) {
            // If no failover is configured, return the default transport
            return self::create($config);
        }

        $transports = [];
        foreach ($config['failover']['mailers'] as $mailerName) {
            if (!isset($config['mailers'][$mailerName])) {
                continue;
            }

            $transports[] = self::createTransportFromConfig($config['mailers'][$mailerName]);
        }

        if (empty($transports)) {
            throw new \InvalidArgumentException("No valid transports configured for failover");
        }

        return new FailoverTransport($transports);
    }

    /**
     * Create a round-robin transport for load balancing
     *
     * @param array $config Mail configuration
     * @return TransportInterface The round-robin transport
     */
    public static function createRoundRobin(array $config): TransportInterface
    {
        if (!isset($config['round_robin']['mailers']) || empty($config['round_robin']['mailers'])) {
            // If no round-robin is configured, return the default transport
            return self::create($config);
        }

        $transports = [];
        foreach ($config['round_robin']['mailers'] as $mailerName) {
            if (!isset($config['mailers'][$mailerName])) {
                continue;
            }

            $transports[] = self::createTransportFromConfig($config['mailers'][$mailerName]);
        }

        if (empty($transports)) {
            throw new \InvalidArgumentException("No valid transports configured for round-robin");
        }

        return new RoundRobinTransport($transports);
    }

    /**
     * Create a transport from a specific configuration
     *
     * @param array $config Transport configuration
     * @return TransportInterface The configured transport
     * @throws \InvalidArgumentException If transport type is not supported
     */
    private static function createTransportFromConfig(array $config): TransportInterface
    {
        $transportType = $config['transport'] ?? 'smtp';

        // Check if user provided a custom DSN override
        if (!empty($config['dsn'])) {
            try {
                return Transport::fromDsn($config['dsn']);
            } catch (\Exception $e) {
                // Fall through to individual config creation
            }
        }

        // Handle provider bridges and transports
        switch ($transportType) {
            // Provider API bridges
            case 'brevo+api':
                return self::createBrevoApiTransport($config);

            case 'brevo+smtp':
                return self::createBrevoSmtpTransport($config);

            case 'sendgrid+api':
                return self::createSendGridApiTransport($config);

            case 'mailgun+api':
                return self::createMailgunApiTransport($config);

            case 'ses+api':
                return self::createSesApiTransport($config);

            case 'postmark+api':
                return self::createPostmarkApiTransport($config);

            // Generic transports
            case 'smtp':
                return self::createSmtpTransport($config);

            case 'log':
                return Transport::fromDsn('log://default');

            case 'null':
                return Transport::fromDsn('null://null');

            case 'array':
                return Transport::fromDsn('null://null');

            default:
                // Check if this might be a provider bridge transport (contains '+')
                if (strpos($transportType, '+') !== false) {
                    // Try to create transport directly using Symfony's Transport::fromDsn()
                    // This allows any Symfony provider bridge to work without explicit support
                    try {
                        [$provider, $protocol] = explode('+', $transportType, 2);

                        // Build DSN based on common patterns
                        if ($protocol === 'api' && !empty($config['key'])) {
                            $dsn = "{$transportType}://{$config['key']}@default";
                        } elseif ($protocol === 'smtp' && !empty($config['username']) && !empty($config['password'])) {
                            $dsn = "{$transportType}://{$config['username']}:" .
                                   urlencode($config['password']) . "@default";
                        } else {
                            throw new \InvalidArgumentException(
                                "Cannot auto-configure transport '{$transportType}'. " .
                                "Please provide a 'dsn' in configuration or use a supported transport."
                            );
                        }

                        return Transport::fromDsn($dsn);
                    } catch (\Exception $e) {
                        throw new \InvalidArgumentException(
                            "Failed to create transport '{$transportType}': " . $e->getMessage() .
                            ". Please provide a 'dsn' in configuration."
                        );
                    }
                }

                throw new \InvalidArgumentException(
                    "Unsupported transport type: {$transportType}. " .
                    "Supported types: brevo+api, brevo+smtp, sendgrid+api, mailgun+api, ses+api, postmark+api, " .
                    "smtp, log, null, array. Or provide a 'dsn' for custom transports."
                );
        }
    }

    /**
     * Create SMTP transport
     *
     * @param array $config SMTP configuration
     * @return TransportInterface SMTP transport
     */
    private static function createSmtpTransport(array $config): TransportInterface
    {
        // Build the DSN
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            urlencode($config['username'] ?? ''),
            urlencode($config['password'] ?? ''),
            $config['host'] ?? 'localhost',
            $config['port'] ?? 587
        );

        // Add query parameters
        $params = [];

        if (isset($config['encryption'])) {
            $params['encryption'] = $config['encryption'];
        }

        if (isset($config['auth_mode'])) {
            $params['auth_mode'] = $config['auth_mode'];
        }

        if (isset($config['verify_peer']) && !$config['verify_peer']) {
            $params['verify_peer'] = '0';
        }

        if (isset($config['timeout'])) {
            $params['timeout'] = (string)$config['timeout'];
        }

        if (!empty($params)) {
            $dsn .= '?' . http_build_query($params);
        }

        return Transport::fromDsn($dsn);
    }

    /**
     * Create SendGrid transport
     *
     * @param array $config SendGrid configuration
     * @return TransportInterface SendGrid transport
     */
    private static function createSendGridTransport(array $config): TransportInterface
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException("SendGrid API key is required");
        }

        return Transport::fromDsn('sendgrid+api://' . $config['key'] . '@default');
    }

    /**
     * Create Mailgun transport
     *
     * @param array $config Mailgun configuration
     * @return TransportInterface Mailgun transport
     */
    private static function createMailgunTransport(array $config): TransportInterface
    {
        if (empty($config['domain']) || empty($config['secret'])) {
            throw new \InvalidArgumentException("Mailgun domain and secret are required");
        }

        $endpoint = $config['endpoint'] ?? 'api.mailgun.net';
        $region = $config['region'] ?? 'us';

        // Use the appropriate endpoint based on region
        if ($region === 'eu') {
            $endpoint = 'api.eu.mailgun.net';
        }

        $dsn = sprintf(
            'mailgun+https://api:%s@%s?domain=%s',
            $config['secret'],
            $endpoint,
            $config['domain']
        );

        return Transport::fromDsn($dsn);
    }

    /**
     * Create Amazon SES transport
     *
     * @param array $config SES configuration
     * @return TransportInterface SES transport
     */
    private static function createSesTransport(array $config): TransportInterface
    {
        if (empty($config['key']) || empty($config['secret'])) {
            throw new \InvalidArgumentException("AWS access key and secret are required");
        }

        $region = $config['region'] ?? 'us-east-1';

        $dsn = sprintf(
            'ses+https://%s:%s@default?region=%s',
            urlencode($config['key']),
            urlencode($config['secret']),
            $region
        );

        return Transport::fromDsn($dsn);
    }

    /**
     * Create Postmark transport
     *
     * @param array $config Postmark configuration
     * @return TransportInterface Postmark transport
     */
    private static function createPostmarkTransport(array $config): TransportInterface
    {
        if (empty($config['token'])) {
            throw new \InvalidArgumentException("Postmark server token is required");
        }

        return Transport::fromDsn('postmark+api://' . $config['token'] . '@default');
    }

    /**
     * Create Mandrill transport
     *
     * @param array $config Mandrill configuration
     * @return TransportInterface Mandrill transport
     */
    private static function createMandrillTransport(array $config): TransportInterface
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException("Mandrill API key is required");
        }

        return Transport::fromDsn('mandrill+api://' . $config['key'] . '@default');
    }

    /**
     * Create Brevo API transport
     *
     * @param array $config Brevo configuration
     * @return TransportInterface Brevo transport
     */
    private static function createBrevoApiTransport(array $config): TransportInterface
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException("Brevo API key is required");
        }

        return Transport::fromDsn('brevo+api://' . $config['key'] . '@default');
    }

    /**
     * Create Brevo SMTP transport
     *
     * @param array $config Brevo SMTP configuration
     * @return TransportInterface Brevo SMTP transport
     */
    private static function createBrevoSmtpTransport(array $config): TransportInterface
    {
        if (empty($config['username']) || empty($config['password'])) {
            throw new \InvalidArgumentException("Brevo SMTP username and password are required");
        }

        // Let Symfony's Brevo bridge handle the username encoding internally
        // Don't URL-encode the username - the bridge should handle @ symbols correctly
        $dsn = 'brevo+smtp://' . $config['username'] . ':' . urlencode($config['password']) . '@default';
        return Transport::fromDsn($dsn);
    }

    /**
     * Create SendGrid API transport
     *
     * @param array $config SendGrid configuration
     * @return TransportInterface SendGrid transport
     */
    private static function createSendGridApiTransport(array $config): TransportInterface
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException("SendGrid API key is required");
        }

        return Transport::fromDsn('sendgrid+api://' . $config['key'] . '@default');
    }

    /**
     * Create Mailgun API transport
     *
     * @param array $config Mailgun configuration
     * @return TransportInterface Mailgun transport
     */
    private static function createMailgunApiTransport(array $config): TransportInterface
    {
        if (empty($config['domain']) || empty($config['key'])) {
            throw new \InvalidArgumentException("Mailgun domain and secret are required");
        }

        $endpoint = $config['endpoint'] ?? 'api.mailgun.net';
        $region = $config['region'] ?? 'us';

        if ($region === 'eu') {
            $endpoint = 'api.eu.mailgun.net';
        }

        return Transport::fromDsn('mailgun+api://' . $config['key'] . '@' . $endpoint . '?domain=' . $config['domain']);
    }

    /**
     * Create Amazon SES API transport
     *
     * @param array $config SES configuration
     * @return TransportInterface SES transport
     */
    private static function createSesApiTransport(array $config): TransportInterface
    {
        if (empty($config['key']) || empty($config['secret'])) {
            throw new \InvalidArgumentException("AWS access key and secret are required");
        }

        $region = $config['region'] ?? 'us-east-1';

        $dsn = 'ses+api://' . urlencode($config['key']) . ':' . urlencode($config['secret']) .
               '@default?region=' . $region;

        return Transport::fromDsn($dsn);
    }

    /**
     * Create Postmark API transport
     *
     * @param array $config Postmark configuration
     * @return TransportInterface Postmark transport
     */
    private static function createPostmarkApiTransport(array $config): TransportInterface
    {
        if (empty($config['token'])) {
            throw new \InvalidArgumentException("Postmark server token is required");
        }

        return Transport::fromDsn('postmark+api://' . $config['token'] . '@default');
    }
}
