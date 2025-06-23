<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\Cache\CacheHitEvent;
use Glueful\Events\Database\QueryExecutedEvent;
use Glueful\Logging\LogManager;

/**
 * Performance Monitoring Event Listener
 *
 * Monitors performance-related events
 *
 * @package Glueful\Events\Listeners
 */
class PerformanceMonitoringListener
{
    public function __construct(
        private LogManager $logger
    ) {
    }

    /**
     * Handle slow query events
     */
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        if ($event->isSlow(1.0)) { // 1 second threshold
            $this->logger->warning('Slow query detected', [
                'sql' => $event->getSql(),
                'execution_time' => $event->getExecutionTime(),
                'connection' => $event->getConnectionName(),
                'type' => $event->getQueryType()
            ]);
        }
    }

    /**
     * Handle slow cache retrieval events
     */
    public function onCacheHit(CacheHitEvent $event): void
    {
        if ($event->isSlow(0.1)) { // 100ms threshold
            $this->logger->debug('Slow cache retrieval', [
                'key' => $event->getKey(),
                'retrieval_time' => $event->getRetrievalTime(),
                'value_size' => $event->getValueSize()
            ]);
        }
    }
}
