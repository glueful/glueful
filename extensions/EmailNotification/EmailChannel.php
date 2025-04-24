<?php
declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Email Notification Channel
 * 
 * Implementation of the NotificationChannel interface for sending
 * notifications via email using PHPMailer.
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
            $mail = $this->createMailer();
            
            // Set recipients
            $mail->addAddress($recipientEmail);
            
            // Set CC if provided in the data
            if (!empty($emailData['cc'])) {
                foreach ((array)$emailData['cc'] as $cc) {
                    $mail->addCC($cc);
                }
            }
            
            // Set BCC if provided in the data
            if (!empty($emailData['bcc'])) {
                foreach ((array)$emailData['bcc'] as $bcc) {
                    $mail->addBCC($bcc);
                }
            }
            
            // Set email content
            $mail->Subject = $emailData['subject'];
            
            // Check if HTML content is provided
            if (!empty($emailData['html_content'])) {
                $mail->isHTML(true);
                $mail->Body = $emailData['html_content'];
                
                // Set plain text alternative if available
                if (!empty($emailData['text_content'])) {
                    $mail->AltBody = $emailData['text_content'];
                }
            } else {
                // Text-only email
                $mail->Body = $emailData['text_content'];
            }
            
            // Add attachments if any
            if (!empty($emailData['attachments'])) {
                foreach ($emailData['attachments'] as $attachment) {
                    if (is_array($attachment) && isset($attachment['path'])) {
                        $filename = $attachment['name'] ?? '';
                        $mail->addAttachment($attachment['path'], $filename);
                    } elseif (is_string($attachment)) {
                        $mail->addAttachment($attachment);
                    }
                }
            }
            
            // Send the email
            return $mail->send();
            
        } catch (Exception $e) {
            // Log the error using LogManager instead of undefined log_error function
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
        return !empty($this->config['host']) && 
               !empty($this->config['from']['address']);
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
     * Create and configure a PHPMailer instance
     * 
     * @return PHPMailer Configured mailer instance
     * @throws Exception If mailer cannot be configured
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->SMTPAuth = $this->config['smtp_auth'] ?? true;
        
        if ($mail->SMTPAuth) {
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
        }
        
        // Set encryption type
        if (!empty($this->config['encryption'])) {
            $mail->SMTPSecure = $this->config['encryption'];
        }
        
        // Set port
        if (!empty($this->config['port'])) {
            $mail->Port = $this->config['port'];
        }
        
        // Set sender
        $mail->setFrom(
            $this->config['from']['address'],
            $this->config['from']['name'] ?? ''
        );
        
        // Set reply-to
        if (!empty($this->config['reply_to']['address'])) {
            $mail->addReplyTo(
                $this->config['reply_to']['address'],
                $this->config['reply_to']['name'] ?? ''
            );
        }
        
        return $mail;
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
}