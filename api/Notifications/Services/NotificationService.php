<?php
declare(strict_types=1);

namespace Glueful\Notifications\Services;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Templates\TemplateManager;
use InvalidArgumentException;


/**
 * Notification Service
 * 
 * Main service for notification operations, providing a simplified interface
 * for creating, sending, and managing notifications.
 * 
 * @package Glueful\Notifications\Services
 */
class NotificationService
{
    /**
     * @var NotificationDispatcher The notification dispatcher
     */
    private NotificationDispatcher $dispatcher;
    
    /**
     * @var TemplateManager|null The template manager
     */
    private ?TemplateManager $templateManager;
    
    /**
     * @var string Generator for notification IDs
     */
    private string $idGenerator;
    
    /**
     * @var array Configuration options
     */
    private array $config;
    
    /**
     * NotificationService constructor
     * 
     * @param NotificationDispatcher $dispatcher Notification dispatcher
     * @param TemplateManager|null $templateManager Template manager
     * @param array $config Configuration options
     */
    public function __construct(
        NotificationDispatcher $dispatcher,
        ?TemplateManager $templateManager = null,
        array $config = []
    ) {
        $this->dispatcher = $dispatcher;
        $this->templateManager = $templateManager;
        $this->config = $config;
        
        // Default ID generator creates UUIDs
        $this->idGenerator = $config['id_generator'] ?? function() {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff)
            );
        };
    }
    
    /**
     * Create and send a notification
     * 
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $subject Subject of the notification
     * @param array $data Additional notification data
     * @param array $options Additional options (channels, priority, schedule)
     * @return array Result of the send operation
     */
    public function send(
        string $type,
        Notifiable $notifiable,
        string $subject,
        array $data = [],
        array $options = []
    ): array {
        // Create the notification
        $notification = $this->create($type, $notifiable, $subject, $data, $options);
        
        // Send it immediately unless scheduled for later
        if (!isset($options['schedule']) || $options['schedule'] === null) {
            return $this->dispatcher->send(
                $notification,
                $notifiable,
                $options['channels'] ?? null
            );
        }
        
        return [
            'status' => 'scheduled',
            'notification_id' => $notification->getId(),
            'scheduled_at' => $notification->getScheduledAt()->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Create a notification without sending it
     * 
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $subject Subject of the notification
     * @param array $data Additional notification data
     * @param array $options Additional options (priority, schedule)
     * @return Notification The created notification
     */
    public function create(
        string $type,
        Notifiable $notifiable,
        string $subject,
        array $data = [],
        array $options = []
    ): Notification {
        // Generate a unique ID
        $id = is_callable($this->idGenerator) 
            ? call_user_func($this->idGenerator) 
            : uniqid('notification_');
        
        $notification = new Notification(
            $id,
            $type,
            $subject,
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $data
        );
        
        // Set priority if specified
        if (isset($options['priority'])) {
            $notification->setPriority($options['priority']);
        }
        
        // Schedule for later if specified
        if (isset($options['schedule']) && $options['schedule'] instanceof DateTime) {
            $notification->schedule($options['schedule']);
        }
        
        return $notification;
    }
    
    /**
     * Send a notification using a template
     * 
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $templateName Template name
     * @param array $templateData Data for template rendering
     * @param array $options Additional options (channels, priority, schedule)
     * @return array Result of the send operation
     * @throws InvalidArgumentException If template manager is not available or template not found
     */
    public function sendWithTemplate(
        string $type,
        Notifiable $notifiable,
        string $templateName,
        array $templateData = [],
        array $options = []
    ): array {
        if ($this->templateManager === null) {
            throw new InvalidArgumentException('Template manager is not available.');
        }
        
        // Get the template
        $templateMap = $this->templateManager->resolveTemplates($type, $templateName);
        
        if (empty($templateMap)) {
            throw new InvalidArgumentException("No templates found for type '{$type}' and name '{$templateName}'.");
        }
        
        // Set subject from template if not provided
        if (!isset($options['subject']) && isset($templateData['subject'])) {
            $options['subject'] = $templateData['subject'];
        } elseif (!isset($options['subject'])) {
            // Default subject based on notification type
            $options['subject'] = ucfirst(str_replace('_', ' ', $type));
        }
        
        // Create notification
        $notification = $this->create(
            $type,
            $notifiable,
            $options['subject'],
            ['template_data' => $templateData, 'template_name' => $templateName],
            $options
        );
        
        // Send notification
        return $this->dispatcher->send(
            $notification,
            $notifiable,
            $options['channels'] ?? null
        );
    }
    
    /**
     * Mark a notification as read
     * 
     * @param Notification $notification The notification to mark as read
     * @param DateTime|null $readAt When the notification was read (null for current time)
     * @return Notification The updated notification
     */
    public function markAsRead(Notification $notification, ?DateTime $readAt = null): Notification
    {
        return $notification->markAsRead($readAt);
    }
    
    /**
     * Mark a notification as unread
     * 
     * @param Notification $notification The notification to mark as unread
     * @return Notification The updated notification
     */
    public function markAsUnread(Notification $notification): Notification
    {
        return $notification->markAsUnread();
    }
    
    /**
     * Set user preference for a notification type
     * 
     * @param Notifiable $notifiable The user or entity
     * @param string $notificationType Notification type
     * @param array|null $channels Preferred channels (null to use defaults)
     * @param bool $enabled Whether notifications are enabled
     * @param array|null $settings Additional settings
     * @return NotificationPreference The created/updated preference
     */
    public function setPreference(
        Notifiable $notifiable,
        string $notificationType,
        ?array $channels = null,
        bool $enabled = true,
        ?array $settings = null
    ): NotificationPreference {
        // Generate a unique ID
        $id = is_callable($this->idGenerator) 
            ? call_user_func($this->idGenerator) 
            : uniqid('preference_');
        
        return new NotificationPreference(
            $id,
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $notificationType,
            $channels,
            $enabled,
            $settings
        );
    }
    
    /**
     * Set a notification ID generator
     * 
     * @param callable $generator Function that generates unique IDs
     * @return self
     */
    public function setIdGenerator(callable $generator): self
    {
        $this->idGenerator = $generator;
        return $this;
    }
    
    /**
     * Get the notification dispatcher
     * 
     * @return NotificationDispatcher The notification dispatcher
     */
    public function getDispatcher(): NotificationDispatcher
    {
        return $this->dispatcher;
    }
    
    /**
     * Get the template manager
     * 
     * @return TemplateManager|null The template manager
     */
    public function getTemplateManager(): ?TemplateManager
    {
        return $this->templateManager;
    }
    
    /**
     * Set the template manager
     * 
     * @param TemplateManager $templateManager The template manager
     * @return self
     */
    public function setTemplateManager(TemplateManager $templateManager): self
    {
        $this->templateManager = $templateManager;
        return $this;
    }
    
    /**
     * Set configuration option
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }
    
    /**
     * Get configuration option
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}