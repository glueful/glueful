<?php
declare(strict_types=1);

namespace Glueful\Api\Library\Interfaces;

use Glueful\Api\Library\QueryAction;
use Glueful\Api\Http\Pagination;

interface QueryBuilderInterface
{
    public static function prepare(
        QueryAction $action,
        array $definition,
        ?array $params = null,
        ?array $config = null,
        ?Pagination $pagination = null
    ): array;
    
    public static function query(array $queryData): mixed;
    public static function fromString(string $value): QueryAction;
}
