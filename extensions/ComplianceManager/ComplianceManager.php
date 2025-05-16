<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Auth;
use Glueful\Http\Response;
use Glueful\Repository\PermissionRepository;

/**
 * ComplianceManager Extension
 *
 * @description The ComplianceManager extension provides organizations with comprehensive tools to meet
 * regulatory requirements across multiple privacy and security frameworks including GDPR, CCPA, and HIPAA
 * @version 1.0.0
 */
class ComplianceManager extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /** @var array Registered service instances */
    private static array $services = [];

    private static PermissionRepository $permission;

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }
    }

    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        // Create service managers and register services directly
        // This follows the pattern used by other extensions
        // Core compliance services
        try {
                $dataClassifier = new \Glueful\ComplianceManager\DataClassifier();
                $accessControlLayer = new \Glueful\ComplianceManager\AccessControlLayer($dataClassifier);

                // GDPR services
                $subjectRightsManager = new \Glueful\ComplianceManager\GDPR\SubjectRightsManager($dataClassifier);
                $lawfulBasisTracker = new \Glueful\ComplianceManager\GDPR\LawfulBasisTracker();

                // CCPA services
                $consumerRightsManager = new \Glueful\ComplianceManager\CCPA\ConsumerRightsManager($dataClassifier);

                // HIPAA services
                $phiAccessManager = new \Glueful\ComplianceManager\HIPAA\PhiAccessManager($dataClassifier);
                $businessAssociateManager = new \Glueful\ComplianceManager\HIPAA\BusinessAssociateManager();

            // Store service instances in a static registry for later use
            self::$services = [
                'DataClassifier' => $dataClassifier,
                'AccessControlLayer' => $accessControlLayer,
                'GDPR.SubjectRightsManager' => $subjectRightsManager,
                'GDPR.LawfulBasisTracker' => $lawfulBasisTracker,
                'CCPA.ConsumerRightsManager' => $consumerRightsManager,
                'HIPAA.PhiAccessManager' => $phiAccessManager,
                'HIPAA.BusinessAssociateManager' => $businessAssociateManager,
            ];
        } catch (\Exception $e) {
            error_log('Error registering ComplianceManager services: ' . $e->getMessage());
        }
    }

    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // Register middleware components for the extension
        // This is where you would add any middleware that needs to be run for every request
        // For example, you might want to check user permissions or validate input data
        // Middleware can be registered here as needed
    }

    /**
     * Register admin UI routes
     */
    public static function registerAdminRoutes(): void
    {
    }

    /**
     * Process extension requests
     *
     * @param array $queryParams GET parameters
     * @param array $bodyParams POST parameters
     * @return array Response data
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        $action = $queryParams['action'] ?? 'status';

        switch ($action) {
            case 'status':
                return [
                    'success' => true,
                    'data' => [
                        'extension' => 'ComplianceManager',
                        'message' => 'ComplianceManager is working properly',
                        'modules' => [
                            'core' => ['DataClassifier', 'AccessControlLayer'],
                            'gdpr' => ['SubjectRightsManager', 'LawfulBasisTracker'],
                            'ccpa' => ['ConsumerRightsManager'],
                            'hipaa' => ['PhiAccessManager', 'BusinessAssociateManager']
                        ]
                    ]
                ];

            case 'classify_data':
                if (empty($bodyParams['data'])) {
                    return [
                        'success' => false,
                        'error' => 'Missing required data parameter'
                    ];
                }

                $classifier = new \Glueful\ComplianceManager\DataClassifier();
                $classifications = $classifier->classifyData($bodyParams['data'], $bodyParams['context'] ?? 'api');
                $metadata = $classifier->tagData($bodyParams['data'], $classifications)['metadata'];

                return [
                    'success' => true,
                    'data' => [
                        'classifications' => $classifications,
                        'sensitivity_level' => $metadata['sensitivity_level'],
                        'has_sensitive_data' => $metadata['has_sensitive_data']
                    ]
                ];

            // Add more actions as needed

            default:
                return [
                    'success' => false,
                    'error' => 'Unknown action: ' . $action
                ];
        }
    }

    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'ComplianceManager',
            'description' => 'The ComplianceManager extension provides organizations with ' .
                'comprehensive tools to meet regulatory requirements across multiple privacy and security frameworks ' .
                'including GDPR, CCPA, and HIPAA',
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'type' => 'optional',
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ]
        ];
    }

    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Add your health checks here

        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
}
