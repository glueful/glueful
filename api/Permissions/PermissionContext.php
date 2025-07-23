<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Symfony\Component\HttpFoundation\Request;

class PermissionContext
{
    public function __construct(
        public readonly array $data = [],
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestId = null
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            requestId: $request->headers->get('X-Request-ID')
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'data' => $this->data,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'request_id' => $this->requestId
        ]);
    }
}
