<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Exists;
use Glueful\Database\Connection;

/**
 * Exists validator
 *
 * Validates that a value exists in the database table.
 * Supports additional where conditions for complex validation scenarios.
 */
class ExistsValidator extends ConstraintValidator
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Constructor
     *
     * @param Connection $connection Database connection
     */
    public function __construct(Connection $connection)
    {
        $this->db = $connection;
    }

    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Exists) {
            throw new UnexpectedTypeException($constraint, Exists::class);
        }

        // Allow null values if configured
        if ($value === null && $constraint->allowNull) {
            return;
        }

        // Fail validation if null is not allowed
        if ($value === null && !$constraint->allowNull) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ value }}', 'null')
                ->addViolation();
            return;
        }

        $column = $constraint->column ?: $this->context->getPropertyName();

        // Build query using fluent interface
        $query = $this->db->table($constraint->table)
            ->selectRaw('COUNT(*) as count')
            ->where($column, $value);

        // Add additional conditions
        if (!empty($constraint->conditions)) {
            foreach ($constraint->conditions as $condColumn => $condValue) {
                $query->where($condColumn, $condValue);
            }
        }

        try {
            $result = $query->first();
            $count = $result['count'] ?? 0;

            if ($count === 0) {
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
