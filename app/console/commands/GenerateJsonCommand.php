<?php

namespace App\Console\Commands;

use App\Console\Command;
use Glueful\Api\JsonGenerator;

class GenerateJsonCommand extends Command
{
    public function getName(): string
    {
        return 'generate:json';
    }

    public function getDescription(): string 
    {
        return 'Generate JSON definitions and API documentation. Format: database [tablename]';
    }

    public function getArguments(): array
    {
        return [
            'type' => [
                'description' => 'Type of generation (api-definitions|doc)',
                'required' => true,
                'values' => ['api-definitions', 'doc']
            ],
            'database' => [
                'description' => 'Database name',
                'required' => false
            ],
            'table' => [
                'description' => 'Table name (optional)',
                'required' => false
            ]
        ];
    }

    public function execute()
    {
        $generator = new JsonGenerator(true);
        
        if ($this->getArgument('type') === 'api-definitions') {
            $database = $this->getArgument('database');
            $table = $this->getArgument('table');
            
            if ($database && $table) {
                // Generate for specific table
                $generator->generate($database);
            } else if ($database) {
                // Generate for entire database
                $generator->generate($database);
            } else {
                // Generate for all databases
                $generator->generate();
            }
        } else if ($this->getArgument('type') === 'doc') {
            if (\config('app.docs_enabled')) {
                $generator->generateApiDocs();
            } else {
                $this->error('API documentation generation is disabled in configuration');
            }
        }
    }
}