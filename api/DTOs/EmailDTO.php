<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Required, Email};
use Glueful\Serialization\Attributes\{Groups, SerializedName};

class EmailDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[Email(message: 'Please provide a valid email address')]
    #[Groups(['email:read', 'email:write', 'contact:form'])]
    #[SerializedName('email_address')]
    public string $email;
}
