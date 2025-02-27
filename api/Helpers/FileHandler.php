<?php
declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Uploader\FileUploader;
use Glueful\Auth\AuthenticationService;
use Glueful\Http\Response;
use Glueful\APIEngine;
use Glueful\Uploader\Storage\StorageInterface;

class FileHandler {
    private AuthenticationService $auth;
    private FileUploader $uploader;

    public function __construct() {
    	$this->uploader = new FileUploader();
    	$this->auth = new AuthenticationService();
    }

    public function handleFileUpload(array $getParams, array $fileParams): array 
    {
        try {
            $token = $this->auth->extractTokenFromRequest();
        
            if (!$token) {
                echo json_encode(Response::unauthorized('Authentication required')->send());
                exit;
            }

            return  $this->uploader->handleUpload($token, $getParams, $fileParams);

        } catch (\Exception $e) {
            return Response::error('File upload failed: ' . $e->getMessage())->send();
        }
    }

    public function handleBase64Upload(array $getParams, array $postParams): array 
    {
        try {

            $token = $this->auth->extractTokenFromRequest();
            if (!$token) {
                echo json_encode(Response::unauthorized('Authentication required')->send());
                exit;
            }
            
            $_GET['token'] = $token; // Store token for downstream use
            
            // Convert base64 to temp file
            $tmpFile = $this->uploader->handleBase64Upload($postParams['base64']);
            
            $fileParams = [
                'name' => $getParams['name'] ?? 'upload.jpg',
                'type' => $getParams['mime_type'] ?? 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => filesize($tmpFile)
            ];
            return $this->uploader->handleUpload(
                $getParams['token'],
                $getParams,
                ['file' => $fileParams]
            );

        } catch (\Exception $e) {

            return Response::error('Base64 upload failed: ' . $e->getMessage())->send();
        }
    }

    /**
     * Get file blob by UUID
     * 
     * Retrieves file information and content by UUID.
     * Supports different response types:
     * - info: Returns file metadata
     * - download: Serves file for download
     * - inline: Displays file in browser
     * - image: Processes and returns image with optional transformations
     * 
     * @param string $uuid File UUID
     * @param string $type Response type (info|download|inline|image)
     * @param array $params Additional parameters for image processing
     * @return array Response with file data or info
     */
    public function getBlob(string $uuid, string $type = 'info', array $params = []): array
    {
        try {
            // Validate parameters
            if (empty($uuid)) {
                return Response::error('File UUID is required', Response::HTTP_BAD_REQUEST)->send();
            }
            
            // Get file information from database
            $fileInfo = $this->getBlobInfo($uuid);
            if (!$fileInfo) {
                return Response::notFound('File not found')->send();
            }
            
            // Get storage driver based on file storage type
            $storage = $this->getStorageDriver($fileInfo['storage_type'] ?? 'local');
            
            // Get full URL to the file
            $fullUrl = $storage->getUrl($fileInfo['filepath']);
            
            // Process based on requested type
            return match($type) {
                'info' => Response::ok($this->getBlobAsFile($storage, $fileInfo), 'File information retrieved')->send(),
                'download' => $this->downloadBlob($storage, $fileInfo),
                'inline' => $this->serveFileInline($fileInfo, $storage),
                'image' => Response::ok($this->processImageBlob($fullUrl, $params), 'Image processed successfully')->send(),
                default => Response::error('Invalid file retrieval type', Response::HTTP_BAD_REQUEST)->send()
            };
            
        } catch (\Exception $e) {
            error_log("Blob processing error: " . $e->getMessage());
            return Response::error('File retrieval failed: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }
    
    /**
     * Get file information from database
     * 
     * @param string $uuid File UUID
     * @return array|null File information or null if not found
     */
    private function getBlobInfo(string $uuid): ?array
    {
        try {
            // Get file information from files table
            $fileData = APIEngine::getData('blobs', 'view', [
                'fields' => 'uuid,filename,filepath,mime_type,file_size,storage_type,created_at,updated_at,status',
                'uuid' => $uuid
            ]);
            
            return $fileData[0] ?? null;
        } catch (\Exception $e) {
            error_log("Error retrieving file info: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Format file information for response
     * 
     * @param StorageInterface $storage Storage driver instance
     * @param array $fileInfo Raw file info from database
     * @return array Formatted file information
     */
    private function getBlobAsFile(StorageInterface $storage, array $fileInfo): array
    {
        return [
            'uuid' => $fileInfo['uuid'],
            'url' => $storage->getUrl($fileInfo['filepath']),
            'name' => $fileInfo['filename'] ?? basename($fileInfo['filepath']),
            'mime_type' => $fileInfo['mime_type'],
            'size' => $fileInfo['file_size'] ?? 0,
            'type' => $this->getFileType($fileInfo['mime_type']),
            'created_at' => $fileInfo['created_at'],
            'updated_at' => $fileInfo['updated_at'],
            'status' => $fileInfo['status'] ?? 'active',
            'storage_type' => $fileInfo['storage_type'] ?? 'local'
        ];
    }
    
    /**
     * Determine general file type from MIME type
     * 
     * @param string $mimeType MIME type of the file
     * @return string General file type (image|video|audio|document|archive|other)
     */
    private function getFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            return 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        } elseif (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv'
        ])) {
            return 'document';
        } elseif (in_array($mimeType, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip'
        ])) {
            return 'archive';
        } else {
            return 'other';
        }
    }
    
    /**
     * Serve file for download
     * 
     * @param StorageInterface $storage Storage driver
     * @param array $fileInfo File information
     * @return array Response data
     */
    private function downloadBlob(StorageInterface $storage, array $fileInfo): array
    {
        $filename = $fileInfo['filename'] ?? basename($fileInfo['filepath']);
        $mime = $fileInfo['mime_type'] ?? 'application/octet-stream';
        
        // Check if file status is active
        if (isset($fileInfo['status']) && $fileInfo['status'] !== 'active') {
            return Response::error('File is not available', Response::HTTP_FORBIDDEN)->send();
        }
        
        // For remote storage (like S3), redirect to signed URL
        if ($storage instanceof \Glueful\Uploader\Storage\S3Storage) {
            $url = $storage->getSignedUrl($fileInfo['filepath'], 300); // 5 min expiry
            header('Location: ' . $url);
            exit;
        }
        
        // For local storage, serve the file
        $path = config('paths.uploads') . '/' . $fileInfo['filepath'];
        if (file_exists($path)) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . ($fileInfo['file_size'] ?? filesize($path)));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($path);
            exit;
        }
        
