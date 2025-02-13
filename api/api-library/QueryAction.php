<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

enum QueryAction: string {
    case SELECT = 'select';
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case CUSTOM = 'custom';

    public static function fromString(string $value): self {
        return match (strtolower($value)) {
            'list', 'view', 'select' => self::SELECT,
            'insert', 'create' => self::INSERT,
            'update', 'edit' => self::UPDATE,
            'delete', 'remove' => self::DELETE,
            'custom' => self::CUSTOM,
            default => throw new \ValueError("Invalid query action: {$value}")
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
