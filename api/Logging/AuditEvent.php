<?php

declare(strict_types=1);

namespace Glueful\Logging;

use Glueful\Helpers\Utils;
use JsonSerializable;

/**
 * AuditEvent
 *
 * Standardized audit event structure for enterprise-grade security logging.
 * Provides a tamper-evident, immutable record of security-relevant events
 * with cryptographic integrity protection.
 *
 * Features:
 * - Standardized event schema with required and optional fields
 * - JSON serialization for flexible storage options
 * - Event correlation through related IDs
 * - Cryptographic integrity verification
 * - Support for multiple event categories
 *
 * @package Glueful\Logging
 */
class AuditEvent implements JsonSerializable
{
    // Event category constants
    public const CATEGORY_AUTH = 'authentication';
    public const CATEGORY_AUTHZ = 'authorization';
    public const CATEGORY_DATA = 'data_access';
    public const CATEGORY_ADMIN = 'administrative';
    public const CATEGORY_CONFIG = 'configuration';
    public const CATEGORY_SYSTEM = 'system';

    // Event severity levels (aligned with PSR-3 log levels)
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_ALERT = 'alert';
    public const SEVERITY_EMERGENCY = 'emergency';

    /**
     * @var string Event UUID
     */
    private string $eventId;

    /**
     * @var string Event category
     */
    private string $category;

    /**
     * @var string Event action
     */
    private string $action;

    /**
     * @var string Event severity
     */
    private string $severity;

    /**
     * @var string|null Actor UUID (user or system performing the action)
     */
    private ?string $actorId;

    /**
     * @var string|null Target UUID (resource being acted upon)
     */
    private ?string $targetId;

    /**
     * @var string|null Target type (e.g., 'user', 'role', 'file')
     */
    private ?string $targetType;

    /**
     * @var string Timestamp when the event occurred (ISO 8601 format)
     */
    private string $timestamp;

    /**
     * @var string|null IP address where the event originated
     */
    private ?string $ipAddress;

    /**
     * @var string|null User agent that originated the event
     */
    private ?string $userAgent;

    /**
     * @var string|null Request URI associated with the event
     */
    private ?string $requestUri;

    /**
     * @var string|null HTTP method used (GET, POST, etc.)
     */
    private ?string $requestMethod;

    /**
     * @var array Additional event details
     */
    private array $details;

    /**
     * @var string|null Related event ID for correlation
     */
    private ?string $relatedEventId;

    /**
     * @var string|null Session ID associated with the event
     */
    private ?string $sessionId;

    /**
     * @var string|null Cryptographic hash for tamper detection
     */
    private ?string $integrityHash;

    /**
     * Construct a new audit event
     *
     * @param string $category Event category (use CATEGORY_* constants)
     * @param string $action Specific action being audited
     * @param string $severity Event severity level (use SEVERITY_* constants)
     * @param array $details Event-specific details and context
     */
    public function __construct(string $category, string $action, string $severity, array $details = [])
    {
        $this->eventId = Utils::generateNanoID();
        $this->category = $category;
        $this->action = $action;
        $this->severity = $severity;
        $this->details = $details;
        $this->timestamp = (new \DateTimeImmutable())->format('c'); // ISO 8601
        $this->actorId = null;
        $this->targetId = null;
        $this->targetType = null;
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->relatedEventId = null;
        $this->sessionId = session_id() ?: null;

        // Generate integrity hash after all other fields are set
        $this->generateIntegrityHash();
    }

