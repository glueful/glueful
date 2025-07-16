<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ValidatorBuilder;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Glueful\Validation\Validator;
use Glueful\Validation\SanitizationProcessor;
use Glueful\Validation\ConstraintCompiler;
use Glueful\Validation\LazyValidationProvider;
use Glueful\Validation\ConstraintValidators\UniqueValidator;
use Glueful\Validation\ConstraintValidators\ExistsValidator;

/**
 * Validator Service Provider
 *
 * Registers Symfony Validator with the dependency injection container.
 * Configures the validator with attribute mapping support for modern
 * PHP 8+ constraint attributes.
 *
 * @package Glueful\DI\ServiceProviders
 */
class ValidatorServiceProvider implements ServiceProviderInterface
{
    /**
     * Register validation services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony Validator with factory method
        $container->register(ValidatorInterface::class)
            ->setFactory([$this, 'createValidator'])
            ->setArguments([new Reference('service_container')])
            ->setPublic(true)
            ->addTag(ServiceTags::VALIDATION_CONSTRAINT);

        // Register Sanitization Processor
        $container->register(SanitizationProcessor::class)
            ->setPublic(true);

        // Register Constraint Compiler
        $container->register(ConstraintCompiler::class)
            ->setFactory([$this, 'createConstraintCompiler'])
            ->setArguments([
                new Reference('cache.store'),
                '%validation.config%'
            ])
            ->setPublic(true);

        // Register Lazy Validation Provider
        $container->register(LazyValidationProvider::class)
            ->setFactory([$this, 'createLazyValidationProvider'])
            ->setArguments([
                new Reference('service_container'),
                new Reference(ConstraintCompiler::class),
                '%validation.config%'
            ])
            ->setPublic(true);

        // Register Glueful Validator facade
        $container->register(Validator::class)
            ->setArguments([
                new Reference(ValidatorInterface::class),
                new Reference(SanitizationProcessor::class),
                new Reference(LazyValidationProvider::class)
            ])
            ->setPublic(true);

        // Register database validators
        $container->register(UniqueValidator::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true)
            ->addTag(ServiceTags::VALIDATION_RULE, ['rule_name' => 'unique']);

        $container->register(ExistsValidator::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true)
            ->addTag(ServiceTags::VALIDATION_RULE, ['rule_name' => 'exists']);
    }

    /**
     * Boot validation services after container is built
     */
    public function boot(Container $container): void
    {
        // Validator is ready to use after registration
        // No additional boot configuration needed
    }

    /**
     * Get compiler passes for validation services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Validation rules will be processed by TaggedServicePass
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'validator';
    }

    /**
     * Factory method for creating Symfony Validator
     */
    public static function createValidator($container): ValidatorInterface
    {
        $validatorBuilder = new ValidatorBuilder();
        $validatorBuilder->enableAttributeMapping();

        // Create custom constraint validator factory that uses DI container
        $validatorFactory = new class ($container) extends ConstraintValidatorFactory {
            private $container;

            public function __construct($container)
            {
                $this->container = $container;
            }

            public function getInstance(Constraint $constraint): ConstraintValidatorInterface
            {
                $className = $constraint->validatedBy();

                // Check if this is one of our custom validators
                if ($className === UniqueValidator::class || $className === ExistsValidator::class) {
                    return $this->container->get($className);
                }

                // Fall back to default factory for built-in validators
                return parent::getInstance($constraint);
            }
        };

        $validatorBuilder->setConstraintValidatorFactory($validatorFactory);

        return $validatorBuilder->getValidator();
    }

    /**
     * Factory method for creating ConstraintCompiler
     */
    public static function createConstraintCompiler($cacheStore, $config): ConstraintCompiler
    {
        return new ConstraintCompiler($cacheStore, $config);
    }

    /**
     * Factory method for creating LazyValidationProvider
     */
    public static function createLazyValidationProvider(
        $container,
        ConstraintCompiler $compiler,
        $config
    ): LazyValidationProvider {
        return new LazyValidationProvider($container, $compiler, $config);
    }
}
