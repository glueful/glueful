<?php

require_once 'vendor/autoload.php';
require_once 'api/CommentsDocGenerator.php';

// Simulate the parseSimplifiedSchema method with debug output
function debugParseSimplifiedSchema($schemaStr) {
    // Clean up the schema string - remove comment markers and normalize whitespace
    $schemaStr = preg_replace('/\*\s*/', ' ', $schemaStr);
    $schemaStr = preg_replace('/\s+/', ' ', $schemaStr);
    $schemaStr = trim($schemaStr, '{} ');
    
    echo "Cleaned schema string:\n$schemaStr\n\n";

    $parts = [];
    $start = 0;
    $braceCount = 0;
    $bracketCount = 0;
    $inQuotes = false;

    // Split on commas, but respect nested objects, arrays, and quoted strings
    for ($i = 0; $i < strlen($schemaStr); $i++) {
        $char = $schemaStr[$i];

        if ($char === '"' && ($i === 0 || $schemaStr[$i - 1] !== '\\')) {
            $inQuotes = !$inQuotes;
        } elseif (!$inQuotes) {
            if ($char === '{') {
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
            } elseif ($char === '[') {
                $bracketCount++;
            } elseif ($char === ']') {
                $bracketCount--;
            } elseif ($char === ',' && $braceCount === 0 && $bracketCount === 0) {
                $parts[] = substr($schemaStr, $start, $i - $start);
                $start = $i + 1;
            }
        }
    }

    // Add the last part
    if ($start < strlen($schemaStr)) {
        $parts[] = substr($schemaStr, $start);
    }
    
    echo "Parts after splitting:\n";
    foreach ($parts as $idx => $part) {
        echo "Part $idx: " . trim($part) . "\n";
    }
}

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

debugParseSimplifiedSchema($testSchema);