    /**
     * Set the actor (user/system) who performed the action
     *
     * @param string $actorId UUID of the actor
     * @return self
     */
    public function setActor(string $actorId): self
    {
        $this->actorId = $actorId;
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Set the target being acted upon
     *
     * @param string $targetId UUID of the target
     * @param string $targetType Type of target (e.g., 'user', 'role')
     * @return self
     */
    public function setTarget(string $targetId, string $targetType): self
    {
        $this->targetId = $targetId;
        $this->targetType = $targetType;
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Set a related event ID for correlation
     *
     * @param string $eventId UUID of related event
     * @return self
     */
    public function setRelatedEvent(string $eventId): self
    {
        $this->relatedEventId = $eventId;
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Set network information explicitly
     *
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @return self
     */
    public function setNetworkInfo(?string $ipAddress, ?string $userAgent): self
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Set request information explicitly
     *
     * @param string|null $uri Request URI
     * @param string|null $method HTTP method
     * @return self
     */
    public function setRequestInfo(?string $uri, ?string $method): self
    {
        $this->requestUri = $uri;
        $this->requestMethod = $method;
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Add additional details to the event
     *
     * @param array $details Additional event details
     * @return self
     */
    public function addDetails(array $details): self
    {
        $this->details = array_merge($this->details, $details);
        $this->generateIntegrityHash();
        return $this;
    }

    /**
     * Generate a cryptographic hash for tamper detection
     *
     * @return void
     */
    private function generateIntegrityHash(): void
    {
        // Create a standardized representation of all fields for hashing
        $data = [
            'eventId' => $this->eventId,
            'category' => $this->category,
            'action' => $this->action,
            'severity' => $this->severity,
            'actorId' => $this->actorId,
            'targetId' => $this->targetId,
            'targetType' => $this->targetType,
            'timestamp' => $this->timestamp,
            'ipAddress' => $this->ipAddress,
            'userAgent' => $this->userAgent,
            'requestUri' => $this->requestUri,
            'requestMethod' => $this->requestMethod,
            'details' => $this->details,
            'relatedEventId' => $this->relatedEventId,
            'sessionId' => $this->sessionId
        ];

        // Generate a cryptographic hash of the data
        $serialized = json_encode($data, JSON_UNESCAPED_SLASHES);
        $this->integrityHash = hash('sha256', $serialized);
    }

    /**
     * Verify the integrity of the event
     *
     * @return bool True if the event has not been tampered with
     */
    public function verifyIntegrity(): bool
    {
        $currentHash = $this->integrityHash;
        $this->generateIntegrityHash();
        $newHash = $this->integrityHash;

        // Restore the original hash
        $this->integrityHash = $currentHash;

        // Compare the hashes
        return hash_equals($currentHash, $newHash);
    }

    /**
     * Get event ID
     *
     * @return string
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * Get event category
     *
     * @return string
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Get event action
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get event severity
     *
     * @return string
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Get actor ID
     *
     * @return string|null
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Get target ID
     *
     * @return string|null
     */
    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /**
     * Get target type
     *
     * @return string|null
     */
    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    /**
     * Get event timestamp
     *
     * @return string
     */
    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    /**
     * Get all event details
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get the integrity hash
     *
     * @return string|null
     */
    public function getIntegrityHash(): ?string
    {
        return $this->integrityHash;
    }

    /**
     * Convert to array for storage or serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'category' => $this->category,
            'action' => $this->action,
            'severity' => $this->severity,
            'actor_id' => $this->actorId,
            'target_id' => $this->targetId,
            'target_type' => $this->targetType,
            'timestamp' => $this->timestamp,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'request_uri' => $this->requestUri,
            'request_method' => $this->requestMethod,
            'details' => $this->details,
            'related_event_id' => $this->relatedEventId,
            'session_id' => $this->sessionId,
            'integrity_hash' => $this->integrityHash
        ];
    }

    /**
     * Implement JsonSerializable for direct JSON encoding
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Create event from array data (for reconstruction)
     *
     * @param array $data Event data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $event = new self(
            $data['category'],
            $data['action'],
            $data['severity'],
            $data['details'] ?? []
        );

        // Override the generated ID with the stored one
        $reflection = new \ReflectionClass($event);
        $prop = $reflection->getProperty('eventId');
        $prop->setAccessible(true);
        $prop->setValue($event, $data['event_id']);

        // Set other properties
        if (isset($data['actor_id'])) {
            $event->setActor($data['actor_id']);
        }

        if (isset($data['target_id']) && isset($data['target_type'])) {
            $event->setTarget($data['target_id'], $data['target_type']);
        }

        if (isset($data['ip_address']) || isset($data['user_agent'])) {
            $event->setNetworkInfo(
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null
            );
        }

        if (isset($data['request_uri']) || isset($data['request_method'])) {
            $event->setRequestInfo(
                $data['request_uri'] ?? null,
                $data['request_method'] ?? null
            );
        }

        if (isset($data['related_event_id'])) {
            $event->setRelatedEvent($data['related_event_id']);
        }

        // Override the timestamp with the stored one
        $timestampProp = $reflection->getProperty('timestamp');
        $timestampProp->setAccessible(true);
        $timestampProp->setValue($event, $data['timestamp']);

        // Override the session ID with the stored one
        $sessionProp = $reflection->getProperty('sessionId');
        $sessionProp->setAccessible(true);
        $sessionProp->setValue($event, $data['session_id'] ?? null);

        // Override the integrity hash with the stored one
        $hashProp = $reflection->getProperty('integrityHash');
        $hashProp->setAccessible(true);
        $hashProp->setValue($event, $data['integrity_hash']);

        return $event;
    }
}
