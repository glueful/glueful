<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

/**
 * Filter Operators Enumeration
 * 
 * Defines supported filter operators for database queries.
 * Used in query building to create standardized filter conditions.
 */
enum FilterOperator: string 
{
    /**
     * Equals operator (=)
     * Matches exact values
     */
    case EQUALS = 'eq';

    /**
     * Not equals operator (!=)
     * Matches values that are different
     */
    case NOT_EQUALS = 'neq';

    /**
     * Greater than operator (>)
     * Matches values strictly greater than
     */
    case GREATER_THAN = 'gt';

    /**
     * Less than operator (<)
     * Matches values strictly less than
     */
    case LESS_THAN = 'lt';

    /**
     * Greater than or equal operator (>=)
     * Matches values greater than or equal to
     */
    case GREATER_EQUALS = 'gte';

    /**
     * Less than or equal operator (<=)
     * Matches values less than or equal to
     */
    case LESS_EQUALS = 'lte';

    /**
     * LIKE operator
     * Matches pattern with wildcards
     */
    case LIKE = 'like';

    /**
     * NOT LIKE operator
     * Excludes pattern matches
     */
    case NOT_LIKE = 'nlike';

    /**
     * IN operator
     * Matches any value in a set
     */
    case IN = 'in';

    /**
     * NOT IN operator
     * Excludes values in a set
     */
    case NOT_IN = 'nin';
}
