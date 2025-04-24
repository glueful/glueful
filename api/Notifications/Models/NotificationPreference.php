<?php
declare(strict_types=1);

namespace Glueful\Notifications\Models;

use DateTime;
use JsonSerializable;

/**
 * NotificationPreference Model
 * 
 * Represents a user's preferences for receiving notifications.
 * Maps to the 'notification_preferences' table in the database.
 * 
 * @package Glueful\Notifications\Models
 */
class NotificationPreference implements JsonSerializable
{
    /**
     * @var string Unique identifier for the preference
     */
    private string $id;
    
    /**
     * @var string Type of entity the preferences belong to
     */
    private string $notifiableType;
    
    /**
     * @var string ID of the entity the preferences belong to
     */
    private string $notifiableId;
    
    /**
     * @var string Type of notification these preferences apply to
     */
    private string $notificationType;
    
    /**
     * @var array|null Channels the user wants to receive this notification on
     */
    private ?array $channels;
    
    /**
     * @var bool Whether notifications of this type are enabled
     */
    private bool $enabled;
    
    /**
     * @var array|null Additional settings for this notification preference
     */
    private ?array $settings;
    
    /**
     * @var DateTime When the preference was created
     */
    private DateTime $createdAt;
    
    /**
     * @var DateTime|null When the preference was last updated
     */
    private ?DateTime $updatedAt;
    
    /**
     * NotificationPreference constructor.
     * 
     * @param string $id Unique identifier
     * @param string $notifiableType Type of notifiable entity
     * @param string $notifiableId ID of notifiable entity
     * @param string $notificationType Notification type
     * @param array|null $channels Preferred channels
     * @param bool $enabled Whether notifications are enabled
     * @param array|null $settings Additional settings
     */
    public function __construct(
        string $id,
        string $notifiableType,
        string $notifiableId,
        string $notificationType,
        ?array $channels = null,
        bool $enabled = true,
        ?array $settings = null
    ) {
        $this->id = $id;
        $this->notifiableType = $notifiableType;
        $this->notifiableId = $notifiableId;
        $this->notificationType = $notificationType;
        $this->channels = $channels;
        $this->enabled = $enabled;
        $this->settings = $settings;
        $this->createdAt = new DateTime();
        $this->updatedAt = null;
    }
    
    /**
     * Get preference ID
     * 
     * @return string Preference unique identifier
     */
    public function getId(): string
    {
        return $this->id;
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
     * Get notification type
     * 
     * @return string Notification type
     */
    public function getNotificationType(): string
    {
        return $this->notificationType;
    }
    
    /**
     * Set notification type
     * 
     * @param string $notificationType Notification type
     * @return self
     */
    public function setNotificationType(string $notificationType): self
    {
        $this->notificationType = $notificationType;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get preferred channels
     * 
     * @return array|null Preferred channels
     */
    public function getChannels(): ?array
    {
        return $this->channels;
    }
    
    /**
     * Set preferred channels
     * 
     * @param array|null $channels Preferred channels
     * @return self
     */
    public function setChannels(?array $channels): self
    {
        $this->channels = $channels;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Add a channel to preferred channels
     * 
     * @param string $channel Channel to add
     * @return self
     */
    public function addChannel(string $channel): self
    {
        if ($this->channels === null) {
            $this->channels = [];
        }
        
        if (!in_array($channel, $this->channels)) {
            $this->channels[] = $channel;
            $this->updatedAt = new DateTime();
        }
        
        return $this;
    }
    
    /**
     * Remove a channel from preferred channels
     * 
     * @param string $channel Channel to remove
     * @return self
     */
    public function removeChannel(string $channel): self
    {
        if ($this->channels !== null) {
            $key = array_search($channel, $this->channels);
            if ($key !== false) {
                unset($this->channels[$key]);
                $this->channels = array_values($this->channels); // Re-index array
                $this->updatedAt = new DateTime();
            }
        }
        
        return $this;
    }
    
    /**
     * Check if a channel is preferred
     * 
     * @param string $channel Channel to check
     * @return bool Whether the channel is preferred
     */
    public function hasChannel(string $channel): bool
    {
        return $this->channels !== null && in_array($channel, $this->channels);
    }
    
    /**
     * Get enabled status
     * 
     * @return bool Whether notifications are enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Set enabled status
     * 
     * @param bool $enabled Whether notifications are enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get additional settings
     * 
     * @return array|null Additional settings
     */
    public function getSettings(): ?array
    {
        return $this->settings;
    }
    
    /**
     * Set additional settings
     * 
     * @param array|null $settings Additional settings
     * @return self
     */
    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;
        $this->updatedAt = new DateTime();
        return $this;
    }
    
    /**
     * Get a specific setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public function getSetting(string $key, $default = null)
    {
        if ($this->settings === null) {
            return $default;
        }
        
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Set a specific setting value
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return self
     */
    public function setSetting(string $key, $value): self
    {
        if ($this->settings === null) {
            $this->settings = [];
        }
        
        $this->settings[$key] = $value;
        $this->updatedAt = new DateTime();
        
        return $this;
    }
    
    /**
     * Get creation timestamp
     * 
     * @return DateTime When the preference was created
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
    
    /**
     * Get update timestamp
     * 
     * @return DateTime|null When the preference was last updated
     */
    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }
    
    /**
     * Convert the preference to an array
     * 
     * @return array Preference as array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'notification_type' => $this->notificationType,
            'channels' => $this->channels,
            'enabled' => $this->enabled,
            'settings' => $this->settings,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
        ];
    }
    
    /**
     * Prepare the preference for JSON serialization
     * 
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    /**
     * Create a preference from a database record
     * 
     * @param array $data Database record
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $preference = new self(
            $data['id'],
            $data['notifiable_type'],
            $data['notifiable_id'],
            $data['notification_type'],
            isset($data['channels']) ? (is_string($data['channels']) ? json_decode($data['channels'], true) : $data['channels']) : null,
            isset($data['enabled']) ? (bool)$data['enabled'] : true,
            isset($data['settings']) ? (is_string($data['settings']) ? json_decode($data['settings'], true) : $data['settings']) : null
        );
        
        if (!empty($data['created_at'])) {
            $preference->createdAt = new DateTime($data['created_at']);
        }
        
        if (!empty($data['updated_at'])) {
            $preference->updatedAt = new DateTime($data['updated_at']);
        }
        
        return $preference;
    }
}