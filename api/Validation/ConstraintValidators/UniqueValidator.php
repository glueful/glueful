<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Unique;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Unique validator
 *
 * Validates that a value is unique in the database table.
 * Supports ignoring specific records (useful for updates) and additional conditions.
 */
class UniqueValidator extends ConstraintValidator
{
    /** @var Connection Database connection */
    private Connection $connection;

    /**
     * Constructor
     *
     * @param Connection $connection Database connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Unique) {
            throw new UnexpectedTypeException($constraint, Unique::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        $column = $constraint->column ?: $this->context->getPropertyName();

        // Create fresh query builder instance
        $queryBuilder = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());

        // Build base query
        $query = $queryBuilder->select($constraint->table, ['COUNT(*) as count'])
            ->where([$column => $value]);

        // Add ignore condition if specified
        if ($constraint->ignoreId && $constraint->ignoreValue !== null) {
            $query->whereNotEqual($constraint->ignoreId, $constraint->ignoreValue);
        }

        // Add additional conditions
        if (!empty($constraint->conditions)) {
            $query->where($constraint->conditions);
        }

        try {
            $result = $query->get();
            $count = $result[0]['count'] ?? 0;

            if ($count > 0) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->setParameter('{{ value }}', (string) $value)
                    ->addViolation();
            }
        } catch (\Exception $e) {
            // Log database error but don't fail validation
            // In production, you might want to handle this differently
            error_log("Database validation error: " . $e->getMessage());
        }
    }
}
