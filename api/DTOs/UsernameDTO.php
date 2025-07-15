<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Required, StringLength};

class UsernameDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[StringLength(
        min: 3,
        max: 30,
        minMessage: 'Username must be at least 3 characters',
        maxMessage: 'Username must be at most 30 characters'
    )]
    public string $username;

    public function __construct(string $username)
    {
        $this->username = $username;
    }
}
