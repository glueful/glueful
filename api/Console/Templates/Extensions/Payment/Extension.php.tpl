<?php
declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * {{EXTENSION_NAME}} Extension
 *
 * @description {{EXTENSION_DESCRIPTION}}
 * @version 1.0.0
 */
class {{EXTENSION_NAME}} extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];
    
    /** @var array Supported payment gateways */
    private static array $supportedGateways = [];
    
    /** @var array Initialized gateway instances */
    private static array $gatewayInstances = [];
    
    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }
        
        // Register supported gateways
        self::registerGateways();
    }
    
    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        // Register payment services
    }
    
    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // Register payment-related middleware
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
        $action = $queryParams['action'] ?? '';
        $gateway = $queryParams['gateway'] ?? '';
        
        switch ($action) {
            case 'process-payment':
                return self::processPayment($gateway, $bodyParams);
                
            case 'get-gateways':
                return [
                    'success' => true,
                    'data' => [
                        'gateways' => self::getSupportedGateways()
                    ]
                ];
                
            default:
                return [
                    'success' => true,
                    'data' => [
                        'extension' => '{{EXTENSION_NAME}}',
                        'message' => 'Payment gateway is available'
                    ]
                ];
        }
    }
    
    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => '{{EXTENSION_NAME}}',
            'description' => '{{EXTENSION_DESCRIPTION}}',
            'version' => '1.0.0',
            'author' => '{{AUTHOR_NAME}}',
            'type' => 'optional',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
            ],
            'category' => 'payment',
            'features' => [
                'Multiple payment gateway support',
                'Secure payment processing',
                'Payment status tracking'
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
        
        // Check configuration
        if (empty(self::$config)) {
            $issues[] = 'Missing configuration file';
            $healthy = false;
        }
        
        // Check if gateways directory exists
        if (!is_dir(__DIR__ . '/Gateways')) {
            $issues[] = 'Gateways directory not found';
            $healthy = false;
        }
        
        // Check if any gateways are configured
        if (empty(self::$supportedGateways)) {
            $issues[] = 'No payment gateways configured';
            $healthy = false;
        }
        
        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
    
    /**
     * Process a payment through the specified gateway
     * 
     * @param string $gateway Gateway identifier
     * @param array $data Payment data
     * @return array Processing result
     */
    private static function processPayment(string $gateway, array $data): array
    {
        // Validate gateway
        if (!in_array($gateway, array_keys(self::$supportedGateways))) {
            return [
                'success' => false,
                'error' => 'Unsupported payment gateway'
            ];
        }
        
        // Validate required payment data
        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            return [
                'success' => false,
                'error' => 'Valid payment amount is required'
            ];
        }
        
        // Process payment through gateway
        try {
            $gatewayInstance = self::getGatewayInstance($gateway);
            
            // Process payment (in real implementation, call gateway's process method)
            $result = [
                'transaction_id' => md5(uniqid((string)rand(), true)),
                'status' => 'success',
                'gateway' => $gateway,
                'amount' => $data['amount']
            ];
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Register supported payment gateways
     */
    private static function registerGateways(): void
    {
        // Get configured gateways from config
        $configuredGateways = self::$config['gateways'] ?? [];
        
        foreach ($configuredGateways as $gateway => $config) {
            if ($config['enabled'] ?? false) {
                $gatewayClass = "Glueful\\Extensions\\{{EXTENSION_NAME}}\\Gateways\\{$gateway}Gateway";
                
                // Check if gateway class exists
                if (class_exists($gatewayClass)) {
                    self::$supportedGateways[$gateway] = [
                        'class' => $gatewayClass,
                        'config' => $config
                    ];
                }
            }
        }
    }
    
    /**
     * Get supported payment gateways
     * 
     * @return array List of supported gateways
     */
    private static function getSupportedGateways(): array
    {
        $gateways = [];
        
        foreach (self::$supportedGateways as $name => $info) {
            $gateways[] = [
                'name' => $name,
                'display_name' => $info['config']['display_name'] ?? $name,
                'description' => $info['config']['description'] ?? '',
                'icon' => $info['config']['icon'] ?? null
            ];
        }
        
        return $gateways;
    }
    
    /**
     * Get a gateway instance
     * 
     * @param string $gateway Gateway name
     * @return object Gateway instance
     * @throws \Exception If gateway not found
     */
    private static function getGatewayInstance(string $gateway)
    {
        if (!isset(self::$gatewayInstances[$gateway])) {
            if (!isset(self::$supportedGateways[$gateway])) {
                throw new \Exception("Gateway '$gateway' not supported");
            }
            
            $gatewayInfo = self::$supportedGateways[$gateway];
            $gatewayClass = $gatewayInfo['class'];
            
            self::$gatewayInstances[$gateway] = new $gatewayClass($gatewayInfo['config']);
        }
        
        return self::$gatewayInstances[$gateway];
    }
}