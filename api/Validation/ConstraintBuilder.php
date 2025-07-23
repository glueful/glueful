<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Constraints\{Required, StringLength, Email, Range, Choice};
use Glueful\Validation\ConditionalBuilder;
use Glueful\Validation\CollectionBuilder;

/**
 * Constraint Builder
 *
 * Provides a fluent interface for building complex validation constraints.
 * Useful for dynamic validation scenarios and complex business rules.
 *
 * Example usage:
 *
 * ```php
 * use Glueful\Validation\ConstraintBuilder;
 *
 * class ProductDTO {
 *     #[ConstraintBuilder::when('type', 'digital')->then(['required'])]
 *     public ?string $downloadUrl;
 *
 *     #[ConstraintBuilder::collection([
 *         'name' => ['required', 'string'],
 *         'price' => ['required', 'numeric', 'min' => 0]
 *     ])]
 *     public array $variants;
 * }
 * ```
 */
class ConstraintBuilder
{
    /** @var array<string, mixed> Constraint configuration */
    private array $config = [];

    /** @var array<string> Current validation groups */
    private array $groups = [];

    /**
     * Create a new constraint builder instance
     */
    public function __construct()
    {
        $this->config = [];
        $this->groups = [];
    }

    /**
     * Add Required constraint
     *
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function required(?string $message = null, array $groups = []): self
    {
        $this->config['required'] = [
            'message' => $message,
            'groups' => $this->combineGroups($groups),
        ];
        return $this;
    }

    /**
     * Add StringLength constraint
     *
     * @param int|null $min Minimum length
     * @param int|null $max Maximum length
     * @param int|null $exact Exact length
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function stringLength(?int $min = null, ?int $max = null, ?int $exact = null, array $groups = []): self
    {
        $this->config['string_length'] = [
            'min' => $min,
            'max' => $max,
            'exact' => $exact,
            'groups' => $this->combineGroups($groups),
        ];
        return $this;
    }

    /**
     * Add Email constraint
     *
     * @param string $mode Validation mode
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function email(string $mode = 'html5', ?string $message = null, array $groups = []): self
    {
        $this->config['email'] = [
            'mode' => $mode,
            'message' => $message,
            'groups' => $this->combineGroups($groups),
        ];
        return $this;
    }

    /**
     * Add Range constraint
     *
     * @param int|float|null $min Minimum value
     * @param int|float|null $max Maximum value
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function range(int|float|null $min = null, int|float|null $max = null, array $groups = []): self
    {
        $this->config['range'] = [
            'min' => $min,
            'max' => $max,
            'groups' => $this->combineGroups($groups),
        ];
        return $this;
    }

    /**
     * Add Choice constraint
     *
     * @param array $choices Available choices
     * @param bool $multiple Allow multiple selections
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function choice(array $choices, bool $multiple = false, array $groups = []): self
    {
        $this->config['choice'] = [
            'choices' => $choices,
            'multiple' => $multiple,
            'groups' => $this->combineGroups($groups),
        ];
        return $this;
    }

    /**
     * Set validation groups for all constraints
     *
     * @param array<string> $groups Validation groups
     * @return self
     */
    public function groups(array $groups): self
    {
        $this->groups = $groups;
        return $this;
    }

    /**
     * Add validation groups to existing groups
     *
     * @param array<string> $groups Additional validation groups
     * @return self
     */
    public function addGroups(array $groups): self
    {
        $this->groups = array_unique(array_merge($this->groups, $groups));
        return $this;
    }

    /**
     * Build constraint instances
     *
     * @return array Array of constraint instances
     */
    public function build(): array
    {
        $constraints = [];

        foreach ($this->config as $type => $config) {
            $constraint = match ($type) {
                'required' => new Required(
                    message: $config['message'],
                    groups: $config['groups']
                ),
                'string_length' => new StringLength(
                    min: $config['min'],
                    max: $config['max'],
                    exact: $config['exact'],
                    groups: $config['groups']
                ),
                'email' => new Email(
                    message: $config['message'],
                    mode: $config['mode'],
                    groups: $config['groups']
                ),
                'range' => new Range(
                    min: $config['min'],
                    max: $config['max'],
                    groups: $config['groups']
                ),
                'choice' => new Choice(
                    choices: $config['choices'],
                    multiple: $config['multiple'],
                    groups: $config['groups']
                ),
                default => null,
            };

            if ($constraint !== null) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Create a conditional constraint builder
     *
     * @param string $field Field to check
     * @param mixed $value Value to compare against
     * @return ConditionalBuilder
     */
    public static function when(string $field, mixed $value): ConditionalBuilder
    {
        return new ConditionalBuilder($field, $value);
    }

    /**
     * Create a collection constraint builder
     *
     * @param array $fields Field constraints
     * @return CollectionBuilder
     */
    public static function collection(array $fields): CollectionBuilder
    {
        return new CollectionBuilder($fields);
    }

    /**
     * Create a new constraint builder
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Combine groups with default groups
     *
     * @param array<string> $groups Additional groups
     * @return array<string> Combined groups
     */
    private function combineGroups(array $groups): array
    {
        return array_unique(array_merge($this->groups, $groups));
    }
}
