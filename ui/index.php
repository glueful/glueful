<?php
require_once __DIR__ . '/../api/bootstrap.php';
$baseUrl = config('paths.api_base_url');
$appName = config('app.name');
$domain = config('paths.domain');

$data = [
    'appName' => $appName,
    'domain' => $domain,
    'apiBaseUrl' => $baseUrl,
];

$jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Set the filename to always be "config.json"
$filename = "env.json";

// Write JSON data to a file (this will overwrite any existing file)
file_put_contents($filename, $jsonData);

