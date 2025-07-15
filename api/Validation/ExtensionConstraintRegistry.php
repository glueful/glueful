<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Constraints\AbstractConstraint;
use Glueful\DI\Interfaces\ContainerInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;

/**
 * Extension Constraint Registry
 *
 * Manages registration and discovery of validation constraints from extensions.
 * Provides a centralized system for extension constraints to be integrated
 * with the main validation system.
 */
class ExtensionConstraintRegistry
{
    /** @var array<string, array> Registered extension constraints */
    private array $constraints = [];

    /** @var array<string, string> Constraint to validator mapping */
    private array $validatorMapping = [];

    /** @var array<string, array> Extension metadata */
    private array $extensionMetadata = [];

    /** @var ContainerInterface DI container for validator instantiation */
    private ContainerInterface $container;

    /**
     * Constructor
     *
     * @param ContainerInterface $container DI container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Register a constraint from an extension
     *
     * @param string $constraintClass Constraint class name
     * @param string $validatorClass Validator class name
     * @param string $extensionName Extension name
     * @throws \InvalidArgumentException If constraint is invalid
     */
    public function registerConstraint(
        string $constraintClass,
        string $validatorClass,
        string $extensionName
    ): void {
        // Validate constraint class
        if (!class_exists($constraintClass)) {
            throw new \InvalidArgumentException("Constraint class '{$constraintClass}' does not exist");
        }

        if (!is_subclass_of($constraintClass, AbstractConstraint::class)) {
            throw new \InvalidArgumentException(
                "Constraint class '{$constraintClass}' must extend AbstractConstraint"
            );
        }

        // Validate validator class
        if (!class_exists($validatorClass)) {
            throw new \InvalidArgumentException("Validator class '{$validatorClass}' does not exist");
        }

        if (!is_subclass_of($validatorClass, ConstraintValidatorInterface::class)) {
            throw new \InvalidArgumentException(
                "Validator class '{$validatorClass}' must implement ConstraintValidatorInterface"
            );
        }

        // Create constraint instance for validation
        $constraint = new $constraintClass();
        $constraint->validateConfiguration();

        // Register constraint
        $this->constraints[$constraintClass] = [
            'constraint' => $constraintClass,
            'validator' => $validatorClass,
            'extension' => $extensionName,
            'metadata' => $constraint->getMetadata(),
            'registered_at' => time(),
        ];

        $this->validatorMapping[$constraintClass] = $validatorClass;

        // Update extension metadata
        if (!isset($this->extensionMetadata[$extensionName])) {
            $this->extensionMetadata[$extensionName] = [
                'name' => $extensionName,
                'constraints' => [],
                'registered_at' => time(),
            ];
        }

        $this->extensionMetadata[$extensionName]['constraints'][] = $constraintClass;
    }

    /**
     * Auto-discover constraints from an extension directory
     *
     * @param string $extensionPath Path to extension directory
     * @param string $extensionName Extension name
     * @return int Number of constraints discovered
     */
    public function discoverConstraintsFromExtension(string $extensionPath, string $extensionName): int
    {
        $constraintPath = $extensionPath . '/src/Validation/Constraints';
        $validatorPath = $extensionPath . '/src/Validation/ConstraintValidators';

        if (!is_dir($constraintPath)) {
            return 0;
        }

        $discovered = 0;
        $constraintFiles = glob($constraintPath . '/*.php') ?: [];

        foreach ($constraintFiles as $constraintFile) {
            $className = pathinfo($constraintFile, PATHINFO_FILENAME);
            $constraintClass = $this->buildConstraintClassName($extensionName, $className);
            $validatorClass = $this->buildValidatorClassName($extensionName, $className);

            // Check if constraint class exists
            if (!class_exists($constraintClass)) {
                continue;
            }

            // Check if validator class exists
            $validatorFile = $validatorPath . '/' . $className . 'Validator.php';
            if (!file_exists($validatorFile) || !class_exists($validatorClass)) {
                continue;
            }

            try {
                $this->registerConstraint($constraintClass, $validatorClass, $extensionName);
                $discovered++;
            } catch (\Exception $e) {
                // Log error but continue discovery
                error_log("Failed to register constraint {$constraintClass}: " . $e->getMessage());
            }
        }

        return $discovered;
    }

    /**
     * Get constraint validator class
     *
     * @param string $constraintClass Constraint class name
     * @return string|null Validator class name or null if not found
     */
    public function getValidatorClass(string $constraintClass): ?string
    {
        return $this->validatorMapping[$constraintClass] ?? null;
    }

