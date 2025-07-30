<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Email Notification Channel
 *
 * Implementation of the NotificationChannel interface for sending
 * notifications via email using Symfony Mailer.
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EmailChannel implements NotificationChannel
{
    /**
     * @var array Email configuration
     */
    private array $config;

    /**
     * @var EmailFormatter Formats email content
     */
    private EmailFormatter $formatter;

    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;

    /**
     * EmailChannel constructor
     *
     * @param array $config Email configuration
     * @param EmailFormatter|null $formatter Custom formatter (optional)
     */
    public function __construct(array $config = [], ?EmailFormatter $formatter = null)
    {
        // Load default config if not provided
        if (empty($config)) {
            $this->config = require __DIR__ . '/config.php';
        } else {
            $this->config = $config;
        }

        $this->formatter = $formatter ?? new EmailFormatter();

        // Initialize logger with email channel
        $this->logger = new LogManager('email');
    }

    /**
     * Get the channel name
     *
     * @return string The name of the notification channel
     */
    public function getChannelName(): string
    {
        return 'email';
    }

    /**
     * Send the notification to the specified notifiable entity
     *
     * @param Notifiable $notifiable The entity receiving the notification
     * @param array $data Notification data including content and metadata
     * @return bool Whether the notification was sent successfully
     */
    public function send(Notifiable $notifiable, array $data): bool
    {
        // Get the recipient email address
        $recipientEmail = $notifiable->routeNotificationFor('email');

        if (empty($recipientEmail)) {
            return false;
        }

        // Format the notification data for email
        $emailData = $this->format($data, $notifiable);

        try {
            $mailer = $this->createMailer();
            $email = $this->createEmail($emailData, $recipientEmail);

            // Send the email
            $mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            // Log the error using LogManager
            $this->logger->error('Email notification failed: ' . $e->getMessage(), [
                'notifiable_id' => $notifiable->getNotifiableId(),
                'notification_data' => $data,
                'exception' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);

            return false;
        }
    }

    /**
     * Format the notification data for this channel
     *
     * @param array $data The raw notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @return array The formatted notification data
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        // Check if data is already formatted
        if (isset($data['html_content']) && isset($data['text_content'])) {
            // Email is already formatted, return as-is
            $this->logger->debug('Email already formatted, skipping second formatting');
            return $data;
        }

        // Use the formatter to prepare email content
        return $this->formatter->format($data, $notifiable);
    }

    /**
     * Determine if the channel is available for sending notifications
     *
     * @return bool Whether the channel is available
     */
    public function isAvailable(): bool
    {
        // Check if required PHP extensions are loaded
        if (!extension_loaded('openssl')) {
            return false;
        }

        // Check if required configuration is set
        $hasHost = false;
        $hasFromAddress = false;

        // Check for new configuration structure
        if (isset($this->config['mailers']) && isset($this->config['default'])) {
            $defaultMailer = $this->config['default'];
            if (isset($this->config['mailers'][$defaultMailer])) {
                $mailerConfig = $this->config['mailers'][$defaultMailer];
                $hasHost = !empty($mailerConfig['host']) ||
                          !empty($mailerConfig['key']) ||
                          !empty($mailerConfig['token']);
            }
        } else {
            // Legacy configuration structure
            $hasHost = !empty($this->config['host']);
        }

        // Check from address (same in both structures)
        $hasFromAddress = !empty($this->config['from']['address']);

        return $hasHost && $hasFromAddress;
    }

    /**
     * Get channel-specific configuration
     *
     * @return array The channel configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set channel-specific configuration
     *
     * @param array $config The new configuration
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Create and configure a Symfony Mailer instance
     *
     * @return Mailer Configured mailer instance
     * @throws \Exception If mailer cannot be configured
     */
    private function createMailer(): Mailer
    {
        // Get the default mailer configuration
        $defaultMailer = $this->config['default'] ?? 'smtp';

        // We expect the new multi-mailer configuration
        if (!isset($this->config['mailers'][$defaultMailer])) {
            throw new \Exception("Mailer configuration not found for: {$defaultMailer}");
        }

        $mailerConfig = $this->config['mailers'][$defaultMailer];

        // Check if this is a provider bridge (doesn't need host validation)
        $transport = $mailerConfig['transport'] ?? 'smtp';
        $isProviderBridge = strpos($transport, '+') !== false; // e.g., brevo+api, sendgrid+api

        // Validate configuration based on transport type
        if (!$isProviderBridge && empty($mailerConfig['host'])) {
            // Fallback to null transport for development environments
            $transport = Transport::fromDsn('null://null');
            return new Mailer($transport);
        }

        // For provider bridges, validate they have required credentials
        if ($isProviderBridge) {
            $hasCredentials = !empty($mailerConfig['key']) ||
                             (!empty($mailerConfig['username']) && !empty($mailerConfig['password']));
            if (!$hasCredentials) {
                // Fallback to null transport if credentials missing
                $transport = Transport::fromDsn('null://null');
                return new Mailer($transport);
            }
        }

        try {
            // Check if failover is properly configured (non-empty mailers array)
            $failoverMailers = $this->config['failover']['mailers'] ?? [];
            $hasValidFailover = !empty($failoverMailers) && !empty(array_filter($failoverMailers));

            if ($hasValidFailover) {
                // Use failover transport if configured
                $transport = \Glueful\Extensions\EmailNotification\TransportFactory::createFailover($this->config);
            } else {
                // Use the default transport with enhanced provider bridge support
                $transport = \Glueful\Extensions\EmailNotification\TransportFactory::create($this->config);
            }

            return new Mailer($transport);
        } catch (\Exception $e) {
            // Log the error for debugging
            $this->logger->error('Failed to create email transport: ' . $e->getMessage(), [
                'exception' => $e,
                'config_keys' => array_keys($this->config)
            ]);

            // Fallback to null transport for development
            $transport = Transport::fromDsn('null://null');
            return new Mailer($transport);
        }
    }

    /**
     * Create a Symfony Email object from email data
     *
     * @param array $data The email data
     * @param string $recipientEmail The primary recipient email
     * @return Email Configured email object
     */
    private function createEmail(array $data, string $recipientEmail): Email
    {
        // Check if we're using EnhancedEmailFormatter
        if (
            $this->formatter instanceof \Glueful\Extensions\EmailNotification\EnhancedEmailFormatter
            && isset($data['template'])
        ) {
            // Use enhanced formatter to build email with advanced features
            $email = $this->formatter->buildEmailFromTemplate($data['template'], $data);

            // Override recipient
            $email->to($recipientEmail);

            // Set from address if not already set
            if (empty($email->getFrom())) {
                $fromAddress = new Address(
                    $this->config['from']['address'],
                    $this->config['from']['name'] ?? ''
                );
                $email->from($fromAddress);
            }

            return $email;
        }

        // Standard email creation
        $email = new Email();

        // Set from address
        $fromAddress = new Address(
            $this->config['from']['address'],
            $this->config['from']['name'] ?? ''
        );
        $email->from($fromAddress);

        // Set primary recipient
        $email->to($recipientEmail);

        // Set CC if provided
        if (!empty($data['cc'])) {
            foreach ((array)$data['cc'] as $cc) {
                $email->addCc($cc);
            }
        }

        // Set BCC if provided
        if (!empty($data['bcc'])) {
            foreach ((array)$data['bcc'] as $bcc) {
                $email->addBcc($bcc);
            }
        }

        // Set reply-to if configured
        if (!empty($this->config['reply_to']['address'])) {
            $replyToAddress = new Address(
                $this->config['reply_to']['address'],
                $this->config['reply_to']['name'] ?? ''
            );
            $email->replyTo($replyToAddress);
        }

        // Set subject
        $email->subject($data['subject'] ?? '');

        // Enhanced features with Symfony Mailer

        // Set priority if specified
        if (isset($data['priority'])) {
            $priority = match ($data['priority']) {
                'highest' => Email::PRIORITY_HIGHEST,
                'high' => Email::PRIORITY_HIGH,
                'normal' => Email::PRIORITY_NORMAL,
                'low' => Email::PRIORITY_LOW,
                'lowest' => Email::PRIORITY_LOWEST,
                default => Email::PRIORITY_NORMAL,
            };
            $email->priority($priority);
        }

        // Embed images if specified
        if (isset($data['embedImages']) && is_array($data['embedImages'])) {
            foreach ($data['embedImages'] as $cid => $path) {
                if (file_exists($path)) {
                    $email->embedFromPath($path, $cid);
                }
            }
        }

        // Add custom headers if specified
        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $name => $value) {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }

        // Set return path if specified
        if (isset($data['returnPath'])) {
            $email->returnPath($data['returnPath']);
        }

        // Set content
        if (!empty($data['html_content'])) {
            $email->html($data['html_content']);

            // Set plain text alternative if available
            if (!empty($data['text_content'])) {
                $email->text($data['text_content']);
            }
        } else {
            // Text-only email
            $email->text($data['text_content'] ?? '');
        }

        // Add attachments if any
        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if (is_array($attachment) && isset($attachment['path'])) {
                    $email->attachFromPath(
                        $attachment['path'],
                        $attachment['name'] ?? null,
                        $attachment['contentType'] ?? null
                    );
                } elseif (is_string($attachment)) {
                    $email->attachFromPath($attachment);
                }
            }
        }

        return $email;
    }

    /**
     * Set the formatter for email content
     *
     * @param EmailFormatter $formatter The formatter instance
     * @return self
     */
    public function setFormatter(EmailFormatter $formatter): self
    {
        $this->formatter = $formatter;
        return $this;
    }

    /**
     * Get the formatter instance
     *
     * @return EmailFormatter The formatter instance
     */
    public function getFormatter(): EmailFormatter
    {
        return $this->formatter;
    }

    /**
     * Get the current size of the email queue
     *
     * Returns the number of emails currently pending in the framework's queue system.
     * Uses the built-in QueueManager to get accurate queue statistics.
     *
     * @return int|null Number of emails in queue, or null if queue system not available
     */
    public function getQueueSize(): ?int
    {
        try {
            // Check if queue feature is enabled in framework config
            $queueConfig = config('queue');
            if (empty($queueConfig) || !($queueConfig['enabled'] ?? true)) {
                return 0;
            }

            // Use the framework's QueueManager to get queue size
            $container = app();
            if (!$container->has('Glueful\\Queue\\QueueManager')) {
                return 0;
            }

            $queueManager = $container->get('Glueful\\Queue\\QueueManager');
            return $queueManager->size('emails'); // Get size of emails queue
        } catch (\Exception $e) {
            $this->logger->error('Failed to get email queue size: ' . $e->getMessage());
            return null;
        }
    }
}
