<?php

declare(strict_types=1);

namespace Glueful\DI;

use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pure Symfony Container Compiler
 */
class ContainerCompiler
{
    private string $cacheDir;
    private string $containerClass = 'CompiledContainer';

    public function __construct(string $cacheDir = 'storage/container')
    {
        $this->cacheDir = $cacheDir;
    }

    public function compile(): Container
    {
        $builder = new ContainerBuilder();
        ContainerFactory::configureContainer($builder);
        $builder->compile();

        // Generate compiled container class
        $this->generateCompiledContainer($builder);

        // Load and return compiled container
        return $this->loadCompiledContainer();
    }

    public function isCompiled(): bool
    {
        return file_exists($this->getCompiledContainerPath());
    }

    public function clearCompiled(): void
    {
        $containerFile = $this->getCompiledContainerPath();
        if (file_exists($containerFile)) {
            unlink($containerFile);
        }
    }

    private function generateCompiledContainer(ContainerBuilder $builder): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $dumper = new PhpDumper($builder);
        $containerCode = $dumper->dump([
            'class' => $this->containerClass,
            'namespace' => 'Glueful\\DI\\Compiled',
            'base_class' => 'Symfony\\Component\\DependencyInjection\\Container',
        ]);

        file_put_contents($this->getCompiledContainerPath(), $containerCode);
    }

    private function loadCompiledContainer(): Container
    {
        require_once $this->getCompiledContainerPath();

        // Compiled container class is generated at runtime by Symfony PhpDumper
        /** @var class-string $containerClass */
        $containerClass = "Glueful\\DI\\Compiled\\{$this->containerClass}";
        /** @var \Symfony\Component\DependencyInjection\ContainerInterface $compiledContainer */
        $compiledContainer = new $containerClass();

        return new Container($compiledContainer);
    }

    private function getCompiledContainerPath(): string
    {
        return $this->cacheDir . '/' . $this->containerClass . '.php';
    }
}
