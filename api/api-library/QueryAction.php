<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

/**
 * Query Action Types
 * 
 * Defines the standard database operation types supported by the API.
 * Used for consistent query action handling across the application.
 */
enum QueryAction: string 
{
    /** Standard SELECT operation for retrieving records */
    case SELECT = 'select';
    
    /** INSERT operation for creating new records */
    case INSERT = 'insert';
    
    /** UPDATE operation for modifying existing records */
    case UPDATE = 'update';
    
    /** DELETE operation for removing records */
    case DELETE = 'delete';
    
    /** Custom operations for special cases */
    case CUSTOM = 'custom';

    /**
     * Convert string action to QueryAction enum
     * 
     * Maps common action names to their corresponding enum values.
     * 
     * @param string $value Action name to convert
     * @return self Corresponding QueryAction enum value
     * @throws \ValueError If action name is invalid
     */
    public static function fromString(string $value): self 
    {
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

/**
 * Filter Operators
 * 
 * Defines supported SQL comparison operators for query filtering.
 * Used in building WHERE clauses for database queries.
 */
enum FilterOperator: string 
{
    /** Exact match operator (=) */
    case EQUALS = '=';
    
    /** Non-match operator (!=) */
    case NOT_EQUALS = '!=';
    
    /** Greater than operator (>) */
    case GREATER_THAN = '>';
    
    /** Less than operator (<) */
    case LESS_THAN = '<';
    
    /** Greater than or equal operator (>=) */
    case GREATER_EQUALS = '>=';
    
    /** Less than or equal operator (<=) */
    case LESS_EQUALS = '<=';
    
    /** Pattern matching operator (LIKE) */
    case LIKE = 'LIKE';
    
    /** Pattern exclusion operator (NOT LIKE) */
    case NOT_LIKE = 'NOT LIKE';
    
    /** Set membership operator (IN) */
    case IN = 'IN';
    
    /** Set exclusion operator (NOT IN) */
    case NOT_IN = 'NOT IN';
    
    /** Range operator (BETWEEN) */
    case BETWEEN = 'BETWEEN';
}
