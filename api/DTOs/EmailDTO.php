<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Required, Email};

class EmailDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[Email(message: 'Please provide a valid email address')]
    public string $email;
}
