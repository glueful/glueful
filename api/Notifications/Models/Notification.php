<?php
declare(strict_types=1);

namespace Glueful\Notifications\Models;

use DateTime;
use JsonSerializable;

/**
 * Notification Model
 * 
 * Represents a notification entity in the system.
 * Maps to the 'notifications' table in the database.
 * 
 * @package Glueful\Notifications\Models
 */
class Notification implements JsonSerializable
{
    /**
     * @var string|null Unique identifier for the notification
     */
    private ?string $id;
    
    /**
     * @var string|null UUID for the notification, used for consistent cross-system identification
     */
    private ?string $uuid;
    
    /**
     * @var string Type of notification (e.g., 'account_created', 'payment_received')
     */
    private string $type;
    
    /**
     * @var string Subject or title of the notification
     */
    private string $subject;
    
    /**
     * @var array|null Data associated with the notification
     */
    private ?array $data;
    
    /**
     * @var string Priority level ('high', 'normal', 'low')
     */
    private string $priority;
    
    /**
     * @var string Type of the entity receiving the notification
     */
    private string $notifiableType;
    
    /**
     * @var string ID of the entity receiving the notification
     */
    private string $notifiableId;
    
    /**
     * @var DateTime|null When the notification was read by recipient
     */
    private ?DateTime $readAt;
    
    /**
     * @var DateTime|null When the notification is scheduled to be sent
     */
    private ?DateTime $scheduledAt;
    
    /**
     * @var DateTime|null When the notification was sent
     */
    private ?DateTime $sentAt;
    
    /**
     * @var DateTime When the notification was created
     */
    private DateTime $createdAt;
    
    /**
     * @var DateTime|null When the notification was last updated
     */
    private ?DateTime $updatedAt;
    
    /**
     * Notification constructor.
     * 
     * @param string $type Notification type
     * @param string $subject Notification subject
     * @param string $notifiableType Type of notifiable entity
     * @param string $notifiableId ID of notifiable entity
     * @param array|null $data Additional notification data
     * @param string|null $uuid UUID for cross-system identification
     * @param string|null $id Unique identifier (moved to the end as optional parameter)
     */
    public function __construct(
        string $type,
        string $subject,
        string $notifiableType,
        string $notifiableId,
        ?array $data = null,
        ?string $uuid = null,
        ?string $id = null
    ) {
        $this->type = $type;
        $this->subject = $subject;
        $this->notifiableType = $notifiableType;
        $this->notifiableId = $notifiableId;
        $this->data = $data;
        $this->uuid = $uuid;
        $this->id = $id;
        $this->priority = 'normal';
        $this->readAt = null;
        $this->scheduledAt = null;
        $this->sentAt = null;
        $this->createdAt = new DateTime();
        $this->updatedAt = null;
    }
    
    /**
     * Get notification ID
     * 
     * @return string|null Notification unique identifier
     */
    public function getId(): ?string
    {
        return $this->id;
    }
    
    /**
     * Get notification UUID
     * 
     * @return string|null Notification UUID
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }
    
    /**
     * Set notification UUID
     * 
     * @param string $uuid Notification UUID
     * @return self
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get notification type
     * 
     * @return string Notification type
     */
    public function getType(): string
    {
        return $this->type;
    }
    
    /**
     * Set notification type
     * 
     * @param string $type Notification type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get notification subject
     * 
     * @return string Notification subject
     */
    public function getSubject(): string
    {
        return $this->subject;
    }
    
    /**
     * Set notification subject
     * 
     * @param string $subject Notification subject
     * @return self
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get notification data
     * 
     * @return array|null Notification data
     */
    public function getData(): ?array
    {
        return $this->data;
    }
    
