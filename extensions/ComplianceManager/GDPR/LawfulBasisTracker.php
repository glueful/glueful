<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager\GDPR;

/**
 * Lawful Basis Tracker for GDPR
 *
 * Manages consent tracking and legitimate interest assessments
 * for GDPR compliance.
 */
class LawfulBasisTracker
{
    /** @var array Consent records indexed by user ID */
    private array $consentRecords = [];

    /** @var array Legitimate interest assessments */
    private array $interestAssessments = [];

    /**
     * Records a user's consent for specific purposes
     *
     * @param int $userId User identifier
     * @param array $purposes Purposes consented to
     * @param array $metadata Additional metadata about the consent
     * @return string Consent record identifier
     */
    public function recordConsent(int $userId, array $purposes, array $metadata = []): string
    {
        $consentId = uniqid('consent_', true);

        $consentRecord = [
            'id' => $consentId,
            'user_id' => $userId,
            'purposes' => $purposes,
            'granted_at' => date('Y-m-d H:i:s'),
            'expires_at' => $metadata['expires_at'] ?? null,
            'collection_method' => $metadata['collection_method'] ?? 'web_form',
            'collection_context' => $metadata['collection_context'] ?? 'account_creation',
            'language' => $metadata['language'] ?? 'en',
            'version' => $metadata['version'] ?? '1.0',
            'status' => 'active',
            'evidence' => $metadata['evidence'] ?? null,
            'history' => [
                [
                    'action' => 'granted',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'details' => 'Initial consent grant',
                ],
            ],
        ];

        // Initialize user's consent records if not exist
        if (!isset($this->consentRecords[$userId])) {
            $this->consentRecords[$userId] = [];
        }

        // Add consent record
        $this->consentRecords[$userId][$consentId] = $consentRecord;

        // In a real implementation, this would persist to storage

        return $consentId;
    }

    /**
     * Withdraws consent for specific purposes
     *
     * @param int $userId User identifier
     * @param string $consentId Consent identifier to withdraw
     * @param array|null $specificPurposes Specific purposes to withdraw (null for all)
     * @return bool Success indicator
     */
    public function withdrawConsent(int $userId, string $consentId, ?array $specificPurposes = null): bool
    {
        // Check if user and consent record exist
        if (
            !isset($this->consentRecords[$userId]) ||
            !isset($this->consentRecords[$userId][$consentId])
        ) {
            return false;
        }

        $consentRecord = &$this->consentRecords[$userId][$consentId];

        // If specific purposes provided, only withdraw those
        if ($specificPurposes !== null) {
            $remainingPurposes = array_diff($consentRecord['purposes'], $specificPurposes);
            $consentRecord['purposes'] = $remainingPurposes;

            // If no purposes remain, mark as withdrawn
            if (empty($remainingPurposes)) {
                $consentRecord['status'] = 'withdrawn';
            } else {
                $consentRecord['status'] = 'partially_withdrawn';
            }

            $withdrawnPurposes = $specificPurposes;
        } else {
            // Withdraw all purposes
            $consentRecord['status'] = 'withdrawn';
            $withdrawnPurposes = $consentRecord['purposes'];
        }

        // Record withdrawal in history
        $consentRecord['history'][] = [
            'action' => 'withdrawn',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => 'Consent withdrawn for purposes: ' . implode(', ', $withdrawnPurposes),
        ];

        return true;
    }

    /**
     * Verifies if a user has consented to a specific purpose
     *
     * @param int $userId User identifier
     * @param string $purpose Purpose to check consent for
     * @return array Consent verification result
     */
    public function verifyConsent(int $userId, string $purpose): array
    {
        // Check if user has any consent records
        if (!isset($this->consentRecords[$userId])) {
            return [
                'consented' => false,
                'reason' => 'No consent records found for user',
            ];
        }

        // Check each consent record for the user
        foreach ($this->consentRecords[$userId] as $consentId => $consentRecord) {
            // Skip inactive consents
            if ($consentRecord['status'] !== 'active' && $consentRecord['status'] !== 'partially_withdrawn') {
                continue;
            }

            // Check if consent has expired
            if ($consentRecord['expires_at'] !== null && strtotime($consentRecord['expires_at']) < time()) {
                continue;
            }

            // Check if purpose is included in this consent
            if (in_array($purpose, $consentRecord['purposes'])) {
                return [
                    'consented' => true,
                    'consent_id' => $consentId,
                    'granted_at' => $consentRecord['granted_at'],
                    'expires_at' => $consentRecord['expires_at'],
                    'version' => $consentRecord['version'],
                ];
            }
        }

        // No valid consent found for this purpose
        return [
            'consented' => false,
            'reason' => 'No valid consent found for the specified purpose',
        ];
    }

