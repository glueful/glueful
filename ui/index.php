<?php
require_once __DIR__ . '/../api/bootstrap.php';
$baseUrl = config('app.paths.api_base_url');
$appName = config('app.name');
$domain = config('app.paths.domain');
$dbEngine = config('database.engine');
$db = config('database.'.$dbEngine.'.db');

$data = [
    'appName' => $appName,
    'domain' => $domain,
    'apiBaseUrl' => $baseUrl,
    'dbEngine' => $dbEngine,
    'db' => $db,
];

$jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Set the filename to always be "config.json"
$filename = "env.json";

// Write JSON data to a file (this will overwrite any existing file)
file_put_contents($filename, $jsonData);

