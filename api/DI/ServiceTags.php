<?php

declare(strict_types=1);

namespace Glueful\DI;

class ServiceTags
{
    // Event system tags
    public const EVENT_SUBSCRIBER = 'event.subscriber';
    public const EVENT_LISTENER = 'event.listener';

    // HTTP middleware tags
    public const MIDDLEWARE = 'middleware';
    public const GLOBAL_MIDDLEWARE = 'middleware.global';
    public const ROUTE_MIDDLEWARE = 'middleware.route';

    // Validation tags
    public const VALIDATION_RULE = 'validation.rule';
    public const VALIDATION_CONSTRAINT = 'validation.constraint';

    // Console tags
    public const CONSOLE_COMMAND = 'console.command';

    // Extension tags
    public const EXTENSION_SERVICE = 'extension.service';
    public const EXTENSION_PROVIDER = 'extension.provider';

    // Cache tags
    public const CACHE_ADAPTER = 'cache.adapter';
    public const CACHE_POOL = 'cache.pool';

    // Security tags
    public const SECURITY_VOTER = 'security.voter';
    public const SECURITY_AUTHENTICATOR = 'security.authenticator';

    // Serialization tags
    public const SERIALIZER_NORMALIZER = 'serializer.normalizer';
    public const SERIALIZER_ENCODER = 'serializer.encoder';
}
