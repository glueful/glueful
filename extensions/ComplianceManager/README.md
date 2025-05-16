# ComplianceManager Extension for Glueful

The ComplianceManager extension provides organizations with comprehensive tools to meet regulatory requirements across multiple privacy and security frameworks including GDPR, CCPA, and HIPAA.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Core Components](#core-components)
- [GDPR Toolkit](#gdpr-toolkit)
- [CCPA Toolkit](#ccpa-toolkit)
- [HIPAA Toolkit](#hipaa-toolkit)
- [API Reference](#api-reference)
- [UI Components](#ui-components)
- [License](#license)
- [Support](#support)

## Installation

1. Copy the extension files to your `extensions/ComplianceManager` directory
2. Enable the extension in `config/extensions.php`:

```php
return [
    'enabled' => [
        // other extensions...
        'ComplianceManager',
    ],
];
```

3. Run migrations to create the necessary database tables:

```bash
php glueful db:migrate
```

## Configuration

Configure the extension by editing `extensions/ComplianceManager/config.php`:

```php
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
    ],
    
    // CCPA specific settings
    'ccpa' => [
        'enabled' => true,
        'response_time_days' => 45, // Days to respond to CCPA requests
        'verification_required' => true,
    ],
    
    // HIPAA specific settings
    'hipaa' => [
        'enabled' => true,
        'phi_access_logging' => true,
        'baa_expiry_warning_days' => 30,
    ],
];
```

## Core Components

### Data Classification System

The Data Classification System automatically detects, classifies, and tags sensitive information in your application.

```php
// Classify data with the DataClassifier
$dataClassifier = new \Glueful\ComplianceManager\DataClassifier();

// Example data to classify
$userData = [
    'name' => 'John Doe',
    'email' => 'john.doe@example.com',
    'ssn' => '123-45-6789',
    'notes' => 'Patient has history of hypertension'
];

// Classify the data
$classifications = $dataClassifier->classifyData($userData, 'user_profile');

// Get tagged data with sensitivity metadata
$taggedData = $dataClassifier->tagData($userData, $classifications);

// Check sensitivity level
if ($taggedData['metadata']['sensitivity_level'] === 'high') {
    // Handle highly sensitive data accordingly
}
```

### Access Control Layer

The Access Control Layer enforces purpose-limited access to data based on regulatory requirements.

```php
// Initialize the access control system
$accessControl = new \Glueful\ComplianceManager\AccessControlLayer($dataClassifier);

// User context - who is accessing the data and why
$userContext = [
    'user_id' => 'user_12345',
    'role' => 'customer_support',
    'user_consents' => ['marketing', 'analytics'],
];

// Validate access to data for a specific purpose
$accessResult = $accessControl->validateAccess(
    $userData,          // Data being accessed
    'support_inquiry',  // Purpose of data access
    $userContext        // Context of the user accessing data
);

if ($accessResult['allowed']) {
    // Proceed with data access
} else {
    // Handle unauthorized access attempt
    $reason = $accessResult['denial_reason'];
}
```

## GDPR Toolkit

### Subject Rights Management

Handle data subject requests for access, erasure, and portability.

```php
// Initialize the Subject Rights Manager
$subjectRights = new \Glueful\ComplianceManager\GDPR\SubjectRightsManager($dataClassifier);

// Process a data access request
$userData = $subjectRights->processAccessRequest('user_12345', [
    'verification_method' => 'email',
    'request_id' => 'req_789'
]);

// Process a data deletion request
$deletionResult = $subjectRights->processDeletionRequest('user_12345', [
    'delete_reason' => 'user_requested',
    'request_id' => 'req_790'
]);

// Process a data portability request
$portableData = $subjectRights->processPortabilityRequest(
    'user_12345',
    ['request_id' => 'req_791'],
    'json' // Format: json, csv, xml
);

// Process consent withdrawal
$withdrawalResult = $subjectRights->processConsentWithdrawal(
    'user_12345',
    ['request_id' => 'req_792'],
    ['marketing', 'analytics'] // Consent IDs to withdraw
);
```

### Lawful Basis Tracking

Track and verify legal basis for data processing activities under GDPR.

```php
// Initialize the Lawful Basis Tracker
$lawfulBasisTracker = new \Glueful\ComplianceManager\GDPR\LawfulBasisTracker();

// Record user consent
$consentId = $lawfulBasisTracker->recordConsent(
    'user_12345',
    ['marketing', 'analytics', 'third_party'],
    ['source' => 'web_form', 'ip_address' => '192.168.1.1']
);

// Verify consent for a specific purpose
$hasConsent = $lawfulBasisTracker->verifyConsent('user_12345', 'marketing');

// Record a legitimate interest assessment
$assessmentId = $lawfulBasisTracker->recordLegitimateInterestAssessment(
    'fraud_prevention',
    [
        'purpose' => 'Prevent fraudulent account activity',
        'necessity' => 'Essential to protect customers and business',
        'balancing_test' => 'Limited data used, minimal impact on privacy'
    ]
);

// Verify legitimate interest for a purpose
$legitimateInterestValid = $lawfulBasisTracker->verifyLegitimateInterest('fraud_prevention');
```

## CCPA Toolkit

### Consumer Rights Manager

Manage California consumer privacy rights under CCPA/CPRA.

```php
// Initialize the Consumer Rights Manager
$consumerRights = new \Glueful\ComplianceManager\CCPA\ConsumerRightsManager($dataClassifier);

// Process a data access request
$consumerData = $consumerRights->processAccessRequest('consumer_12345', [
    'verification_level' => 'high',
    'request_id' => 'req_123'
]);

// Process a data deletion request
$deletionResult = $consumerRights->processDeletionRequest('consumer_12345', [
    'verification_level' => 'high',
    'request_id' => 'req_124'
]);

// Record a "Do Not Sell My Personal Information" opt-out
$optOutResult = $consumerRights->recordDoNotSellOptOut('consumer_12345', [
    'source' => 'privacy_center',
    'timestamp' => time()
]);

// Cancel a "Do Not Sell" opt-out
$cancelOptOut = $consumerRights->cancelDoNotSellOptOut('consumer_12345', [
    'reason' => 'user_requested'
]);

// Generate a disclosure report for regulatory compliance
$report = $consumerRights->generateDisclosureReport(
    '2023-01-01',  // start date
    '2023-12-31',  // end date
    'detailed'     // format: summary, detailed
);
```

## HIPAA Toolkit

### PHI Access Manager

Ensure compliant access to Protected Health Information (PHI).

```php
// Initialize the PHI Access Manager
$phiAccessManager = new \Glueful\ComplianceManager\HIPAA\PhiAccessManager($dataClassifier);

// Validate access to PHI
$accessContext = [
    'user_id' => 'provider_789',
    'role' => 'physician',
    'purpose' => 'treatment',
    'patient_relationship' => 'attending_physician'
];

$accessResult = $phiAccessManager->validatePhiAccess($patientData, $accessContext);

if ($accessResult['allowed']) {
    // Allow PHI access
} else {
    // Log unauthorized access attempt
    $reason = $accessResult['denial_reason'];
}

// Log a security incident
$incidentId = $phiAccessManager->logSecurityIncident(
    'unauthorized_access',
    [
        'user_id' => 'employee_123',
        'location' => 'remote',
        'timestamp' => time()
    ],
    'Attempted access to patient records without authorization',
    $dataClassifications // Optional: classifications of affected data
);
```

### Business Associate Manager

Manage Business Associate Agreements (BAAs) for HIPAA compliance.

```php
// Initialize the Business Associate Manager
$baaManager = new \Glueful\ComplianceManager\HIPAA\BusinessAssociateManager();

// Create a new business associate
$associateId = $baaManager->createBusinessAssociate(
    'Acme Healthcare Analytics',
    [
        'contact_name' => 'Jane Smith',
        'contact_email' => 'jane@acmehealth.com',
        'services_provided' => 'Data analytics for patient outcomes'
    ]
);

// Create a BAA with this associate
$agreementId = $baaManager->createAgreement(
    $associateId,
    [
        'start_date' => '2023-01-01',
        'end_date' => '2024-01-01',
        'data_allowed' => ['aggregate_health_metrics', 'deidentified_outcomes'],
        'signed_by' => 'John Doe, CTO'
    ],
    'standard' // template to use
);

// Validate a BAA is in place
$isValid = $baaManager->validateBusinessAssociateAgreement('Acme Healthcare Analytics');

// Get agreements expiring soon
$expiringAgreements = $baaManager->getExpiringAgreements(30); // next 30 days
```

## API Reference

The ComplianceManager provides a comprehensive RESTful API for integrating compliance functionality into your applications.

### Core API Endpoints

#### Classify Data

**POST** `/api/compliance/classify-data`

```json
{
  "data": {
    "name": "Jane Doe",
    "email": "jane@example.com",
    "ssn": "123-45-6789"
  },
  "context": "user_registration"
}
```

Response:
```json
{
  "success": true,
  "data": {
    "classifications": {
      "name": "personal_data",
      "email": "personal_data",
      "ssn": "sensitive_data"
    },
    "sensitivity_level": "high",
    "has_sensitive_data": true
  }
}
```

#### Validate Access

**POST** `/api/compliance/validate-access`

```json
{
  "data": { "user_data_object" },
  "purpose": "customer_support",
  "user_context": {
    "user_id": "staff_1234",
    "role": "support_agent"
  }
}
```

### GDPR API Endpoints

#### Subject Request

**POST** `/api/compliance/gdpr/subject-request`

```json
{
  "subject_id": "user_12345",
  "request_type": "access|deletion|portability|consent_withdrawal",
  "metadata": { "optional_context" },
  "format": "json", // for portability requests
  "consent_ids": ["marketing", "analytics"] // for consent withdrawal
}
```

#### Lawful Basis Management

**POST** `/api/compliance/gdpr/lawful-basis`

```json
{
  "operation": "record_consent|verify_consent|record_legitimate_interest|verify_legitimate_interest",
  "user_id": "user_12345", // for consent operations
  "purposes": ["marketing", "analytics"], // for record_consent
  "purpose": "marketing", // for verify_consent and legitimate interest
  "assessment": { "legitimate_interest_details" } // for record_legitimate_interest
}
```

### CCPA API Endpoints

#### Consumer Request

**POST** `/api/compliance/ccpa/consumer-request`

```json
{
  "consumer_id": "consumer_12345",
  "request_type": "access|deletion|do_not_sell|cancel_do_not_sell",
  "metadata": { "optional_context" }
}
```

#### Disclosure Report

**POST** `/api/compliance/ccpa/disclosure-report`

```json
{
  "start_date": "2023-01-01",
  "end_date": "2023-12-31",
  "format": "summary|detailed"
}
```

### HIPAA API Endpoints

#### Validate PHI Access

**POST** `/api/compliance/hipaa/validate-phi-access`

```json
{
  "data": { "patient_data" },
  "access_context": {
    "user_id": "provider_789",
    "role": "physician",
    "purpose": "treatment"
  }
}
```

#### Business Associate Management

**POST** `/api/compliance/hipaa/business-associate-agreement`

```json
{
  "operation": "create_associate|create_agreement|validate_agreement|expiring_agreements",
  "name": "Associate Name", // for create_associate
  "details": { "associate_details" }, // for create_associate
  "associate_id": "associate_id", // for create_agreement
  "agreement_details": { "agreement_info" }, // for create_agreement
  "template_id": "standard", // optional for create_agreement
  "associate_name": "Associate Name", // for validate_agreement
  "days": 30 // for expiring_agreements
}
```

#### Security Incident Reporting

**POST** `/api/compliance/hipaa/security-incident`

```json
{
  "incident_type": "unauthorized_access|disclosure|loss",
  "context": { "incident_context" },
  "description": "Detailed description of the incident",
  "phi_data": { "optional_affected_data" }
}
```

## UI Components

The ComplianceManager extension includes a set of UI components for easy integration into your application's admin panel:

1. **Compliance Dashboard**: A central hub showing compliance status across regulations
2. **Data Mapping Tool**: Visual representation of data flows and classifications
3. **Subject/Consumer Request Manager**: UI for handling data subject requests
4. **Consent Management Center**: Interface for tracking and managing user consents
5. **Incident Response Console**: Tool for monitoring and responding to security incidents
6. **Compliance Report Generator**: UI for creating compliance reports for regulators

To access the admin interface, navigate to:
```
/admin/compliance-manager
```

## License

This extension is licensed under the same license as the Glueful framework.

## Support

For support, please open an issue on the GitHub repository or contact the Glueful team directly.