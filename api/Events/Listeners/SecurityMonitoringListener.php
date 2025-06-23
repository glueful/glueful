<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\RateLimitExceededEvent;
use Glueful\Logging\LogManager;

/**
 * Security Monitoring Event Listener
 *
 * Monitors security-related events for threat detection
 *
 * @package Glueful\Events\Listeners
 */
class SecurityMonitoringListener
{
    public function __construct(
        private LogManager $logger
    ) {
    }

    /**
     * Handle authentication failed events
     */
    public function onAuthenticationFailed(AuthenticationFailedEvent $event): void
    {
        $this->logger->warning('Authentication failed', [
            'username' => $event->getUsername(),
            'reason' => $event->getReason(),
            'client_ip' => $event->getClientIp(),
            'user_agent' => $event->getUserAgent(),
            'suspicious' => $event->isSuspicious()
        ]);
    }

    /**
     * Handle rate limit exceeded events
     */
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        $this->logger->warning('Rate limit exceeded', [
            'client_ip' => $event->getClientIp(),
            'rule' => $event->getRule(),
            'current_count' => $event->getCurrentCount(),
            'limit' => $event->getLimit(),
            'excess_percentage' => $event->getExcessPercentage(),
            'severe' => $event->isSevereViolation()
        ]);
    }
}
