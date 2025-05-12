<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\{Rules, Sanitize};

class UsernameDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Rules(['required', 'string'])]

    public string $username;

    public function __construct(string $username)
    {
        $this->username = $username;
    }
}
