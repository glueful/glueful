<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Logging\AuditEvent;

/**
 * Resource Auditing Trait
 *
 * Provides resource-specific audit logging functionality for controllers.
 * Works in conjunction with AsyncAuditTrait for comprehensive audit trails.
 *
 * @package Glueful\Controllers\Traits
 */
trait ResourceAuditingTrait
{
    /**
     * Log resource access for audit trail
     *
     * This method can be overridden by child controllers to provide
     * specific resource access logging behavior.
     *
     * @param string $operation Operation performed
     * @param string $table Table/resource name
     * @param string|null $uuid Resource UUID
     * @return void
     */
    protected function logResourceAccess(string $operation, string $table, ?string $uuid = null): void
    {
        // Default implementation uses high-volume async audit logging
        $this->asyncAudit(
            'resource_access',
            $operation,
            AuditEvent::SEVERITY_INFO,
            [
                'table' => $table,
                'uuid' => $uuid,
                'user_uuid' => $this->getCurrentUserUuid(),
                'operation' => $operation
            ]
        );
    }
}
