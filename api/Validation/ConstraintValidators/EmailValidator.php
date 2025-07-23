<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Email;

/**
 * Email validator
 *
 * Validates that a value is a valid email address.
 * Maps Glueful's Email constraint to validation logic.
 */
class EmailValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Email) {
            throw new UnexpectedTypeException($constraint, Email::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        // Must be a string
        if (!is_string($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
            return;
        }

        // Validate email format based on mode
        $isValid = match ($constraint->mode) {
            'strict' => $this->validateStrict($value),
            'html5-allow-no-tld' => $this->validateHtml5AllowNoTld($value),
            default => $this->validateHtml5($value), // 'html5' is default
        };

        if (!$isValid) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
        }
    }

    /**
     * Validate email with HTML5 rules
     *
     * @param string $email Email to validate
     * @return bool True if valid
     */
    private function validateHtml5(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate email with HTML5 rules allowing no TLD
     *
     * @param string $email Email to validate
     * @return bool True if valid
     */
    private function validateHtml5AllowNoTld(string $email): bool
    {
        // First try standard validation
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        // Allow emails without TLD (e.g., user@localhost)
        return preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+$/', $email) === 1;
    }

    /**
     * Validate email with strict rules
     *
     * @param string $email Email to validate
     * @return bool True if valid
     */
    private function validateStrict(string $email): bool
    {
        // Use PHP's filter with strict RFC compliance
        return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }
}
