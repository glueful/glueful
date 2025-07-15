<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Required, StringLength};

class PasswordDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[StringLength(
        min: 8,
        max: 100,
        minMessage: 'Password must be at least 8 characters',
        maxMessage: 'Password must be at most 100 characters'
    )]
    public string $password;
}
