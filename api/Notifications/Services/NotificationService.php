<?php
declare(strict_types=1);

namespace Glueful\Notifications\Services;

use DateTime;
use Glueful\Helpers\Utils;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Templates\TemplateManager;
use Glueful\Repository\NotificationRepository;
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
     * @var NotificationRepository The notification repository
     */
    private NotificationRepository $repository;
    
    /**
     * @var callable Generator for notification IDs
     */
    private $idGenerator;
    
    /**
     * @var array Configuration options
     */
    private array $config;
    
    /**
     * NotificationService constructor
     * 
     * @param NotificationDispatcher $dispatcher Notification dispatcher
     * @param NotificationRepository $repository Notification repository
     * @param TemplateManager|null $templateManager Template manager
     * @param array $config Configuration options
     */
    public function __construct(
        NotificationDispatcher $dispatcher,
        NotificationRepository $repository,
        ?TemplateManager $templateManager = null,
        array $config = []
    ) {
        $this->dispatcher = $dispatcher;
        $this->repository = $repository;
        $this->templateManager = $templateManager;
        $this->config = $config;
        
        // Default ID generator uses Utils::generateNanoID
        $this->idGenerator = $config['id_generator'] ?? function() {
            return Utils::generateNanoID();
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
        
        // Save to database first
        $this->repository->save($notification);
        
        // Send it immediately unless scheduled for later
        if (!isset($options['schedule']) || $options['schedule'] === null) {
            $result = $this->dispatcher->send(
                $notification,
                $notifiable,
                $options['channels'] ?? null
            );
            
            // Update notification in database after sending
            if ($result['status'] === 'success') {
                $notification->markAsSent();
                $this->repository->save($notification);
            }
            
            return $result;
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
        
        // Save to database
        $this->repository->save($notification);
        
        // Send notification
        $result = $this->dispatcher->send(
            $notification,
            $notifiable,
            $options['channels'] ?? null
        );
        
        // Update notification in database after sending
        if ($result['status'] === 'success') {
            $notification->markAsSent();
            $this->repository->save($notification);
        }
        
        return $result;
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
        $notification = $notification->markAsRead($readAt);
        $this->repository->save($notification);
        return $notification;
    }
    
    /**
     * Mark a notification as unread
     * 
     * @param Notification $notification The notification to mark as unread
     * @return Notification The updated notification
     */
    public function markAsUnread(Notification $notification): Notification
    {
        $notification = $notification->markAsUnread();
        $this->repository->save($notification);
        return $notification;
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
        
        $preference = new NotificationPreference(
            $id,
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $notificationType,
            $channels,
            $enabled,
            $settings
        );
        
        // Save to database
        $this->repository->savePreference($preference);
        
        return $preference;
    }
    
    /**
     * Get notifications for a user
     * 
     * @param Notifiable $notifiable Recipient
     * @param bool $onlyUnread Whether to get only unread notifications
     * @param int|null $limit Maximum number of notifications
     * @param int|null $offset Pagination offset
     * @return array Array of Notification objects
     */
    public function getNotifications(
        Notifiable $notifiable,
        bool $onlyUnread = false,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        return $this->repository->findForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $onlyUnread,
            $limit,
            $offset
        );
    }
    
    /**
     * Get notification by ID
     * 
     * @param string $id Notification ID
     * @return Notification|null The notification or null if not found
     */
    public function getNotificationById(string $id): ?Notification {
        return $this->repository->findById($id);
    }
    
    /**
     * Get unread notification count for a user
     * 
     * @param Notifiable $notifiable Recipient
     * @return int Count of unread notifications
     */
    public function getUnreadCount(Notifiable $notifiable): int {
        return $this->repository->countUnread(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId()
        );
    }
    
    /**
     * Mark all notifications as read for a user
     * 
     * @param Notifiable $notifiable Recipient
     * @return int Number of notifications updated
     */
    public function markAllAsRead(Notifiable $notifiable): int {
        return $this->repository->markAllAsRead(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId()
        );
    }
    
    /**
     * Process scheduled notifications
     * 
     * This method should be called from a cron job or scheduler
     * to process notifications that were scheduled for delivery.
     * 
     * @param int $batchSize Maximum number of notifications to process
     * @return array Processing results
     */
    public function processScheduledNotifications(int $batchSize = 50): array {
        $pendingNotifications = $this->repository->findPendingScheduled(null, $batchSize);
        
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0
        ];
        
        foreach ($pendingNotifications as $notification) {
            $results['processed']++;
            
            // Get notifiable entity
            $notifiableType = $notification->getNotifiableType();
            $notifiableId = $notification->getNotifiableId();
            
            // This assumes you have a way to get the notifiable entity
            // You would need to implement this method or similar
            $notifiable = $this->getNotifiableEntity($notifiableType, $notifiableId);
            
            if (!$notifiable) {
                $results['failed']++;
                continue;
            }
            
            $sendResult = $this->dispatcher->send($notification, $notifiable);
            
            if ($sendResult['status'] === 'success') {
                $notification->markAsSent();
                $this->repository->save($notification);
                $results['successful']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Delete old notifications
     * 
     * @param int $olderThanDays Delete notifications older than this many days
     * @return bool Success status
     */
    public function deleteOldNotifications(int $olderThanDays): bool {
        return $this->repository->deleteOldNotifications($olderThanDays);
    }
    
    /**
     * Get notification preferences for a user
     * 
     * @param Notifiable $notifiable The user or entity
     * @return array Array of NotificationPreference objects
     */
    public function getPreferences(Notifiable $notifiable): array {
        return $this->repository->findPreferencesForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId()
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
     * Get the notification repository
     * 
     * @return NotificationRepository The notification repository
     */
    public function getRepository(): NotificationRepository
    {
        return $this->repository;
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
    
    /**
     * Get the notifiable entity from type and ID
     * 
     * Note: This is a placeholder method that should be implemented
     * based on your application's entity retrieval mechanism.
     * 
     * @param string $type Entity type
     * @param string $id Entity ID
     * @return Notifiable|null The notifiable entity
     */
    protected function getNotifiableEntity(string $type, string $id): ?Notifiable
    {
        // This is a placeholder implementation
        // In a real application, you would retrieve the entity from your ORM/database
        
        // Example implementation for a User entity:
        // if ($type === 'user') {
        //     $userRepository = new UserRepository();
        //     return $userRepository->findById($id);
        // }
        
        return null;
    }
}