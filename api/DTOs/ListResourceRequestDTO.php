<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Choice, Range};

class ListResourceRequestDTO
{
    #[Sanitize(['trim', 'sanitize_string'])]
    #[Choice(choices: ['name', 'created_at', '*'], message: 'Invalid field selection')]
    public ?string $fields = '*';

    #[Sanitize(['trim', 'sanitize_string'])]
    #[Choice(choices: ['name', 'created_at'], message: 'Invalid sort field')]
    public ?string $sort = 'created_at';

    #[Sanitize(['intval'])]
    #[Range(min: 1, minMessage: 'Page must be at least 1')]
    public ?int $page = 1;

    #[Sanitize(['intval'])]
    #[Range(min: 1, max: 100, notInRangeMessage: 'Per page must be between 1 and 100')]
    public ?int $per_page = 25;

    #[Sanitize(['trim', 'sanitize_string'])]
    #[Choice(choices: ['asc', 'desc'], message: 'Order must be asc or desc')]
    public ?string $order = 'desc';
}
