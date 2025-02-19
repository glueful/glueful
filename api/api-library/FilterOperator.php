<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

enum FilterOperator: string {
    case EQUALS = 'eq';
    case NOT_EQUALS = 'neq';
    case GREATER_THAN = 'gt';
    case LESS_THAN = 'lt';
    case GREATER_EQUALS = 'gte';
    case LESS_EQUALS = 'lte';
    case LIKE = 'like';
    case NOT_LIKE = 'nlike';
    case IN = 'in';
    case NOT_IN = 'nin';
}
