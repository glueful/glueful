<?php

declare(strict_types=1);

namespace Glueful\Notifications\Models;

use DateTime;
use JsonSerializable;

/**
 * NotificationTemplate Model
 *
 * Represents a notification template for formatting notifications on different channels.
 * Maps to the 'notification_templates' table in the database.
 *
 * @package Glueful\Notifications\Models
 */
class NotificationTemplate implements JsonSerializable
{
    /**
     * @var string Unique identifier for the template
     */
    private string $id;

    /**
     * @var string|null UUID for the template, used for consistent cross-system identification
     */
    private ?string $uuid;

    /**
     * @var string Template name/identifier
     */
    private string $name;

    /**
     * @var string Type of notification this template applies to
     */
    private string $notificationType;

    /**
     * @var string Channel this template is for (email, sms, database, etc.)
     */
    private string $channel;

    /**
     * @var string Template content with placeholders
     */
    private string $content;

    /**
     * @var array|null Additional parameters for the template
     */
    private ?array $parameters;

    /**
     * @var DateTime When the template was created
     */
    private DateTime $createdAt;

    /**
     * @var DateTime|null When the template was last updated
     */
    private ?DateTime $updatedAt;

    /**
     * NotificationTemplate constructor.
     *
     * @param string $id Unique identifier
     * @param string $name Template name
     * @param string $notificationType Notification type
     * @param string $channel Notification channel
     * @param string $content Template content
     * @param array|null $parameters Additional parameters
     * @param string|null $uuid UUID for cross-system identification
     */
    public function __construct(
        string $id,
        string $name,
        string $notificationType,
        string $channel,
        string $content,
        ?array $parameters = null,
        ?string $uuid = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->notificationType = $notificationType;
        $this->channel = $channel;
        $this->content = $content;
        $this->parameters = $parameters;
        $this->uuid = $uuid;
        $this->createdAt = new DateTime();
        $this->updatedAt = null;
    }

    /**
     * Get template ID
     *
     * @return string Template unique identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get template UUID
     *
     * @return string|null Template UUID
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * Set template UUID
     *
     * @param string $uuid Template UUID
     * @return self
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        $this->updatedAt = new DateTime();
        return $this;
    }

    /**
     * Get template name
     *
     * @return string Template name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set template name
     *
     * @param string $name Template name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new DateTime();
        return $this;
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
     * Get channel
     *
     * @return string Channel identifier
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Set channel
     *
     * @param string $channel Channel identifier
     * @return self
     */
    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        $this->updatedAt = new DateTime();
        return $this;
    }

    /**
     * Get template content
     *
     * @return string Template content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set template content
     *
     * @param string $content Template content
     * @return self
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->updatedAt = new DateTime();
        return $this;
    }

    /**
     * Get template parameters
     *
     * @return array|null Template parameters
     */
    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    /**
     * Set template parameters
     *
     * @param array|null $parameters Template parameters
     * @return self
     */
    public function setParameters(?array $parameters): self
    {
        $this->parameters = $parameters;
        $this->updatedAt = new DateTime();
        return $this;
    }

    /**
     * Get created timestamp
     *
     * @return DateTime When the template was created
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * Get updated timestamp
     *
     * @return DateTime|null When the template was last updated
     */
    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Render the template with data
     *
     * Replace placeholders in template content with actual values
     *
     * @param array $data Data to use for variable replacement
     * @return string Rendered content
     */
    public function render(array $data): string
    {
        $content = $this->content;

        // Handle simple placeholders like {{variable}}
        $content = preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) use ($data) {
            $key = trim($matches[1]);
            return $data[$key] ?? '';
        }, $content);

        return $content;
    }

    /**
     * Convert the template to an array
     *
     * @return array Template as array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'notification_type' => $this->notificationType,
            'channel' => $this->channel,
            'content' => $this->content,
            'parameters' => $this->parameters,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Prepare the template for JSON serialization
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Create a template from a database record
     *
     * @param array $data Database record
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $template = new self(
            $data['id'],
            $data['name'],
            $data['notification_type'],
            $data['channel'],
            $data['content'],
            isset($data['parameters'])
                ? (is_string($data['parameters'])
                    ? json_decode($data['parameters'], true)
                    : $data['parameters'])
                : null,
            $data['uuid'] ?? null
        );

        if (!empty($data['created_at'])) {
            $template->createdAt = new DateTime($data['created_at']);
        }

        if (!empty($data['updated_at'])) {
            $template->updatedAt = new DateTime($data['updated_at']);
        }

        return $template;
    }
}
