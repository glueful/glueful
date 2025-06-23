<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Database\EntityCreatedEvent;
use Glueful\Events\Database\EntityUpdatedEvent;
use Glueful\Logging\LogManager;

/**
 * Audit Logging Event Listener
 *
 * Provides comprehensive audit logging
 *
 * @package Glueful\Events\Listeners
 */
class AuditLoggingListener
{
    public function __construct(
        private LogManager $logger
    ) {
    }

    /**
     * Handle session created events
     */
    public function onSessionCreated(SessionCreatedEvent $event): void
    {
        $this->logger->info('User session created', [
            'user_uuid' => $event->getUserUuid(),
            'username' => $event->getUsername(),
            'access_token' => substr($event->getAccessToken() ?? '', 0, 8) . '...',
            'metadata' => $event->getMetadata()
        ]);
    }

    /**
     * Handle entity creation events
     */
    public function onEntityCreated(EntityCreatedEvent $event): void
    {
        if ($event->isUserRelated()) {
            $this->logger->info('Entity created', [
                'table' => $event->getTable(),
                'entity_id' => $event->getEntityId(),
                'metadata' => $event->getMetadata()
            ]);
        }
    }

    /**
     * Handle entity update events
     */
    public function onEntityUpdated(EntityUpdatedEvent $event): void
    {
        if ($event->isCriticalUpdate()) {
            $this->logger->warning('Critical entity update', [
                'table' => $event->getTable(),
                'entity_id' => $event->getEntityId(),
                'changes' => $event->getChanges(),
                'metadata' => $event->getMetadata()
            ]);
        }
    }
}
