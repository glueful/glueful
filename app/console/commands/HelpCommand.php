<?php

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Application;

class HelpCommand extends Command
{
    public function getName(): string
    {
        return 'help';
    }

    public function execute()
    {
        echo "Available commands:\n";

        // Get all registered commands from Application
        $app = new Application();
        foreach ($app->getCommands() as $cmd) {
            echo "  - " . $cmd->getName() . "\n";
        }
    }
}