<?php

namespace App\Console;

abstract class Command
{
    abstract public function getName(): string;
    
    public function getDescription(): string
    {
        return 'No description available';
    }
    
    public function getHelp(): string
    {
        return $this->getDescription();
    }

    public function execute(array $args = []): void
    {
        // Default implementation
    }

    protected function getArgument(string $name)
    {
        return $this->arguments[$name] ?? null;
    }

    /**
     * Display an error message
     */
    protected function error(string $message): void
    {
        echo "Error: " . $message . PHP_EOL;
    }

    protected function info(string $message): void
    {
        echo $message . PHP_EOL;
    }
}