    /**
     * Get validator instance for constraint
     *
     * @param string $constraintClass Constraint class name
     * @return ConstraintValidatorInterface|null Validator instance or null if not found
     */
    public function getValidator(string $constraintClass): ?ConstraintValidatorInterface
    {
        $validatorClass = $this->getValidatorClass($constraintClass);

        if (!$validatorClass) {
            return null;
        }

        try {
            return $this->container->get($validatorClass);
        } catch (\Exception $e) {
            // If not registered in container, try to instantiate directly
            if (class_exists($validatorClass)) {
                return new $validatorClass();
            }
            return null;
        }
    }

    /**
     * Get all registered constraints
     *
     * @return array<string, array> Registered constraints
     */
    public function getRegisteredConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * Get constraints by extension
     *
     * @param string $extensionName Extension name
     * @return array<string, array> Constraints for the extension
     */
    public function getConstraintsByExtension(string $extensionName): array
    {
        return array_filter($this->constraints, fn($constraint) =>
            $constraint['extension'] === $extensionName);
    }

    /**
     * Get extension metadata
     *
     * @param string $extensionName Extension name
     * @return array|null Extension metadata or null if not found
     */
    public function getExtensionMetadata(string $extensionName): ?array
    {
        return $this->extensionMetadata[$extensionName] ?? null;
    }

    /**
     * Get all extension metadata
     *
     * @return array<string, array> All extension metadata
     */
    public function getAllExtensionMetadata(): array
    {
        return $this->extensionMetadata;
    }

    /**
     * Check if constraint is registered
     *
     * @param string $constraintClass Constraint class name
     * @return bool True if constraint is registered
     */
    public function isConstraintRegistered(string $constraintClass): bool
    {
        return isset($this->constraints[$constraintClass]);
    }

    /**
     * Unregister constraint
     *
     * @param string $constraintClass Constraint class name
     * @return bool True if constraint was unregistered
     */
    public function unregisterConstraint(string $constraintClass): bool
    {
        if (!$this->isConstraintRegistered($constraintClass)) {
            return false;
        }

        $constraint = $this->constraints[$constraintClass];
        $extensionName = $constraint['extension'];

        // Remove from constraints
        unset($this->constraints[$constraintClass]);
        unset($this->validatorMapping[$constraintClass]);

        // Update extension metadata
        if (isset($this->extensionMetadata[$extensionName])) {
            $this->extensionMetadata[$extensionName]['constraints'] = array_filter(
                $this->extensionMetadata[$extensionName]['constraints'],
                fn($c) => $c !== $constraintClass
            );

            // Remove extension metadata if no constraints left
            if (empty($this->extensionMetadata[$extensionName]['constraints'])) {
                unset($this->extensionMetadata[$extensionName]);
            }
        }

        return true;
    }

    /**
     * Clear all registered constraints
     */
    public function clear(): void
    {
        $this->constraints = [];
        $this->validatorMapping = [];
        $this->extensionMetadata = [];
    }

    /**
     * Get constraint statistics
     *
     * @return array Constraint statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_constraints' => count($this->constraints),
            'total_extensions' => count($this->extensionMetadata),
            'constraints_by_extension' => array_map(
                fn($metadata) => count($metadata['constraints']),
                $this->extensionMetadata
            ),
            'constraint_types' => $this->getConstraintTypes(),
        ];
    }

    /**
     * Build constraint class name
     *
     * @param string $extensionName Extension name
     * @param string $className Constraint class name
     * @return string Full constraint class name
     */
    private function buildConstraintClassName(string $extensionName, string $className): string
    {
        return "Glueful\\Extensions\\{$extensionName}\\Validation\\Constraints\\{$className}";
    }

    /**
     * Build validator class name
     *
     * @param string $extensionName Extension name
     * @param string $className Constraint class name
     * @return string Full validator class name
     */
    private function buildValidatorClassName(string $extensionName, string $className): string
    {
        return "Glueful\\Extensions\\{$extensionName}\\Validation\\ConstraintValidators\\{$className}Validator";
    }

    /**
     * Register constraints from a directory path
     *
     * @param string $constraintPath Path to constraints directory
     * @param string $extensionName Extension name
     * @return int Number of constraints registered
     */
    public function registerConstraintsFromPath(string $constraintPath, string $extensionName): int
    {
        if (!is_dir($constraintPath)) {
            return 0;
        }

        return $this->discoverConstraintsFromExtension(
            dirname($constraintPath, 2), // Go up 2 levels to get extension root
            $extensionName
        );
    }

    /**
     * Get constraint types distribution
     *
     * @return array<string, int> Constraint types
     */
    private function getConstraintTypes(): array
    {
        $types = [];

        foreach ($this->constraints as $constraint) {
            $constraintClass = $constraint['constraint'];
            $instance = new $constraintClass();
            $type = $instance->getType();
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        return $types;
    }
}
