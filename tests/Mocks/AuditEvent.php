<?php

namespace Glueful\Logging;

/**
 * AuditEvent Class
 *
 * Provides constants for audit event categories and severities.
 */
class AuditEvent
{
    /** @var string System event category */
    public const CATEGORY_SYSTEM = 'system';

    /** @var string Security event category */
    public const CATEGORY_SECURITY = 'security';

    /** @var string User action event category */
    public const CATEGORY_USER = 'user';

    /** @var string Data event category */
    public const CATEGORY_DATA = 'data';

    /** @var string API event category */
    public const CATEGORY_API = 'api';

    /** @var string Info severity level */
    public const SEVERITY_INFO = 'info';

    /** @var string Warning severity level */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Error severity level */
    public const SEVERITY_ERROR = 'error';

    /** @var string Critical severity level */
    public const SEVERITY_CRITICAL = 'critical';
}
