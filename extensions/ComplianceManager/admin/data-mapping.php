<?php
/**
 * ComplianceManager Data Mapping Tool
 */

// Get the configuration
$config = require __DIR__ . '/../config.php';

// In a real implementation, we would fetch the data mapping information from a database
// This is just demonstration data
$dataMappings = [
    [
        'source' => 'User Registration Form',
        'data_types' => ['Personal Information', 'Contact Details'],
        'storage' => 'Users Database',
        'purpose' => 'Account Management',
        'retention' => '3 years after account deletion',
        'sensitivity' => 'Medium',
        'regulations' => ['GDPR', 'CCPA']
    ],
    [
        'source' => 'Patient Intake Form',
        'data_types' => ['Personal Information', 'Health Information', 'Insurance Details'],
        'storage' => 'Patient Records Database',
        'purpose' => 'Healthcare Service Provision',
        'retention' => '7 years',
        'sensitivity' => 'High',
        'regulations' => ['GDPR', 'HIPAA']
    ],
    [
        'source' => 'Marketing Subscription',
        'data_types' => ['Email Address', 'Preferences'],
        'storage' => 'Marketing Database',
        'purpose' => 'Email Marketing',
        'retention' => 'Until consent withdrawn',
        'sensitivity' => 'Low',
        'regulations' => ['GDPR', 'CCPA']
    ],
    [
        'source' => 'Website Analytics',
        'data_types' => ['IP Address', 'Browser Information', 'Behavior Data'],
        'storage' => 'Analytics Platform',
        'purpose' => 'Website Improvement',
        'retention' => '14 months',
        'sensitivity' => 'Low',
        'regulations' => ['GDPR', 'CCPA']
    ],
    [
        'source' => 'Payment Processing',
        'data_types' => ['Payment Information', 'Billing Address'],
        'storage' => 'Payment Processor',
        'purpose' => 'Transaction Processing',
        'retention' => '7 years (financial records)',
        'sensitivity' => 'High',
        'regulations' => ['GDPR', 'CCPA', 'PCI DSS']
    ]
];

// Count data types by sensitivity
$sensitivityCounts = [
    'High' => 0,
    'Medium' => 0,
    'Low' => 0
];

foreach ($dataMappings as $mapping) {
    $sensitivityCounts[$mapping['sensitivity']]++;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Mapping Tool - Compliance Manager</title>
    <style>
        :root {
            --primary: #0066cc;
            --secondary: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
            background-color: #f5f7f9;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            margin: 0;
            color: var(--dark);
        }

        .data-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
        }
        
        .summary-card .summary-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .summary-card.high-sensitivity .summary-value {
            color: var(--danger);
        }
        
        .summary-card.medium-sensitivity .summary-value {
            color: var(--warning);
        }
        
        .summary-card.low-sensitivity .summary-value {
            color: var(--success);
        }
        
        .summary-card .summary-title {
            font-size: 0.9rem;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .data-mappings {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            overflow-x: auto;
        }
        
        .data-mappings h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .mapping-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .mapping-table th {
            background-color: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #eee;
        }
        
        .mapping-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .mapping-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 5px;
        }
        
        .tag-gdpr {
            background-color: #e3f2fd;
            color: #0277bd;
        }
        
        .tag-ccpa {
            background-color: #ffebee;
            color: #c62828;
        }
        
        .tag-hipaa {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .tag-other {
            background-color: #ede7f6;
            color: #5e35b1;
        }
        
        .sensitivity-high {
            color: var(--danger);
            font-weight: bold;
        }
        
        .sensitivity-medium {
            color: var(--warning);
            font-weight: bold;
        }
        
        .sensitivity-low {
            color: var(--success);
            font-weight: bold;
        }
        
        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Data Mapping Tool</h1>
            <div>
                <button class="btn btn-primary">Add New Data Mapping</button>
            </div>
        </div>
        
        <div class="data-summary">
            <div class="summary-card high-sensitivity">
                <div class="summary-title">High Sensitivity Data</div>
                <div class="summary-value"><?= $sensitivityCounts['High'] ?></div>
                <div>Data processes with high risk</div>
            </div>
            
            <div class="summary-card medium-sensitivity">
                <div class="summary-title">Medium Sensitivity Data</div>
                <div class="summary-value"><?= $sensitivityCounts['Medium'] ?></div>
                <div>Data processes with moderate risk</div>
            </div>
            
            <div class="summary-card low-sensitivity">
                <div class="summary-title">Low Sensitivity Data</div>
                <div class="summary-value"><?= $sensitivityCounts['Low'] ?></div>
                <div>Data processes with minimal risk</div>
            </div>
        </div>
        
        <div class="data-mappings">
            <h2>Data Flow Mappings</h2>
            <p>This table shows all data flows in your organization, including sources, types, storage locations, purposes, and applicable regulations.</p>
            
            <table class="mapping-table">
                <thead>
                    <tr>
                        <th>Data Source</th>
                        <th>Data Types</th>
                        <th>Storage Location</th>
                        <th>Purpose</th>
                        <th>Retention Period</th>
                        <th>Sensitivity</th>
                        <th>Regulations</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dataMappings as $mapping): ?>
                    <tr>
                        <td><?= htmlspecialchars($mapping['source']) ?></td>
                        <td>
                            <ul style="margin: 0; padding-left: 18px;">
                                <?php foreach ($mapping['data_types'] as $type): ?>
                                <li><?= htmlspecialchars($type) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td><?= htmlspecialchars($mapping['storage']) ?></td>
                        <td><?= htmlspecialchars($mapping['purpose']) ?></td>
                        <td><?= htmlspecialchars($mapping['retention']) ?></td>
                        <td>
                            <span class="sensitivity-<?= strtolower($mapping['sensitivity']) ?>">
                                <?= htmlspecialchars($mapping['sensitivity']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="tag-list">
                                <?php foreach ($mapping['regulations'] as $regulation): ?>
                                <span class="tag tag-<?= strtolower($regulation) ?>">
                                    <?= htmlspecialchars($regulation) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="actions">
            <button class="btn btn-outline">Export Data Map</button>
            <button class="btn btn-primary">Generate Data Protection Impact Assessment</button>
        </div>
    </div>
</body>
</html>
