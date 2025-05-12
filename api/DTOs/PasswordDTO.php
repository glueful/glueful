<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\{Rules, Sanitize};

class PasswordDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Rules(['required', 'string', 'min:8', 'max:100'])]

    public string $password;
}
