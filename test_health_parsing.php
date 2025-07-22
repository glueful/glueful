<?php

require_once 'vendor/autoload.php';
require_once 'api/CommentsDocGenerator.php';

use Glueful\CommentsDocGenerator;

// Test the health check schema parsing
$testSchema = '{
    status:string="Overall health status (ok|warning|error)",
    timestamp:string="ISO timestamp of check",
    version:string="Application version",
    environment:string="Application environment",
    checks:{
        database:{
            status:string="Database status",
            message:string="Database status message",
            driver:string="Database driver name",
            migrations_applied:integer="Number of applied migrations"
        },
        cache:{
            status:string="Cache status",
            message:string="Cache status message",
            driver:string="Cache driver name"
        },
        extensions:{
            status:string="Extensions status",
            message:string="Extensions status message",
            loaded:array="List of loaded extensions"
        },
        config:{
            status:string="Configuration status",
            message:string="Configuration status message",
            environment:string="Application environment"
        }
    }
}';

$generator = new CommentsDocGenerator();

// Use reflection to access the private method
$reflection = new ReflectionClass($generator);
$method = $reflection->getMethod('parseSimplifiedSchema');
$method->setAccessible(true);

$result = $method->invoke($generator, $testSchema);

echo "Parsed schema:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

// Check if all nested objects are present
$checks = $result['properties']['checks']['properties'] ?? [];
$expectedChecks = ['database', 'cache', 'extensions', 'config'];
$missingChecks = array_diff($expectedChecks, array_keys($checks));

if (!empty($missingChecks)) {
    echo "\nMissing checks: " . implode(', ', $missingChecks) . "\n";
} else {
    echo "\nAll checks parsed correctly!\n";
}