<?php

declare(strict_types=1);

use Glueful\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;

/*
 * Compliance Manager Routes
 *
 * This file defines routes for compliance management:
 * - Core data classification endpoints
 * - GDPR compliance endpoints
 * - CCPA compliance endpoints
 * - HIPAA compliance endpoints
 */

// Core Compliance API endpoints
Router::group('/compliance', function () {
    /**
     * @route POST /compliance/classify-data
     * @summary Classify Data
     * @description Analyzes and classifies data according to privacy regulations
     * @tag Compliance Core
     * @requestBody data:array="Data to be classified",
     *              context:string="Context of the classification (default: api)"
     *              {required=data}
     * @response 200 application/json "Successfully classified data" {
     *   success:boolean=true,
     *   data:{
     *     classifications:object="Data classifications by category",
     *     sensitivity_level:string="Overall sensitivity level",
     *     has_sensitive_data:boolean="Whether sensitive data was detected"
     *   }
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during classification"
     */
    Router::post('/classify-data', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $classifications = $classifier->classifyData($data['data'] ?? [], $data['context'] ?? 'api');
            $metadata = $classifier->tagData($data['data'] ?? [], $classifications)['metadata'];

            return Response::ok([
                'classifications' => $classifications,
                'sensitivity_level' => $metadata['sensitivity_level'],
                'has_sensitive_data' => $metadata['has_sensitive_data']
            ], 'Data successfully classified')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });

    /**
     * @route POST /compliance/validate-access
     * @summary Validate Data Access
     * @description Validates if access to data should be permitted based on purpose and user context
     * @tag Compliance Core
     * @requestBody data:array="Data to validate access for",
     *              purpose:string="Purpose of access",
     *              user_context:object="User context information"
     *              {required=data,purpose,user_context}
     * @response 200 application/json "Successfully validated access" {
     *   success:boolean=true,
     *   data:object="Access validation results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during validation"
     */
    Router::post('/validate-access', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['data']) || empty($data['purpose']) || empty($data['user_context'])) {
                return Response::error('Missing required parameters: data, purpose, user_context', 400)->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $accessControl = new \Glueful\ComplianceManager\AccessControlLayer($classifier);

            $result = $accessControl->validateAccess(
                $data['data'],
                $data['purpose'],
                $data['user_context']
            );

            return Response::ok($result, 'Access validation completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });
});