        return Response::error('File not found on storage', Response::HTTP_NOT_FOUND)->send();
    }
    
    /**
     * Serve file for inline viewing
     * 
     * @param array $fileInfo File information
     * @param StorageInterface $storage Storage driver
     * @return array Response data
     */
    private function serveFileInline(array $fileInfo, StorageInterface $storage): array
    {
        $filename = $fileInfo['filename'] ?? basename($fileInfo['filepath']);
        $mime = $fileInfo['mime_type'] ?? 'application/octet-stream';
        
        // Check if file status is active
        if (isset($fileInfo['status']) && $fileInfo['status'] !== 'active') {
            return Response::error('File is not available', Response::HTTP_FORBIDDEN)->send();
        }
        
        // For remote storage (like S3), redirect to signed URL
        if ($storage instanceof \Glueful\Uploader\Storage\S3Storage) {
            $url = $storage->getSignedUrl($fileInfo['filepath'], 300); // 5 min expiry
            header('Location: ' . $url);
            exit;
        }
        
        // For local storage, serve the file
        $path = config('paths.uploads') . '/' . $fileInfo['filepath'];
        if (file_exists($path)) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . ($fileInfo['file_size'] ?? filesize($path)));
            header('Cache-Control: max-age=86400, public'); // Cache for a day
            readfile($path);
            exit;
        }
        
        return Response::error('File not found on storage', Response::HTTP_NOT_FOUND)->send();
    }
    
    /**
     * Process image with transformations
     * 
     * @param string $src Source image URL
     * @param array $params Image processing parameters
     * @return array Response with processed image data
     */
    private function processImageBlob(string $src, array $params): array
    {
        try {
            $config = [
                'maxWidth' => 1500,
                'maxHeight' => 1500,
                'quality' => (int)($params['q'] ?? 90),
                'width' => isset($params['w']) ? (int)$params['w'] : null,
                'height' => isset($params['h']) ? (int)$params['h'] : null,
                'zoom' => isset($params['z']) ? (int)$params['z'] : null,
                'memoryLimit' => '256M',
                'allowExternal' => true,
                'cacheDir' => config('paths.cache') . '/images'
            ];

            // Use TimThumb or appropriate image processor
            $thumbnailer = new \Glueful\ImageProcessing\TimThumb($config);
            
            if (!$thumbnailer->processImage($src)) {
                throw new \RuntimeException("Failed to process image");
            }

            // Generate cache path and filename
            $cacheKey = md5($src . serialize($config));
            $cachedFilename = $cacheKey . '.jpg';
            $cachePath = $config['cacheDir'] . '/' . $cachedFilename;

            // Ensure cache directory exists and save image
            if (!is_dir($config['cacheDir'])) {
                mkdir($config['cacheDir'], 0755, true);
            }

            // Capture image data
            ob_start();
            $thumbnailer->outputImage();
            $imageData = ob_get_clean();

            if (!file_put_contents($cachePath, $imageData)) {
                throw new \RuntimeException("Failed to save processed image");
            }

            // Get image dimensions
            $imgSize = getimagesizefromstring($imageData);
            $width = $imgSize[0] ?? $config['width'] ?? null;
            $height = $imgSize[1] ?? $config['height'] ?? null;

            // Use storage driver for caching
            $storage = $this->getStorageDriver('local');
            $cachedPath = 'cache/images/' . $cachedFilename;
            
            if ($storage->store($cachePath, $cachedPath)) {
                return [
                    'url' => $storage->getUrl($cachedPath),
                    'cached' => true,
                    'dimensions' => [
                        'width' => $width,
                        'height' => $height
                    ],
                    'size' => strlen($imageData),
                    'params' => array_intersect_key($params, array_flip(['w', 'h', 'q', 'z']))
                ];
            }

            // Fallback to original URL if caching fails
            return [
                'url' => $src,
                'cached' => false,
                'error' => null
            ];

        } catch (\Exception $e) {
            error_log("Image processing error: " . $e->getMessage());
            return [
                'url' => $src,
                'cached' => false,
                'error' => 'Failed to process image: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if a file exists by UUID
     * 
     * Verifies if a file exists in the database and optionally on disk
     * 
     * @param string $uuid File UUID to check
     * @param bool $checkDisk Whether to also check if file exists on disk/storage
     * @return bool True if file exists, false otherwise
     */
    public function fileExists(string $uuid, bool $checkDisk = false): bool
    {
        try {
            // Get file info from database
            $fileInfo = $this->getBlobInfo($uuid);
            
            // If file not found in database, return false
            if (!$fileInfo) {
                return false;
            }
            
            // If we don't need to check disk, return true as file exists in DB
            if (!$checkDisk) {
                return true;
            }
            
            // Get storage driver based on file storage type
            $storage = $this->getStorageDriver($fileInfo['storage_type'] ?? 'local');
            
            // Check if file exists in storage
            return $storage->exists($fileInfo['filepath']);
            
        } catch (\Exception $e) {
            error_log("Error checking file existence: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a file
     * 
     * Removes a file from storage and updates database record
     * 
     * @param string $uuid UUID of file to delete
     * @return bool True if file was successfully deleted, false otherwise
     */
    public function deleteFile(string $uuid): bool
    {
        try {
            // First check if file exists
            if (!$this->fileExists($uuid)) {
                return false;
            }
            
            // Get file info from database
            $fileInfo = $this->getBlobInfo($uuid);
            
            // Get storage driver based on file storage type
            $storage = $this->getStorageDriver($fileInfo['storage_type'] ?? 'local');
            
            // Try to delete the physical file
            $fileDeleted = $storage->delete($fileInfo['filepath']);
            
            // Update the file status in the database to deleted
            $dbUpdated = APIEngine::saveData(
                'blobs',
                'update',
                [
                    'uuid' => $uuid,
                    'status' => 'deleted',
                ]
            );
            
            // Return true if either operation succeeded
            // We consider it successful if we update the DB even if physical delete fails
            return $dbUpdated || $fileDeleted;
            
        } catch (\Exception $e) {
            error_log("Error deleting file: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get storage driver instance
     * 
     * Returns the appropriate storage driver based on configuration
     * or the specified storage type
     * 
     * @param string $storageType Storage type (local, s3, etc.)
     * @return StorageInterface Storage driver instance
     */
    private function getStorageDriver(string $storageType = null): StorageInterface 
    {
        // If no storage type is specified, use the configured default
        $storageType = $storageType ?? config('storage.driver', 'local');
        
        return match($storageType) {
            's3' => new \Glueful\Uploader\Storage\S3Storage(
                config('storage.s3.key'),
                config('storage.s3.secret'),
                config('storage.s3.region'),
                config('storage.s3.bucket'),
                config('storage.s3.endpoint')
            ),
            default => new \Glueful\Uploader\Storage\LocalStorage(
                config('paths.uploads'),
                config('paths.cdn')
            )
        };
    }
}