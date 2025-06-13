<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin;

use Glueful\Http\Response;
use Glueful\Auth\{AuthBootstrap};
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Glueful\Controllers\ConfigController;

class AdminController
{
    private ?ConfigController $configController = null;
    private $authManager = null;
    private bool $authInitialized = false;

    public function __construct()
    {
        // Lightweight constructor - dependencies are loaded lazily when needed
    }

    /**
     * Lazy load ConfigController
     */
    private function getConfigController(): ConfigController
    {
        if ($this->configController === null) {
            $this->configController = new ConfigController();
        }
        return $this->configController;
    }

    /**
     * Lazy load AuthManager
     */
    private function getAuthManager()
    {
        if ($this->authManager === null || !$this->authInitialized) {
            AuthBootstrap::initialize();
            $this->authManager = AuthBootstrap::getManager();
            $this->authInitialized = true;
        }
        return $this->authManager;
    }


    /**
     * Get all configurations
     */
    public function getAllConfigs(SymfonyRequest $request): mixed
    {
        try {
            // Use ConfigController to get all configs
            $configs = $this->getConfigController()->getConfigs();

            // Transform the data to match API response format
            $configList = [];
            foreach ($configs as $config) {
                $configList[] = [
                    'name' => $config['name'],
                    'path' => $config['name'] . '.php'
                ];
            }

            return Response::ok($configList, 'Configuration files retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("AdminController::getAllConfigs - Exception: " . $e->getMessage());
            return Response::error(
                'Failed to get configurations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_FETCH_FAILED'
            )->send();
        }
    }

    /**
     * Get configuration by filename
     */
    public function getConfig($filename): mixed
    {
        try {
            if (!$filename) {
                return Response::error(
                    'Filename is required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_FILENAME'
                )->send();
            }

            // Use ConfigController to get the config
            $content = $this->getConfigController()->getConfigByFile($filename);

            if ($content === null) {
                return Response::notFound('Configuration file not found')->send();
            }

            return Response::ok([
                'name' => $filename,
                'content' => $content
            ], 'Configuration retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get config error: " . $e->getMessage());
            return Response::error(
                'Failed to get configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_GET_FAILED'
            )->send();
        }
    }


    /**
     * Update configuration
     */
    public function updateConfig(SymfonyRequest $request): mixed
    {
        try {
            $filename = $request->attributes->get('filename');
            $data = json_decode($request->getContent(), true);

            if (!$filename) {
                return Response::error(
                    'Filename is required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_FILENAME'
                )->send();
            }

            if (!isset($data['content']) || !is_array($data['content'])) {
                return Response::error(
                    'Configuration content is required and must be an array',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'INVALID_CONTENT'
                )->send();
            }

            // Use ConfigController to update the config
            // Note: updateConfig expects the config data directly, not wrapped in 'content'
            $success = $this->getConfigController()->updateConfig($filename, $data['content']);

            if (!$success) {
                return Response::error(
                    'Failed to update configuration',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_SERVER,
                    'CONFIG_UPDATE_FAILED'
                )->send();
            }

            // Clear configuration cache
            if (class_exists('\Glueful\Helpers\ConfigManager')) {
                \Glueful\Helpers\ConfigManager::clearCache();
            }

            return Response::ok([
                'success' => true
            ], 'Configuration updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update config error: " . $e->getMessage());
            return Response::error(
                'Failed to update configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_UPDATE_EXCEPTION'
            )->send();
        }
    }

    /**
     * Create new configuration
     */
    public function createConfig(SymfonyRequest $request): mixed
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['name']) || !isset($data['content'])) {
                return Response::error(
                    'Name and content are required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_REQUIRED_FIELDS'
                )->send();
            }

            if (!is_array($data['content'])) {
                return Response::error(
                    'Configuration content must be an array',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'INVALID_CONTENT'
                )->send();
            }

            $success = $this->getConfigController()->createConfig($data['name'], $data['content']);

            if (!$success) {
                return Response::error(
                    'Failed to create configuration',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_SERVER,
                    'CONFIG_CREATE_FAILED'
                )->send();
            }

            return Response::ok([
                'success' => true
            ], 'Configuration created successfully')->send();
        } catch (\Exception $e) {
            error_log("Create config error: " . $e->getMessage());
            return Response::error(
                'Failed to create configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_CREATE_EXCEPTION'
            )->send();
        }
    }

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param SymfonyRequest $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(SymfonyRequest $request): ?array
    {
        // For admin routes, try admin provider first, then either jwt OR api_key (not both)
        $userData = $this->getAuthManager()->authenticateWithProvider('admin', $request);

        if (!$userData) {
            // If admin auth fails, try jwt
            $userData = $this->getAuthManager()->authenticateWithProvider('jwt', $request);

            // If jwt fails, try api_key as a last resort
            if (!$userData) {
                $userData = $this->getAuthManager()->authenticateWithProvider('api_key', $request);
            }
        }

        return $userData;
    }
}
