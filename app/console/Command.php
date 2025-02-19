<?php

namespace App\Console;

abstract class Command
{
    abstract public function getName(): string;
    protected function getArgument(string $name)
    {
        return $this->arguments[$name] ?? null;
    }
    /**
     * Display an error message
     */
    protected function error(string $message): void
    {
        echo "\033[31mError: {$message}\033[0m" . PHP_EOL;
    }

    abstract public function execute();
}