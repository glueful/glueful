<?php
/**
 * ComplianceManager Admin Dashboard
 */

// Get the configuration
$config = require __DIR__ . '/../config.php';

// Status checks for different compliance frameworks
$gdprEnabled = $config['gdpr']['enabled'] ?? false;
$ccpaEnabled = $config['ccpa']['enabled'] ?? false;
$hipaaEnabled = $config['hipaa']['enabled'] ?? false;

// Default stats
$stats = [
    'data_classified' => 0,
    'subject_requests' => 0,
    'consumer_requests' => 0,
    'phi_access_checks' => 0,
    'security_incidents' => 0,
    'expiring_baas' => 0
];

// In a real implementation, we would fetch these stats from the database
// This is just demonstration code
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Manager Dashboard</title>
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
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .dashboard-title {
            margin: 0;
            color: var(--dark);
        }
        
        .compliance-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .status-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .status-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            margin-right: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-active {
            background-color: #dff2e3;
            color: var(--success);
        }
        
        .status-inactive {
            background-color: #f2dddd;
            color: var(--danger);
        }
        
        .status-warning {
            background-color: #fff3cd;
            color: var(--warning);
        }
        
        .status-info {
            background-color: #e3f6f9;
            color: var(--info);
        }
        
        .metrics-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .metric-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
        }
        
        .metric-card .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .metric-card .metric-title {
            font-size: 0.9rem;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .action-panel {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-top: 30px;
            padding: 20px;
        }
        
        .action-panel h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .action-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-link {
            display: flex;
            align-items: center;
            padding: 10px;
            background-color: var(--light);
            border-radius: 4px;
            text-decoration: none;
            color: var(--dark);
            transition: background-color 0.2s ease;
        }
        
        .action-link:hover {
            background-color: #e9ecef;
        }
        
        .action-icon {
            width: 36px;
            height: 36px;
            background-color: #e3f2fd;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            color: var(--primary);
        }
        
        .alerts {
            margin-top: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-icon {
            margin-right: 10px;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Compliance Manager Dashboard</h1>
            <div>
                Last updated: <?= date('Y-m-d H:i:s') ?>
            </div>
        </div>
        
        <div class="compliance-status">
            <div class="status-card">
                <h3>
                    <span class="status-icon <?= $gdprEnabled ? 'status-active' : 'status-inactive' ?>">
                        <?= $gdprEnabled ? '✓' : '✗' ?>
                    </span>
                    GDPR Compliance
                </h3>
                <p>The General Data Protection Regulation toolkit <?= $gdprEnabled ? 'is active' : 'is not active' ?>.</p>
                <div>
                    <?php if ($gdprEnabled): ?>
                        <div>Response time setting: <?= $config['gdpr']['response_time_days'] ?> days</div>
                        <div>Data retention after deletion: <?= $config['gdpr']['deletion_retention_days'] ?> days</div>
                    <?php else: ?>
                        <div>Enable GDPR compliance in config.php</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="status-card">
                <h3>
                    <span class="status-icon <?= $ccpaEnabled ? 'status-active' : 'status-inactive' ?>">
                        <?= $ccpaEnabled ? '✓' : '✗' ?>
                    </span>
                    CCPA Compliance
                </h3>
                <p>The California Consumer Privacy Act toolkit <?= $ccpaEnabled ? 'is active' : 'is not active' ?>.</p>
                <div>
                    <?php if ($ccpaEnabled): ?>
                        <div>Response time setting: <?= $config['ccpa']['response_time_days'] ?> days</div>
                        <div>Verification required: <?= $config['ccpa']['verification_required'] ? 'Yes' : 'No' ?></div>
                    <?php else: ?>
                        <div>Enable CCPA compliance in config.php</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="status-card">
                <h3>
                    <span class="status-icon <?= $hipaaEnabled ? 'status-active' : 'status-inactive' ?>">
                        <?= $hipaaEnabled ? '✓' : '✗' ?>
                    </span>
                    HIPAA Compliance
                </h3>
                <p>The Health Insurance Portability and Accountability Act toolkit <?= $hipaaEnabled ? 'is active' : 'is not active' ?>.</p>
                <div>
                    <?php if ($hipaaEnabled): ?>
                        <div>PHI access logging: <?= $config['hipaa']['phi_access_logging'] ? 'Enabled' : 'Disabled' ?></div>
                        <div>BAA expiry warning: <?= $config['hipaa']['baa_expiry_warning_days'] ?> days</div>
                    <?php else: ?>
                        <div>Enable HIPAA compliance in config.php</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="metrics-row">
            <div class="metric-card">
                <div class="metric-title">Data Classifications</div>
                <div class="metric-value"><?= $stats['data_classified'] ?></div>
                <div>Classification operations</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-title">Subject Requests</div>
                <div class="metric-value"><?= $stats['subject_requests'] ?></div>
                <div>GDPR data subject requests</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-title">Consumer Requests</div>
                <div class="metric-value"><?= $stats['consumer_requests'] ?></div>
                <div>CCPA consumer requests</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-title">PHI Access</div>
                <div class="metric-value"><?= $stats['phi_access_checks'] ?></div>
                <div>HIPAA access validations</div>
            </div>
        </div>
        
        <div class="action-panel">
            <h3>Quick Actions</h3>
            <div class="action-links">
                <a href="#" class="action-link">
                    <div class="action-icon">📊</div>
                    <div>Generate Compliance Report</div>
                </a>
                <a href="#" class="action-link">
                    <div class="action-icon">👤</div>
                    <div>Process Data Subject Request</div>
                </a>
                <a href="#" class="action-link">
                    <div class="action-icon">🔍</div>
                    <div>Run Data Classification Scan</div>
                </a>
                <a href="#" class="action-link">
                    <div class="action-icon">📝</div>
                    <div>Manage Consent Records</div>
                </a>
                <a href="#" class="action-link">
                    <div class="action-icon">🔒</div>
                    <div>Security Incident Report</div>
                </a>
                <a href="#" class="action-link">
                    <div class="action-icon">⚙️</div>
                    <div>Configure Compliance Settings</div>
                </a>
            </div>
        </div>
        
        <div class="alerts">
            <div class="alert alert-warning">
                <span class="alert-icon">⚠️</span>
                <div>
                    <strong>Attention Required:</strong> You have <?= $stats['expiring_baas'] ?> Business Associate Agreements expiring within the next 30 days.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
