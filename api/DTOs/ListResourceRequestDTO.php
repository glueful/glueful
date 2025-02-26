<?php
declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\{Rules, Sanitize};

class ListResourceRequestDTO {
    #[Sanitize(['trim', 'sanitize_string'])]
    #[Rules(['string', 'in:name,created_at'])]
    public ?string $fields = '*';

    #[Sanitize(['trim', 'sanitize_string'])]
    #[Rules(['string', 'in:name,created_at'])]
    public ?string $sort = 'created_at';

    #[Sanitize(['intval'])]
    #[Rules(['int', 'min:1'])]
    public ?int $page = 1;

    #[Sanitize(['intval'])]
    #[Rules(['int', 'min:1', 'max:100'])]
    public ?int $per_page = 25;

    #[Sanitize(['trim', 'sanitize_string'])]
    #[Rules(['string', 'in:asc,desc'])]
    public ?string $order = 'desc';
}