// GDPR API endpoints
Router::group('/compliance/gdpr', function () {
    /**
     * @route POST /compliance/gdpr/subject-request
     * @summary GDPR Subject Request
     * @description Processes data subject requests under GDPR (access, deletion, portability, consent withdrawal)
     * @tag GDPR Compliance
     * @requestBody subject_id:string="Subject identifier",
     *              request_type:string="Type of request (access, deletion, portability, consent_withdrawal)",
     *              metadata:object="Additional request metadata" {required=subject_id,request_type}
     * @response 200 application/json "Successfully processed subject request" {
     *   success:boolean=true,
     *   data:object="Request processing results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during request processing"
     */
    Router::post('/subject-request', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['subject_id']) || empty($data['request_type'])) {
                return Response::error('Missing required parameters: subject_id, request_type', 400)->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $subjectRights = new \Glueful\ComplianceManager\GDPR\SubjectRightsManager($classifier);

            $result = null;
            $requestType = $data['request_type'];
            $metadata = $data['metadata'] ?? [];

            if ($requestType === 'access') {
                $result = $subjectRights->processAccessRequest($data['subject_id'], $metadata);
            } elseif ($requestType === 'deletion') {
                $result = $subjectRights->processDeletionRequest($data['subject_id'], $metadata);
            } elseif ($requestType === 'portability') {
                $format = $data['format'] ?? 'json';
                $result = $subjectRights->processPortabilityRequest(
                    $data['subject_id'],
                    $metadata,
                    $format
                );
            } elseif ($requestType === 'consent_withdrawal') {
                $consentIds = $data['consent_ids'] ?? [];
                $result = $subjectRights->processConsentWithdrawal(
                    $data['subject_id'],
                    $metadata,
                    $consentIds
                );
            } else {
                return Response::error(
                    'Invalid request_type. Supported types: access, deletion, portability, consent_withdrawal',
                    400
                )->send();
            }

            return Response::ok($result, 'Subject request processed successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });

    /**
     * @route POST /compliance/gdpr/lawful-basis
     * @summary GDPR Lawful Basis Management
     * @description Manages lawful basis records for processing personal data under GDPR
     * @tag GDPR Compliance
     * @requestBody operation:string="Operation type (record_consent, verify_consent,
     *              record_legitimate_interest, verify_legitimate_interest)" {required=operation}
     * @response 200 application/json "Successfully processed lawful basis operation" {
     *   success:boolean=true,
     *   data:object="Operation results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during operation"
     */
    Router::post('/lawful-basis', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            $operation = $data['operation'] ?? '';

            $lawfulBasisTracker = new \Glueful\ComplianceManager\GDPR\LawfulBasisTracker();
            $result = null;

            switch ($operation) {
                case 'record_consent':
                    if (empty($data['user_id']) || empty($data['purposes'])) {
                        return Response::error('Missing required parameters: user_id, purposes', 400)->send();
                    }

                    $consentId = $lawfulBasisTracker->recordConsent(
                        $data['user_id'],
                        $data['purposes'],
                        $data['metadata'] ?? []
                    );

                    $result = [
                        'consent_id' => $consentId,
                        'user_id' => $data['user_id']
                    ];
                    break;

                case 'verify_consent':
                    if (empty($data['user_id']) || empty($data['purpose'])) {
                        return Response::error('Missing required parameters: user_id, purpose', 400)->send();
                    }

                    $result = $lawfulBasisTracker->verifyConsent($data['user_id'], $data['purpose']);
                    break;

                case 'record_legitimate_interest':
                    if (empty($data['purpose']) || empty($data['assessment'])) {
                        return Response::error('Missing required parameters: purpose, assessment', 400)->send();
                    }

                    $assessmentId = $lawfulBasisTracker->recordLegitimateInterestAssessment(
                        $data['purpose'],
                        $data['assessment']
                    );

                    $result = [
                        'assessment_id' => $assessmentId,
                        'purpose' => $data['purpose']
                    ];
                    break;

                case 'verify_legitimate_interest':
                    if (empty($data['purpose'])) {
                        return Response::error('Missing required parameter: purpose', 400)->send();
                    }

                    $result = $lawfulBasisTracker->verifyLegitimateInterest($data['purpose']);
                    break;

                default:
                    return Response::error(
                        'Invalid operation. Supported operations: record_consent, verify_consent, ' .
                        'record_legitimate_interest, verify_legitimate_interest',
                        400
                    )->send();
            }

            return Response::ok($result, 'Lawful basis operation completed successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });
});

// CCPA API endpoints
Router::group('/compliance/ccpa', function () {
    /**
     * @route POST /compliance/ccpa/consumer-request
     * @summary CCPA Consumer Request
     * @description Processes consumer requests under CCPA (access, deletion, do_not_sell, cancel_do_not_sell)
     * @tag CCPA Compliance
     * @requestBody consumer_id:string="Consumer identifier",
     *              request_type:string="Type of request",
     *              metadata:object="Additional request metadata"
     *              {required=consumer_id,request_type}
     * @response 200 application/json "Successfully processed consumer request" {
     *   success:boolean=true,
     *   data:object="Request processing results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during request processing"
     */
    Router::post('/consumer-request', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['consumer_id']) || empty($data['request_type'])) {
                return Response::error('Missing required parameters: consumer_id, request_type', 400)->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $consumerRights = new \Glueful\ComplianceManager\CCPA\ConsumerRightsManager($classifier);

            $result = null;
            $requestType = $data['request_type'];
            $metadata = $data['metadata'] ?? [];

            if ($requestType === 'access') {
                $result = $consumerRights->processAccessRequest($data['consumer_id'], $metadata);
            } elseif ($requestType === 'deletion') {
                $result = $consumerRights->processDeletionRequest($data['consumer_id'], $metadata);
            } elseif ($requestType === 'do_not_sell') {
                $result = $consumerRights->recordDoNotSellOptOut($data['consumer_id'], $metadata);
            } elseif ($requestType === 'cancel_do_not_sell') {
                $result = [
                    'success' => $consumerRights->cancelDoNotSellOptOut($data['consumer_id'], $metadata),
                    'consumer_id' => $data['consumer_id']
                ];
            } else {
                return Response::error(
                    'Invalid request_type. Supported types: access, deletion, do_not_sell, cancel_do_not_sell',
                    400
                )->send();
            }

            return Response::ok($result, 'Consumer request processed successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });

    /**
     * @route POST /compliance/ccpa/disclosure-report
     * @summary CCPA Disclosure Report
     * @description Generates disclosure reports required under CCPA
     * @tag CCPA Compliance
     * @requestBody start_date:string="Start date for report period",
     *              end_date:string="End date for report period",
     *              format:string="Report format (default: summary)"
     *              {required=start_date,end_date}
     * @response 200 application/json "Successfully generated disclosure report" {
     *   success:boolean=true,
     *   data:object="Disclosure report data"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during report generation"
     */
    Router::post('/disclosure-report', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['start_date']) || empty($data['end_date'])) {
                return Response::error('Missing required parameters: start_date, end_date', 400)->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $consumerRights = new \Glueful\ComplianceManager\CCPA\ConsumerRightsManager($classifier);

            $format = $data['format'] ?? 'summary';

            $report = $consumerRights->generateDisclosureReport(
                $data['start_date'],
                $data['end_date'],
                $format
            );

            return Response::ok($report, 'Disclosure report generated successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });
});

// HIPAA API endpoints
Router::group('/compliance/hipaa', function () {
    /**
     * @route POST /compliance/hipaa/validate-phi-access
     * @summary Validate PHI Access
     * @description Validates access to Protected Health Information according to HIPAA requirements
     * @tag HIPAA Compliance
     * @requestBody data:array="PHI data to validate access for",
     *              access_context:object="Access context information"
     *              {required=data,access_context}
     * @response 200 application/json "Successfully validated PHI access" {
     *   success:boolean=true,
     *   data:object="PHI access validation results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during validation"
     */
    Router::post('/validate-phi-access', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['data']) || empty($data['access_context'])) {
                return Response::error('Missing required parameters: data, access_context', 400)->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $phiAccess = new \Glueful\ComplianceManager\HIPAA\PhiAccessManager($classifier);

            $result = $phiAccess->validatePhiAccess($data['data'], $data['access_context']);

            return Response::ok($result, 'PHI access validation completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });

    /**
     * @route POST /compliance/hipaa/business-associate-agreement
     * @summary Business Associate Agreement Management
     * @description Manages Business Associate Agreements required under HIPAA
     * @tag HIPAA Compliance
     * @requestBody operation:string="Operation type (create_associate, create_agreement, validate_agreement,
     *              expiring_agreements)" {required=operation}
     * @response 200 application/json "Successfully processed BAA operation" {
     *   success:boolean=true,
     *   data:object="Operation results"
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during operation"
     */
    Router::post('/business-associate-agreement', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            $operation = $data['operation'] ?? '';

            $baaManager = new \Glueful\ComplianceManager\HIPAA\BusinessAssociateManager();
            $result = null;

            switch ($operation) {
                case 'create_associate':
                    if (empty($data['name'])) {
                        return Response::error('Missing required parameter: name', 400)->send();
                    }

                    $associateId = $baaManager->createBusinessAssociate(
                        $data['name'],
                        $data['details'] ?? []
                    );

                    $result = [
                        'associate_id' => $associateId,
                        'name' => $data['name']
                    ];
                    break;

                case 'create_agreement':
                    if (empty($data['associate_id']) || empty($data['agreement_details'])) {
                        return Response::error(
                            'Missing required parameters: associate_id, agreement_details',
                            400
                        )->send();
                    }

                    $agreementId = $baaManager->createAgreement(
                        $data['associate_id'],
                        $data['agreement_details'],
                        $data['template_id'] ?? 'standard'
                    );

                    $result = [
                        'agreement_id' => $agreementId,
                        'associate_id' => $data['associate_id']
                    ];
                    break;

                case 'validate_agreement':
                    if (empty($data['associate_id'])) {
                        return Response::error('Missing required parameter: associate_id', 400)->send();
                    }

                    $result = $baaManager->checkActiveAgreement($data['associate_id']);
                    break;

                case 'expiring_agreements':
                    $days = $data['days'] ?? 30;
                    $result = $baaManager->getExpiringAgreements($days);
                    break;

                default:
                    return Response::error(
                        'Invalid operation. Supported operations: create_associate, create_agreement, ' .
                        'validate_agreement, expiring_agreements',
                        400
                    )->send();
            }

            return Response::ok($result, 'Business Associate operation completed successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });

    /**
     * @route POST /compliance/hipaa/security-incident
     * @summary Security Incident Reporting
     * @description Records and manages security incidents per HIPAA requirements
     * @tag HIPAA Compliance
     * @requestBody incident_type:string="Type of security incident",
     *              context:object="Incident context",
     *              description:string="Incident description",
     *              phi_data:array="Optional PHI data involved in incident"
     *              {required=incident_type,context,description}
     * @response 200 application/json "Successfully recorded security incident" {
     *   success:boolean=true,
     *   data:{
     *     incident_id:string="Unique identifier for the incident",
     *     timestamp:string="When the incident was recorded",
     *     severity:string="Incident severity level"
     *   }
     * }
     * @response 400 "Bad request or missing parameters"
     * @response 500 "Server error during incident recording"
     */
    Router::post('/security-incident', function (Request $request) {
        $data = $request->getContent() ? json_decode($request->getContent(), true) : [];

        try {
            if (empty($data['incident_type']) || empty($data['context']) || empty($data['description'])) {
                return Response::error(
                    'Missing required parameters: incident_type, context, description',
                    400
                )->send();
            }

            $classifier = new \Glueful\ComplianceManager\DataClassifier();
            $phiAccess = new \Glueful\ComplianceManager\HIPAA\PhiAccessManager($classifier);

            $phiClassifications = [];
            if (!empty($data['phi_data'])) {
                $phiClassifications = $classifier->classifyData($data['phi_data'], 'security_incident');
            }

            $incidentId = $phiAccess->logSecurityIncident(
                $data['incident_type'],
                $data['context'],
                $data['description'],
                $phiClassifications
            );

            return Response::ok([
                'incident_id' => $incidentId,
                'timestamp' => date('Y-m-d H:i:s'),
                'severity' => !empty($phiClassifications) ? 'high' : 'medium'
            ], 'Security incident recorded successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500)->send();
        }
    });
});
