<?php

/**
 * Default configuration for ComplianceManager extension
 */

declare(strict_types=1);

return [
    // General configuration
    'logging_enabled' => true,
    'log_level' => 'info', // debug, info, warning, error
    'storage_retention_days' => 90,

    // Data classification settings
    'data_classification' => [
        'scan_attachments' => true,
        'max_scan_size_mb' => 10,
        'pii_scan_threshold' => 0.75, // Confidence threshold for PII detection
        'phi_scan_threshold' => 0.85, // Confidence threshold for PHI detection
    ],

    // GDPR specific settings
    'gdpr' => [
        'enabled' => true,
        'response_time_days' => 30, // Days to respond to GDPR requests
        'deletion_retention_days' => 7, // Days to keep data after deletion request processing
        'consent_templates' => [
            'marketing' => 'I consent to receiving marketing communications about products and services.',
            'analytics' => 'I consent to the use of my data for analytics and service improvement.',
            'third_party' => 'I consent to sharing my information with trusted third parties.',
        ],
    ],

    // CCPA specific settings
    'ccpa' => [
        'enabled' => true,
        'response_time_days' => 45, // Days to respond to CCPA requests
        'verification_required' => true,
        'verification_methods' => ['email', 'phone', 'identity_document'],
        'do_not_sell_duration_days' => 365, // How long do-not-sell preferences are valid
    ],

    // HIPAA specific settings
    'hipaa' => [
        'enabled' => true,
        'phi_access_logging' => true,
        'baa_expiry_warning_days' => 30,
        'minimum_necessary_enforcement' => true,
        'permitted_purposes' => [
            'treatment',
            'payment',
            'healthcare_operations',
            'required_by_law'
        ],
        'audit_frequency_days' => 30,
    ],

    // UI Settings
    'ui' => [
        'dashboard_enabled' => true,
        'admin_route' => 'admin/compliance-manager',
        'theme' => 'light',
        'default_reports' => [
            'gdpr_compliance_status',
            'ccpa_disclosure_report',
            'hipaa_access_audit'
        ]
    ],
];