    /**
     * Records a legitimate interest assessment
     *
     * @param string $purpose Business purpose for the processing
     * @param array $assessment Assessment details
     * @return string Assessment identifier
     */
    public function recordLegitimateInterestAssessment(string $purpose, array $assessment): string
    {
        $assessmentId = uniqid('lia_', true);

        $assessmentRecord = [
            'id' => $assessmentId,
            'purpose' => $purpose,
            'created_at' => date('Y-m-d H:i:s'),
            'conducted_by' => $assessment['conducted_by'] ?? 'system',
            'processing_description' => $assessment['processing_description'],
            'necessity_justification' => $assessment['necessity_justification'],
            'individual_interests' => $assessment['individual_interests'],
            'balancing_analysis' => $assessment['balancing_analysis'],
            'safeguards' => $assessment['safeguards'] ?? [],
            'conclusion' => $assessment['conclusion'],
            'status' => $assessment['conclusion'] === 'approved' ? 'active' : 'rejected',
            'review_date' => $assessment['review_date'] ?? date('Y-m-d', strtotime('+1 year')),
            'history' => [
                [
                    'action' => 'created',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'details' => 'Initial assessment created',
                ],
            ],
        ];

        $this->interestAssessments[$assessmentId] = $assessmentRecord;

        // In a real implementation, this would persist to storage

        return $assessmentId;
    }

    /**
     * Updates an existing legitimate interest assessment
     *
     * @param string $assessmentId Assessment identifier
     * @param array $updates Updates to apply
     * @return bool Success indicator
     */
    public function updateLegitimateInterestAssessment(string $assessmentId, array $updates): bool
    {
        // Check if assessment exists
        if (!isset($this->interestAssessments[$assessmentId])) {
            return false;
        }

        $assessment = &$this->interestAssessments[$assessmentId];

        // Apply updates
        foreach ($updates as $key => $value) {
            if (isset($assessment[$key]) && $key !== 'id' && $key !== 'history') {
                $assessment[$key] = $value;
            }
        }

        // Update status if conclusion changed
        if (isset($updates['conclusion'])) {
            $assessment['status'] = $updates['conclusion'] === 'approved' ? 'active' : 'rejected';
        }

        // Record update in history
        $assessment['history'][] = [
            'action' => 'updated',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => 'Assessment updated: ' . implode(', ', array_keys($updates)),
        ];

        return true;
    }

    /**
     * Verifies if legitimate interest applies for a specific purpose
     *
     * @param string $purpose Purpose to check
     * @return array Verification result
     */
    public function verifyLegitimateInterest(string $purpose): array
    {
        $matchingAssessments = [];

        // Find all assessments for this purpose
        foreach ($this->interestAssessments as $assessmentId => $assessment) {
            if ($assessment['purpose'] === $purpose && $assessment['status'] === 'active') {
                // Check if assessment has expired
                if (strtotime($assessment['review_date']) < time()) {
                    continue; // Skip expired assessments
                }

                $matchingAssessments[$assessmentId] = $assessment;
            }
        }

        // Return the most recent valid assessment if any found
        if (!empty($matchingAssessments)) {
            // Sort by creation date, newest first
            uasort($matchingAssessments, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            $latestAssessment = reset($matchingAssessments);
            $latestAssessmentId = key($matchingAssessments);

            return [
                'legitimate_interest_applies' => true,
                'assessment_id' => $latestAssessmentId,
                'created_at' => $latestAssessment['created_at'],
                'review_date' => $latestAssessment['review_date'],
                'safeguards' => $latestAssessment['safeguards'],
            ];
        }

        // No valid assessment found
        return [
            'legitimate_interest_applies' => false,
            'reason' => 'No valid legitimate interest assessment found for the specified purpose',
        ];
    }

    /**
     * Checks all applicable lawful bases for a processing operation
     *
     * @param int $userId User identifier (for consent check, 0 to skip)
     * @param string $purpose Processing purpose
     * @return array Lawful basis verification results
     */
    public function checkLawfulBasis(int $userId, string $purpose): array
    {
        $result = [
            'has_lawful_basis' => false,
            'bases' => [],
        ];

        // Check consent if user ID provided
        if ($userId > 0) {
            $consentResult = $this->verifyConsent($userId, $purpose);
            $result['bases']['consent'] = $consentResult;

            if ($consentResult['consented']) {
                $result['has_lawful_basis'] = true;
                $result['primary_basis'] = 'consent';
            }
        }

        // Check legitimate interest
        $liaResult = $this->verifyLegitimateInterest($purpose);
        $result['bases']['legitimate_interest'] = $liaResult;

        if ($liaResult['legitimate_interest_applies'] && !$result['has_lawful_basis']) {
            $result['has_lawful_basis'] = true;
            $result['primary_basis'] = 'legitimate_interest';
        }

        // Other lawful bases could be checked here:
        // - Contract fulfillment
        // - Legal obligation
        // - Vital interests
        // - Public interest

        return $result;
    }

    /**
     * Gets user's consent records
     *
     * @param int $userId User identifier
     * @return array User's consent records
     */
    public function getUserConsents(int $userId): array
    {
        return $this->consentRecords[$userId] ?? [];
    }

    /**
     * Gets details of a specific legitimate interest assessment
     *
     * @param string $assessmentId Assessment identifier
     * @return array|null Assessment details or null if not found
     */
    public function getLegitimateInterestAssessment(string $assessmentId): ?array
    {
        return $this->interestAssessments[$assessmentId] ?? null;
    }

    /**
     * Gets all legitimate interest assessments
     *
     * @param string|null $purpose Filter by purpose
     * @return array Assessment records
     */
    public function getAllLegitimateInterestAssessments(?string $purpose = null): array
    {
        if ($purpose === null) {
            return $this->interestAssessments;
        }

        return array_filter($this->interestAssessments, function ($assessment) use ($purpose) {
            return $assessment['purpose'] === $purpose;
        });
    }
}
