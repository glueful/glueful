<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ValidatorBuilder;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Glueful\Validation\Validator;
use Glueful\Validation\SanitizationProcessor;
use Glueful\Validation\ExtensionConstraintRegistry;
use Glueful\Validation\ValidationExtensionLoader;
use Glueful\Validation\ConstraintCompiler;
use Glueful\Validation\LazyValidationProvider;
use Glueful\Validation\ConstraintValidators\UniqueValidator;
use Glueful\Validation\ConstraintValidators\ExistsValidator;
use Glueful\Database\Connection;
use Glueful\Cache\CacheStore;

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
     * Register validation services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register Symfony Validator
        $container->singleton(ValidatorInterface::class, function (ContainerInterface $container) {
            $validatorBuilder = new ValidatorBuilder();
            $validatorBuilder->enableAttributeMapping();

            // Create custom constraint validator factory that uses DI container
            $validatorFactory = new class ($container) extends ConstraintValidatorFactory {
                private ContainerInterface $container;

                public function __construct(ContainerInterface $container)
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

                    // Check if this is an extension constraint
                    $registry = $this->container->get(ExtensionConstraintRegistry::class);
                    if ($registry->isConstraintRegistered(get_class($constraint))) {
                        $validator = $registry->getValidator(get_class($constraint));
                        if ($validator) {
                            return $validator;
                        }
                    }

                    // Fall back to default factory for built-in validators
                    return parent::getInstance($constraint);
                }
            };

            $validatorBuilder->setConstraintValidatorFactory($validatorFactory);

            return $validatorBuilder->getValidator();
        });

        // Register Sanitization Processor
        $container->singleton(SanitizationProcessor::class, function () {
            return new SanitizationProcessor();
        });

        // Register Extension Constraint Registry
        $container->singleton(ExtensionConstraintRegistry::class, function (ContainerInterface $container) {
            return new ExtensionConstraintRegistry($container);
        });

        // Register Validation Extension Loader
        $container->singleton(ValidationExtensionLoader::class, function (ContainerInterface $container) {
            return new ValidationExtensionLoader(
                $container->get(ExtensionConstraintRegistry::class),
                $container
            );
        });

        // Register Constraint Compiler
        $container->singleton(ConstraintCompiler::class, function (ContainerInterface $container) {
            $config = config('validation', []);
            return new ConstraintCompiler(
                $container->get(CacheStore::class),
                $config
            );
        });

        // Register Lazy Validation Provider
        $container->singleton(LazyValidationProvider::class, function (ContainerInterface $container) {
            $config = config('validation', []);
            return new LazyValidationProvider(
                $container,
                $container->get(ConstraintCompiler::class),
                $config
            );
        });

        // Register Glueful Validator facade
        $container->singleton(Validator::class, function (ContainerInterface $container) {
            return new Validator(
                $container->get(ValidatorInterface::class),
                $container->get(SanitizationProcessor::class),
                $container->get(LazyValidationProvider::class)
            );
        });

        // Register database validators
        $container->singleton(UniqueValidator::class, function (ContainerInterface $container) {
            return new UniqueValidator(
                $container->get(Connection::class)
            );
        });

        $container->singleton(ExistsValidator::class, function (ContainerInterface $container) {
            return new ExistsValidator(
                $container->get(Connection::class)
            );
        });
    }

    /**
     * Boot validation services
     */
    public function boot(ContainerInterface $container): void
    {
        // Validator is ready to use after registration
        // No additional boot configuration needed
    }
}
