<?php

namespace App\Console\Commands;

use App\Console\Command;

class HelloCommand extends Command
{
    public function getName(): string
    {
        return 'hello';
    }

    public function execute()
    {
        echo "Hello from Glueful CLI!\n";
    }
}