    /**
     * Set notification data
     * 
     * @param array|null $data Notification data
     * @return self
     */
    public function setData(?array $data): self
    {
        $this->data = $data;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get notification priority
     * 
     * @return string Notification priority
     */
    public function getPriority(): string
    {
        return $this->priority;
    }
    
    /**
     * Set notification priority
     * 
     * @param string $priority Notification priority
     * @return self
     */
    public function setPriority(string $priority): self
    {
        $this->priority = $priority;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get notifiable type
     * 
     * @return string Notifiable entity type
     */
    public function getNotifiableType(): string
    {
        return $this->notifiableType;
    }
    
    /**
     * Get notifiable ID
     * 
     * @return string Notifiable entity ID
     */
    public function getNotifiableId(): string
    {
        return $this->notifiableId;
    }
    
    /**
     * Check if notification has been read
     * 
     * @return bool Whether notification has been read
     */
    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
    
    /**
     * Mark notification as read
     * 
     * @param DateTime|null $readAt When the notification was read
     * @return self
     */
    public function markAsRead(?DateTime $readAt = null): self
    {
        $this->readAt = $readAt ?? new DateTime();
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Mark notification as unread
     * 
     * @return self
     */
    public function markAsUnread(): self
    {
        $this->readAt = null;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get read timestamp
     * 
     * @return DateTime|null When the notification was read
     */
    public function getReadAt(): ?DateTime
    {
        return $this->readAt;
    }
    
    /**
     * Schedule notification for later delivery
     * 
     * @param DateTime $scheduledAt When to send the notification
     * @return self
     */
    public function schedule(DateTime $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get scheduled timestamp
     * 
     * @return DateTime|null When the notification is scheduled
     */
    public function getScheduledAt(): ?DateTime
    {
        return $this->scheduledAt;
    }
    
    /**
     * Mark notification as sent
     * 
     * @param DateTime|null $sentAt When the notification was sent
     * @return self
     */
    public function markAsSent(?DateTime $sentAt = null): self
    {
        $this->sentAt = $sentAt ?? new DateTime();
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get sent timestamp
     * 
     * @return DateTime|null When the notification was sent
     */
    public function getSentAt(): ?DateTime
    {
        return $this->sentAt;
    }
    
    /**
     * Check if notification has been sent
     * 
     * @return bool Whether notification has been sent
     */
    public function isSent(): bool
    {
        return $this->sentAt !== null;
    }
    
    /**
     * Get creation timestamp
     * 
     * @return DateTime When the notification was created
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
    
    /**
     * Get last update timestamp
     * 
     * @return DateTime|null When the notification was last updated
     */
    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }
    
    /**
     * Convert the notification to an array
     * 
     * @return array Notification as array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'subject' => $this->subject,
            'data' => $this->data,
            'priority' => $this->priority,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'read_at' => $this->readAt ? $this->readAt->format('Y-m-d H:i:s') : null,
            'scheduled_at' => $this->scheduledAt ? $this->scheduledAt->format('Y-m-d H:i:s') : null,
            'sent_at' => $this->sentAt ? $this->sentAt->format('Y-m-d H:i:s') : null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
        ];
    }
    
    /**
     * Prepare the notification for JSON serialization
     * 
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    /**
     * Create a notification from a database record
     * 
     * @param array $data Database record
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $notification = new self(
            $data['type'],
            $data['subject'],
            $data['notifiable_type'],
            $data['notifiable_id'],
            isset($data['data']) ? (is_string($data['data']) ? json_decode($data['data'], true) : $data['data']) : null,
            $data['uuid'] ?? null,
            isset($data['id']) ? (string)$data['id'] : null
        );
        
        if (isset($data['priority'])) {
            $notification->setPriority($data['priority']);
        }
        
        if (!empty($data['read_at'])) {
            $notification->readAt = new DateTime($data['read_at']);
        }
        
        if (!empty($data['scheduled_at'])) {
            $notification->scheduledAt = new DateTime($data['scheduled_at']);
        }
        
        if (!empty($data['sent_at'])) {
            $notification->sentAt = new DateTime($data['sent_at']);
        }
        
        if (!empty($data['created_at'])) {
            $notification->createdAt = new DateTime($data['created_at']);
        }
        
        if (!empty($data['updated_at'])) {
            $notification->updatedAt = new DateTime($data['updated_at']);
        }
        
        return $notification;
    }
}