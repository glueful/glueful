<?php

declare(strict_types=1);

namespace Glueful\Extensions\{{EXTENSION_NAME}}\Services;

/**
 * {{EXTENSION_NAME}} Service
 * 
 * Core service class for the {{EXTENSION_NAME}} extension
 */
class {{EXTENSION_NAME}}Service
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Process data for the extension
     */
    public function processData(array $data): array
    {
        // Add your business logic here
        
        return [
            'processed' => true,
            'data' => $data,
            'timestamp' => time()
        ];
    }

    /**
     * Validate input data
     */
    public function validateData(array $data): array
    {
        $errors = [];
        
        // Add validation logic here
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get service configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}