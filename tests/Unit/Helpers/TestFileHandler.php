<?php
namespace Tests\Unit\Helpers;

use Glueful\Helpers\FileHandler as OriginalFileHandler;
use Glueful\Uploader\FileUploader;
use Tests\Unit\Helpers\AuthTestHelper;

/**
 * Test-specific subclass of FileHandler that overrides the authentication method
 * This allows us to test without modifying the original class
 */
class TestFileHandler extends OriginalFileHandler
{
    /**
     * Test-specific uploader instance - must be public for direct access in tests
     * Can be either a real FileUploader or our TestFileUploaderWrapper
     */
    public $testUploader;
    
    /**
     * Mock auth service for testing
     * This property is needed for tests but doesn't exist in the original FileHandler class
     * We explicitly define it here to make the tests work without modifying the original class
     */
    public $auth;
    
    /**
     * Constructor that initializes our own uploader instance
     * We're not calling parent::__construct() to avoid database connections
     * 
     * @param object $fileUploader Either a real FileUploader or a TestFileUploaderWrapper
     */
    public function __construct($fileUploader = null)
    {
        // Don't call parent::__construct() to avoid database connections
        $this->testUploader = $fileUploader ?? new FileUploader();
        
        // Set up auth directly instead of using the parent constructor
        $this->auth = new class() {
            public function getUser() {
                return null; // Mock user implementation for testing
            }
            
            public function validateToken() {
                return true; // Always valid for testing
            }
        };
    }
    
    /**
     * Override the original method to use our test helper instead
     * This test-only version will be used for the tests
     */
    public function handleFileUpload(array $getParams, array $fileParams): array 
    {
        try {
            // Use our test helper instead of the original static method
            $token = AuthTestHelper::extractTokenFromRequest();
        
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Authentication required',
                    'code' => 401
                ];
            }

            return $this->testUploader->handleUpload($token, $getParams, $fileParams);

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'File upload failed: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    /**
     * Override base64 upload method to use our test helper
     */
    public function handleBase64Upload(array $getParams, array $postParams): array 
    {
        try {
            // Use our test helper instead of the original static method
            $token = AuthTestHelper::extractTokenFromRequest();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Authentication required',
                    'code' => 401
                ];
            }
            
            $_GET['token'] = $token;
            
            // Convert base64 to temp file
            $tmpFile = $this->testUploader->handleBase64Upload($postParams['base64']);
            
            // In tests, the tmpFile might not actually exist
            $fileSize = file_exists($tmpFile) ? filesize($tmpFile) : 1024; // Use a default size if file doesn't exist
            
            $fileParams = [
                'name' => $getParams['name'] ?? 'upload.jpg',
                'type' => $getParams['mime_type'] ?? 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => $fileSize
            ];
            
            return $this->testUploader->handleUpload(
                $getParams['token'] ?? $token,
                $getParams,
                ['file' => $fileParams]
            );

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Base64 upload failed: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }
    
    /**
     * Real implementation of getBlob method for testing
     * 
     * This handles the basic getBlob functionality in a way that can be tested
     * Specific parts will be overridden in the tests as needed
     * 
     * @param string $uuid File UUID
     * @param string $type Response type (info|download|inline|image)
     * @param array $params Additional parameters for image processing
     * @return array Response with file data or info
     */
    public function getBlob(string $uuid, string $type = 'info', array $params = []): array
    {
        // Basic validation
        if (empty($uuid)) {
            return [
                'success' => false,
                'message' => 'File UUID is required',
                'code' => 400
            ];
        }
        
        // Get file info from the database or storage
        $fileInfo = $this->getBlobInfo($uuid);
        
        // File not found
        if (!$fileInfo) {
            return [
                'success' => false,
                'message' => 'File not found',
                'code' => 404
            ];
        }
        
        // Get the appropriate storage driver based on the file's storage type
        $storage = $this->getStorageDriver($fileInfo['storage_type'] ?? 'local');
        
        // Process the request based on the requested response type
        if ($type === 'info') {
            $result = $this->getBlobAsFile($storage, $fileInfo);
            return [
                'success' => true,
                'message' => 'File information retrieved',
                'data' => $result
            ];
        } elseif ($type === 'download') {
            $result = $this->downloadBlob($storage, $fileInfo);
            return [
                'success' => true,
                'message' => 'File download prepared',
                'data' => $result
            ];
        } elseif ($type === 'inline') {
            $result = $this->serveFileInline($fileInfo, $storage);
            return [
                'success' => true,
                'message' => 'File inline display prepared',
                'data' => $result
            ];
        } elseif ($type === 'image' && strpos($fileInfo['mime_type'], 'image/') === 0) {
            // Check if storage is available before using it
            if ($storage === null) {
                return [
                    'success' => false,
                    'message' => 'Storage driver not available',
                    'code' => 500
                ];
            }
            
            // Get image URL from storage for processing
            $imageUrl = $storage->getUrl($fileInfo['filepath']);
            
            // Process the image with the requested parameters
            $result = $this->processImageBlob($imageUrl, $params);
            return [
                'success' => true,
                'message' => 'Image processed successfully',
                'data' => $result
            ];
        }
        
        // Invalid response type or unable to process
        return [
            'success' => false,
            'message' => 'Invalid response type or file type',
            'code' => 400
        ];
    }
    
    /**
     * The following are placeholder methods that will be mocked in tests
     * We're adding these so the mock builder can override them
     * Note: These methods need to be PUBLIC for mocking in PHPUnit
     */
    public function getBlobInfo(string $uuid): ?array 
    {
        // This is a placeholder that will always be mocked
        return null;
    }
    
    public function getStorageDriver(string $storageType = 'local')
    {
        // This is a placeholder that will always be mocked
        return null;
    }
    
    public function getBlobAsFile($storage, array $fileInfo): array
    {
        // This is a placeholder that will always be mocked
        return [];
    }
    
    public function downloadBlob($storage, array $fileInfo): array
    {
        // This is a placeholder that will always be mocked
        return [];
    }
    
    public function serveFileInline(array $fileInfo, $storage): array
    {
        // This is a placeholder that will always be mocked
        return [];
    }
    
    public function processImageBlob(string $src, array $params): array
    {
        // This is a placeholder that will always be mocked
        return [];
    }
}
