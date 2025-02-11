<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

enum QueryAction {
    case SELECT;
    case INSERT;
    case UPDATE;
    case DELETE;
    case REPLACE;
    case COUNT;
    case SUM;

    public static function fromString(string $action): self 
    {
        return match($action) {
            'list' => self::SELECT,
            'save' => self::INSERT,
            'delete' => self::UPDATE,
            'replace' => self::REPLACE,
            'count' => self::COUNT,
            'sum' => self::SUM,
            default => throw new \InvalidArgumentException("Invalid query action: $action")
        };
    }
}

enum FilterOperator: string {
    case EQUALS = '=';
    case NOT_EQUALS = '!=';
    case GREATER_THAN = '>';
    case LESS_THAN = '<';
    case GREATER_EQUALS = '>=';
    case LESS_EQUALS = '<=';
    case LIKE = 'LIKE';
    case NOT_LIKE = 'NOT LIKE';
    case IN = 'IN';
    case NOT_IN = 'NOT IN';
    case BETWEEN = 'BETWEEN';
}
