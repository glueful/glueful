<?php

require_once __DIR__ . '/api/bootstrap.php';

use Glueful\Security\VulnerabilityScanner;

try {
    echo "Creating VulnerabilityScanner...\n";
    $scanner = new VulnerabilityScanner();
    
    echo "Running vulnerability scan...\n";
    $results = $scanner->scan(['code', 'dependency', 'config']);
    
    echo "Scan completed successfully!\n";
    echo "Scan ID: " . $results['scan_id'] . "\n";
    echo "Total vulnerabilities found: " . $results['summary']['total_vulnerabilities'] . "\n";
    
    // Check if file was created
    $scanDate = date('Y-m-d');
    $storageDir = __DIR__ . '/storage/vulnerabilities';
    $files = glob($storageDir . '/' . $scanDate . '_*.json');
    
    if (!empty($files)) {
        echo "Scan results saved to: " . basename($files[0]) . "\n";
        
        // Display a summary
        echo "\nVulnerability Summary:\n";
        echo "- Critical: " . $results['summary']['critical'] . "\n";
        echo "- High: " . $results['summary']['high'] . "\n";
        echo "- Medium: " . $results['summary']['medium'] . "\n";
        echo "- Low: " . $results['summary']['low'] . "\n";
    } else {
        echo "Warning: No scan files found in storage directory\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
