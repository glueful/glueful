<?php
declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\{Rules, Sanitize};

class EmailDTO {
    #[Sanitize(['trim', 'strip_tags'])]
    #[Rules(['required', 'string'])]
    public string $email;
}