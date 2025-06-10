<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Uploader\FileUploader;
use Glueful\Repository\RepositoryFactory;
use Glueful\Repository\Interfaces\RepositoryInterface;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\{AuditLogger, AuditEvent};
use Glueful\Permissions\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Files Controller
 *
 * Handles file operations such as uploads, downloads, and retrieval.
 * Provides endpoints for working with file resources in the application.
 * Extends BaseController for authentication, permissions, and caching capabilities.
 *
 * @package Glueful\Controllers
 */
class FilesController extends BaseController
{
    private FileUploader $fileUploader;
    private RepositoryInterface $blobRepository;

    /**
     * Constructor
     *
     * @param RepositoryFactory|null $repositoryFactory Repository factory instance
     * @param AuthenticationManager|null $authManager Authentication manager
     * @param AuditLogger|null $auditLogger Audit logger instance
     * @param SymfonyRequest|null $request Request instance
     * @param FileUploader|null $fileUploader File uploader instance
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?SymfonyRequest $request = null,
        ?FileUploader $fileUploader = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        // Initialize file uploader
        $this->fileUploader = $fileUploader ?? new FileUploader();

        // Get blob repository from factory
        $this->blobRepository = $this->repositoryFactory->getRepository('Blob');
    }

    /**
     * Retrieve a file by UUID
     *
     * Gets a file and can return different formats based on type parameter:
     * - info: Returns file metadata only
     * - image: Returns image with optional resizing
     * - download: Forces download of file
     * - inline: Displays file in browser if possible
     *
     * @return mixed HTTP response
     */
    public function getFile()
    {
        // Authentication handled by AuthenticationMiddleware
        $request = new Request();
        $requestData = $request->getQueryParams();

        // Get parameters from request
        $uuid = $requestData['uuid'] ?? null;
        $type = $requestData['type'] ?? 'info';

        // Apply rate limiting for file access
        $this->applyFileAccessRateLimiting($type);

        if (!isset($uuid)) {
            return Response::error('File UUID is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Process image parameters if needed
        $params = [];
        if ($type === 'image') {
            $params = [
                'w' => $requestData['w'] ?? null,
                'h' => $requestData['h'] ?? null,
                'q' => $requestData['q'] ?? 80,
                'z' => $requestData['z'] ?? null
            ];
        }

        // Get the blob data from repository
        $fileInfo = $this->blobRepository->find($uuid);
        if (!$fileInfo) {
            return Response::error('File not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Check file access permissions using permission system
        // Use different permissions based on access type
        $permission = match ($type) {
            'download' => 'files.download',
            'image' => 'files.image.process',
            'inline' => 'files.view',
            default => 'files.read'
        };

        $this->requirePermission($permission, 'files:' . $uuid, [
            'file_owner' => $fileInfo['created_by'],
            'file_type' => $fileInfo['mime_type'],
            'access_type' => $type
        ]);

        // Apply adaptive rate limiting with file context
        $this->applyAdaptiveRateLimiting($fileInfo, $type);

        // Process the file based on type with caching
        $result = $this->processFileRequestWithCaching($fileInfo, $type, $params);

        // Enhanced audit logging with permission context
        $this->auditWithPermissionContext(
            'file_access',
            AuditEvent::SEVERITY_INFO,
            [
                'access_type' => $type,
                'parameters' => $params,
                'cached' => $result['cached'] ?? false,
                'permission_used' => $permission
            ],
            $fileInfo
        );

        // For download and inline types, handle ETag and caching headers
        if (in_array($type, ['download', 'inline'])) {
            return $this->handleFileServing($fileInfo, $type, $params);
        }

        // Return cached response with appropriate headers
        return $this->withCacheHeaders(
            Response::ok($result, 'File retrieved successfully'),
            $this->getFileCacheOptions($type)
        )->send();
    }

    /**
     * Upload a file
     *
     * Handles both multipart/form-data uploads and base64 encoded uploads.
     * Stores files and returns file metadata including UUID.
     *
     * @return mixed HTTP response
     */
    public function uploadFile()
    {
        // Authentication handled by AuthenticationMiddleware
        // Check upload permission
        $this->requirePermission('files.create');

        // Apply rate limiting for file uploads
        $this->rateLimitResource('files', 'write', 10, 300); // 10 uploads per 5 minutes
        $this->requireLowRiskBehavior(0.7, 'file_upload');

        $request = new Request();
        $contentType = $request->getContentType();

        // Handle multipart form data (regular file upload)
        if (strpos($contentType, 'multipart/form-data') !== false) {
            if (empty($request->getFiles())) {
                return Response::error('No file uploaded', Response::HTTP_BAD_REQUEST)->send();
            }

            // Add current user UUID to the request params for file ownership
            $params = array_merge($request->getQueryParams(), [
                'created_by' => $this->currentUser->uuid
            ]);

            // Check file-specific upload permissions and apply additional rate limiting
            $files = $request->getFiles();
            $this->checkUploadPermissions($files);
            $this->applyFileSpecificRateLimiting($files);

            $result = $this->fileUploader->handleUpload(
                $this->currentToken,
                $params,
                $files
            );

            // Enhanced audit logging for file upload
            $uploadFileInfo = [
                'uuid' => $result['uuid'] ?? null,
                'name' => $result['filename'] ?? 'unknown',
                'size' => $result['size'] ?? 0,
                'mime_type' => $result['mime_type'] ?? 'unknown',
                'created_by' => $this->currentUser->uuid
            ];

            $this->auditWithPermissionContext(
                'file_upload',
                AuditEvent::SEVERITY_INFO,
                [
                    'upload_method' => 'multipart',
                    'file_count' => count($files),
                    'total_size' => array_sum(array_column($files, 'size')),
                    'upload_success' => isset($result['uuid'])
                ],
                $uploadFileInfo
            );

            // Invalidate user-specific file cache after upload
            $this->invalidateUserFileCache();

            return Response::ok($result, 'File uploaded successfully')->send();
        } else {
            // Handle JSON/base64 upload
            $postData = Request::getPostData();
            if (!isset($postData['base64'])) {
                return Response::error('Base64 content required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Add current user UUID to the request params for file ownership
            $params = array_merge($request->getQueryParams(), [
                'created_by' => $this->currentUser->uuid
            ]);

            // Check base64 upload permissions
            $this->checkBase64UploadPermissions($postData, $params);

            $result = $this->handleBase64Upload($params, $postData);

            // Enhanced audit logging for base64 upload
            $base64FileInfo = [
                'uuid' => $result['uuid'] ?? null,
                'name' => $result['filename'] ?? 'unknown',
                'size' => $result['size'] ?? 0,
                'mime_type' => $result['mime_type'] ?? 'unknown',
                'created_by' => $this->currentUser->uuid
            ];

            $this->auditWithPermissionContext(
                'file_upload_base64',
                AuditEvent::SEVERITY_INFO,
                [
                    'upload_method' => 'base64',
                    'estimated_size' => strlen($postData['base64']) * 0.75,
                    'upload_success' => isset($result['uuid'])
                ],
                $base64FileInfo
            );

            return Response::ok($result, 'File uploaded successfully')->send();
        }
    }

    /**
     * Delete a file
     *
     * Removes a file from storage based on UUID.
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function deleteFile(array $params)
    {
        // Authentication handled by AuthenticationMiddleware
        // Apply rate limiting for delete operations (more restrictive)
        $this->rateLimitResource('files', 'delete', 5, 300); // 5 deletes per 5 minutes
        $this->requireLowRiskBehavior(0.6, 'file_delete'); // Stricter behavior check

        $uuid = $params['uuid'] ?? null;

        if (!$uuid) {
            return Response::error('File UUID is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Check if file exists using repository
        if ($this->blobRepository->exists($uuid)) {
            // Get file metadata before deletion for permission and audit check
            $fileInfo = $this->blobRepository->find($uuid);

            // Check delete permission with file context
            $this->requirePermission('files.delete', 'files:' . $uuid, [
                'file_owner' => $fileInfo['created_by'],
                'file_type' => $fileInfo['mime_type'],
                'file_size' => $fileInfo['size']
            ]);

            // Apply adaptive rate limiting for delete operations
            $this->applyAdaptiveRateLimiting($fileInfo, 'delete');

            $result = $this->blobRepository->softDelete($uuid);

            // Invalidate caches after successful deletion
            if ($result) {
                $this->invalidateFileCache($uuid);
                $this->invalidateUserFileCache();
            }

            // Enhanced audit logging for file deletion
            if ($result) {
                $this->auditWithPermissionContext(
                    'file_delete',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'deletion_success' => true,
                        'was_own_file' => $fileInfo['created_by'] === $this->currentUser->uuid,
                        'file_age_days' => $this->calculateFileAge($fileInfo)
                    ],
                    $fileInfo
                );
            }
        } else {
            $result = false;
            // Enhanced audit logging for attempted deletion of non-existent file
            $this->auditWithPermissionContext(
                'file_delete_not_found',
                AuditEvent::SEVERITY_WARNING,
                [
                    'attempted_file_uuid' => $uuid,
                    'deletion_attempt' => true,
                    'file_exists' => false
                ]
            );
        }

        if (!$result) {
            return Response::error('File not found or could not be deleted', Response::HTTP_NOT_FOUND)->send();
        }

        return Response::ok(['uuid' => $uuid], 'File deleted successfully')->send();
    }

    /**
     * Get file listing with permission-aware caching
     *
     * @return mixed HTTP response
     */
    public function getFileList()
    {
        // Authentication handled by AuthenticationMiddleware
        // Check list permission
        $this->requirePermission('files.list');

        // Apply rate limiting for file listing
        $this->rateLimitResource('files', 'read', 50, 60); // 50 listings per minute

        $request = new Request();
        $requestData = $request->getQueryParams();

        $page = (int)($requestData['page'] ?? 1);
        $perPage = min((int)($requestData['per_page'] ?? 25), 100); // Max 100 per page
        $conditions = [];

        // Add filter conditions if provided
        if (isset($requestData['mime_type'])) {
            $conditions['mime_type'] = $requestData['mime_type'];
        }

        // Get cached paginated files with permission awareness
        $result = $this->getCachedPaginatedFiles($page, $perPage, $conditions);

        // Enhanced audit logging for file listing access
        $this->auditWithPermissionContext(
            'file_list_access',
            AuditEvent::SEVERITY_INFO,
            [
                'page' => $page,
                'per_page' => $perPage,
                'conditions' => $conditions,
                'total_files' => $result['total'] ?? 0,
                'filtered_by_ownership' => !$this->isAdmin()
            ]
        );

        // Return with cache headers for file listings
        return $this->withCacheHeaders(
            Response::ok($result, 'File list retrieved successfully'),
            [
                'public' => false, // User-specific data
                'max_age' => 300, // 5 minutes
                'must_revalidate' => true,
                'vary' => ['Authorization']
            ]
        )->send();
    }

    /**
     * Process file request based on type
     *
     * @param array $fileInfo File information from repository
     * @param string $type Request type (info|download|inline|image)
     * @param array $params Additional parameters for processing
     * @return array Processed file data
     */
    private function processFileRequest(array $fileInfo, string $type, array $params = []): array
    {
        return match ($type) {
            'info' => [
                'uuid' => $fileInfo['uuid'],
                'url' => $fileInfo['url'],
                'name' => $fileInfo['name'],
                'mime_type' => $fileInfo['mime_type'],
                'size' => $fileInfo['size'],
                'type' => $this->getFileType($fileInfo['mime_type']),
                'created_at' => $fileInfo['created_at'],
                'updated_at' => $fileInfo['updated_at'],
                'status' => $fileInfo['status'] ?? 'active'
            ],
            'download', 'inline', 'image' => $this->processFileServing($fileInfo, $type, $params),
            default => throw new \InvalidArgumentException('Invalid file retrieval type')
        };
    }

    /**
     * Handle base64 file upload
     *
     * @param array $getParams Query parameters
     * @param array $postData POST data containing base64 content
     * @return array Upload result
     */
    private function handleBase64Upload(array $getParams, array $postData): array
    {
        try {
            // Convert base64 to temp file
            $tmpFile = $this->fileUploader->handleBase64Upload($postData['base64']);

            $fileParams = [
                'name' => $getParams['name'] ?? 'upload.jpg',
                'type' => $getParams['mime_type'] ?? 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => filesize($tmpFile)
            ];

            return $this->fileUploader->handleUpload(
                $this->currentToken,
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
        } elseif (
            in_array($mimeType, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv'
            ])
        ) {
            return 'document';
        } elseif (
            in_array($mimeType, [
                'application/zip',
                'application/x-rar-compressed',
                'application/x-7z-compressed',
                'application/x-tar',
                'application/gzip'
            ])
        ) {
            return 'archive';
        } else {
            return 'other';
        }
    }

    /**
     * Process file serving for download, inline, or image requests
     *
     * @param array $fileInfo File information
     * @param string $type Serving type
     * @param array $params Additional parameters
     * @return array Response data
     */
    private function processFileServing(array $fileInfo, string $type, array $params = []): array
    {
        // Check if file status is active
        if (($fileInfo['status'] ?? 'active') !== 'active') {
            return [
                'success' => false,
                'message' => 'File is not available',
                'code' => 403
            ];
        }

        $filename = $fileInfo['name'] ?? 'download';
        $mimeType = $fileInfo['mime_type'] ?? 'application/octet-stream';
        $fileUrl = $fileInfo['url'] ?? '';

        return match ($type) {
            'download' => $this->serveFileDownload($fileInfo, $filename, $mimeType),
            'inline' => $this->serveFileInline($fileInfo, $filename, $mimeType),
            'image' => $this->processImageServing($fileUrl, $params),
            default => [
                'uuid' => $fileInfo['uuid'],
                'url' => $fileUrl,
                'name' => $filename,
                'mime_type' => $mimeType,
                'type' => $type
            ]
        };
    }

    /**
     * Serve file for download
     *
     * @param array $fileInfo File information
     * @param string $filename Filename for download
     * @param string $mimeType File MIME type
     * @return array Response data
     */
    private function serveFileDownload(array $fileInfo, string $filename, string $mimeType): array
    {
        $fileUrl = $fileInfo['url'] ?? '';
        $fileSize = $fileInfo['size'] ?? 0;

        // For direct file serving, set appropriate headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($fileSize > 0) {
            header('Content-Length: ' . $fileSize);
        }
        header('Cache-Control: private, max-age=3600');

        return [
            'uuid' => $fileInfo['uuid'],
            'url' => $fileUrl,
            'name' => $filename,
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'type' => 'download'
        ];
    }

    /**
     * Serve file for inline viewing
     *
     * @param array $fileInfo File information
     * @param string $filename Filename
     * @param string $mimeType File MIME type
     * @return array Response data
     */
    private function serveFileInline(array $fileInfo, string $filename, string $mimeType): array
    {
        $fileUrl = $fileInfo['url'] ?? '';
        $fileSize = $fileInfo['size'] ?? 0;

        // For inline viewing, set appropriate headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        if ($fileSize > 0) {
            header('Content-Length: ' . $fileSize);
        }
        header('Cache-Control: public, max-age=86400'); // Cache for a day

        return [
            'uuid' => $fileInfo['uuid'],
            'url' => $fileUrl,
            'name' => $filename,
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'type' => 'inline'
        ];
    }

    /**
     * Process image serving with transformations
     *
     * @param string $imageUrl Source image URL
     * @param array $params Image processing parameters
     * @return array Response with processed image data
     */
    private function processImageServing(string $imageUrl, array $params): array
    {
        $config = [
            'maxWidth' => 1500,
            'maxHeight' => 1500,
            'quality' => (int)($params['q'] ?? 90),
            'width' => isset($params['w']) ? (int)$params['w'] : null,
            'height' => isset($params['h']) ? (int)$params['h'] : null,
            'zoom' => isset($params['z']) ? (int)$params['z'] : null,
        ];

        // For now, return the configuration - actual image processing
        // would integrate with an image processing service
        return [
            'url' => $imageUrl,
            'processing_config' => $config,
            'processed' => false,
            'message' => 'Image processing integration needed'
        ];
    }

    /**
     * Check upload permissions based on file characteristics
     *
     * @param array $files Array of file upload data
     * @throws UnauthorizedException If permission is denied
     */
    private function checkUploadPermissions(array $files): void
    {
        foreach ($files as $fileData) {
            $fileSize = $fileData['size'] ?? 0;
            $mimeType = $fileData['type'] ?? 'application/octet-stream';
            $fileName = $fileData['name'] ?? 'unknown';

            // Check file size permissions
            if ($fileSize > 50 * 1024 * 1024) { // 50MB
                $this->requirePermission('files.upload.large', 'system', [
                    'file_size' => $fileSize,
                    'file_name' => $fileName,
                    'mime_type' => $mimeType
                ]);
            }

            // Check file type permissions
            if (str_starts_with($mimeType, 'video/')) {
                $this->requirePermission('files.upload.video', 'system', [
                    'file_size' => $fileSize,
                    'mime_type' => $mimeType
                ]);
            } elseif (str_starts_with($mimeType, 'application/')) {
                $this->requirePermission('files.upload.executable', 'system', [
                    'file_size' => $fileSize,
                    'mime_type' => $mimeType
                ]);
            }
        }
    }

    /**
     * Check base64 upload permissions
     *
     * @param array $postData Base64 upload data
     * @param array $params Upload parameters
     * @throws UnauthorizedException If permission is denied
     */
    private function checkBase64UploadPermissions(array $postData, array $params): void
    {
        $base64Data = $postData['base64'] ?? '';
        $estimatedSize = strlen($base64Data) * 0.75; // Base64 is ~33% larger than binary
        $mimeType = $params['mime_type'] ?? 'image/jpeg';

        // Check size limits for base64 uploads
        if ($estimatedSize > 10 * 1024 * 1024) { // 10MB for base64
            $this->requirePermission('files.upload.base64.large', 'system', [
                'estimated_size' => $estimatedSize,
                'mime_type' => $mimeType
            ]);
        }

        // Base64 uploads are typically images, check image permissions
        if (str_starts_with($mimeType, 'image/')) {
            $this->requirePermission('files.upload.image', 'system', [
                'estimated_size' => $estimatedSize,
                'mime_type' => $mimeType
            ]);
        }
    }

    /**
     * Apply file access rate limiting based on access type
     *
     * @param string $accessType Type of file access (info, download, image, inline)
     */
    private function applyFileAccessRateLimiting(string $accessType): void
    {
        switch ($accessType) {
            case 'download':
                // Burst limiting for downloads: allow 5 rapid downloads, then 30 per hour
                $this->burstRateLimit('file_download', 5, 60, 3600);
                break;

            case 'image':
                // Image processing is CPU intensive, limit more strictly
                $this->rateLimitResource('files', 'read', 20, 60); // 20 per minute
                $this->requireLowRiskBehavior(0.8, 'image_processing');
                break;

            case 'inline':
                // Inline viewing has moderate limits
                $this->rateLimitResource('files', 'read', 50, 60); // 50 per minute
                break;

            case 'info':
            default:
                // Info requests are lightweight, allow more
                $this->rateLimitResource('files', 'read', 100, 60); // 100 per minute
                break;
        }

        // Apply conditional rate limiting based on user type
        $this->conditionalRateLimit('file_access_' . $accessType);
    }

    /**
     * Apply file-specific rate limiting based on file characteristics
     *
     * @param array $files Array of file upload data
     */
    private function applyFileSpecificRateLimiting(array $files): void
    {
        foreach ($files as $fileData) {
            $fileSize = $fileData['size'] ?? 0;
            $mimeType = $fileData['type'] ?? 'application/octet-stream';

            // Apply stricter limits for large files
            if ($fileSize > 10 * 1024 * 1024) { // 10MB+
                $this->rateLimitResource('files', 'write', 3, 600); // 3 large files per 10 minutes
            }

            // Apply stricter limits for video files
            if (str_starts_with($mimeType, 'video/')) {
                $this->rateLimitResource('files', 'write', 2, 1800); // 2 videos per 30 minutes
                $this->requireLowRiskBehavior(0.5, 'video_upload'); // Very strict behavior check
            }

            // Monitor for rapid consecutive uploads (potential abuse)
            if (count($files) > 5) {
                $this->requireLowRiskBehavior(0.4, 'bulk_upload');
            }
        }

        // Apply multi-level rate limiting for uploads
        $this->multiLevelRateLimit([
            'ip' => ['attempts' => 15, 'window' => 300, 'adaptive' => true], // 15 per 5min per IP
            'user' => ['attempts' => 10, 'window' => 300, 'adaptive' => true], // 10 per 5min per user
            'endpoint' => ['attempts' => 100, 'window' => 300, 'adaptive' => false] // 100 per 5min global
        ]);
    }

    /**
     * Apply adaptive rate limiting with enhanced monitoring
     *
     * @param array $fileInfo File information for context
     * @param string $operation Operation being performed
     */
    private function applyAdaptiveRateLimiting(array $fileInfo, string $operation): void
    {
        // Create comprehensive context for behavior analysis
        // Context is used by requireLowRiskBehavior for adaptive rate limiting

        // Apply different behavior thresholds based on operation sensitivity
        $behaviorThreshold = match ($operation) {
            'delete' => 0.3, // Very strict for deletions
            'bulk_download' => 0.4, // Strict for bulk operations
            'video_upload' => 0.5, // Strict for resource-intensive uploads
            'large_file_upload' => 0.6, // Moderate for large files
            'download' => 0.7, // Normal for downloads
            'view' => 0.8, // Relaxed for viewing
            default => 0.7
        };

        $this->requireLowRiskBehavior($behaviorThreshold, $operation);

        // Additional monitoring for suspicious patterns
        if ($operation === 'download' && isset($fileInfo['created_by'])) {
            // Flag if user is accessing many files from different owners
            $this->detectCrossOwnerAccess($fileInfo['created_by']);
        }

        // Track any unusual access patterns
        $suspiciousIndicators = $this->detectSuspiciousPatterns($fileInfo, $operation);
        if (!empty($suspiciousIndicators)) {
            $this->auditSuspiciousFileAccess($fileInfo, $operation, $suspiciousIndicators);
        }
    }

    /**
     * Detect potential suspicious cross-owner file access
     *
     * @param string $fileOwner Owner of the file being accessed
     */
    private function detectCrossOwnerAccess(string $fileOwner): void
    {
        // Only monitor if accessing files from other users
        if ($fileOwner !== $this->currentUser->uuid) {
            // Apply stricter rate limiting for cross-owner access
            $this->rateLimit('cross_owner_access', 10, 3600, true); // 10 per hour with adaptive limiting

            // Require very low risk behavior for accessing others' files
            $this->requireLowRiskBehavior(0.3, 'cross_owner_file_access');
        }
    }

    /**
     * Process file request with caching support
     *
     * @param array $fileInfo File information from repository
     * @param string $type Request type (info|download|inline|image)
     * @param array $params Additional parameters for processing
     * @return array Processed file data with caching metadata
     */
    private function processFileRequestWithCaching(array $fileInfo, string $type, array $params = []): array
    {
        // For info requests, use permission-aware caching
        if ($type === 'info') {
            return $this->cacheByPermission(
                "file_info_{$fileInfo['uuid']}",
                fn() => $this->processFileRequest($fileInfo, $type, $params),
                3600 // 1 hour cache for file info
            );
        }

        // For image processing, cache results based on parameters
        if ($type === 'image') {
            $cacheKey = "file_image_{$fileInfo['uuid']}_" . md5(serialize($params));
            return $this->cacheResponse(
                $cacheKey,
                fn() => $this->processFileRequest($fileInfo, $type, $params),
                1800, // 30 minutes for processed images
                ['files', 'file:' . $fileInfo['uuid'], 'image_processing']
            );
        }

        // For download and inline, don't cache the data but return file info
        return $this->processFileRequest($fileInfo, $type, $params);
    }

    /**
     * Handle file serving with ETag support
     *
     * @param array $fileInfo File information
     * @param string $type Serving type (download|inline)
     * @param array $params Additional parameters
     * @return Response File serving response with appropriate headers
     */
    private function handleFileServing(array $fileInfo, string $type, array $params = []): Response
    {
        // Generate ETag based on file metadata
        $etag = $this->generateFileETag($fileInfo);

        // Check If-None-Match header for ETag validation
        $clientEtag = $this->request->headers->get('If-None-Match');
        if ($clientEtag === $etag) {
            // Return 304 Not Modified
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=3600, must-revalidate');
            exit;
        }

        // Process the file serving
        $result = $this->processFileRequest($fileInfo, $type, $params);

        // Create response with caching headers
        $response = Response::ok($result);

        // Add ETag and cache headers
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=3600, must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($fileInfo['updated_at'])) . ' GMT');

        // Add file-specific headers
        if ($type === 'download') {
            header('Content-Disposition: attachment; filename="' . ($fileInfo['name'] ?? 'download') . '"');
        }

        return $response;
    }

    /**
     * Get cache options for file responses
     *
     * @param string $type Access type
     * @return array Cache configuration
     */
    private function getFileCacheOptions(string $type): array
    {
        $baseOptions = [
            'public' => true,
            'vary' => ['Accept', 'Authorization'],
            'etag' => true
        ];

        return match ($type) {
            'info' => array_merge($baseOptions, [
                'max_age' => 3600, // 1 hour for file info
                's_maxage' => 1800, // 30 minutes for CDN
                'must_revalidate' => true
            ]),
            'image' => array_merge($baseOptions, [
                'max_age' => 1800, // 30 minutes for processed images
                's_maxage' => 3600, // 1 hour for CDN
                'must_revalidate' => false
            ]),
            default => array_merge($baseOptions, [
                'max_age' => 300, // 5 minutes for other types
                's_maxage' => 600, // 10 minutes for CDN
                'must_revalidate' => true
            ])
        };
    }

    /**
     * Generate ETag for file based on metadata
     *
     * @param array $fileInfo File information
     * @return string ETag value
     */
    private function generateFileETag(array $fileInfo): string
    {
        $etagData = [
            'uuid' => $fileInfo['uuid'],
            'size' => $fileInfo['size'],
            'updated_at' => $fileInfo['updated_at'],
            'user_permissions' => $this->isAdmin() ? 'admin' : 'user'
        ];

        return '"' . md5(serialize($etagData)) . '"';
    }

    /**
     * Invalidate user-specific file cache
     */
    private function invalidateUserFileCache(): void
    {
        $this->invalidateCache([
            'user:' . $this->currentUser->uuid,
            'files',
            'user_files'
        ]);
    }

    /**
     * Invalidate specific file cache
     *
     * @param string $fileUuid File UUID
     */
    private function invalidateFileCache(string $fileUuid): void
    {
        $this->invalidateCache([
            'file:' . $fileUuid,
            'files',
            'user:' . $this->currentUser->uuid
        ]);
    }

    /**
     * Create cached file listing with permission awareness
     *
     * @param array $conditions Query conditions
     * @param array $orderBy Order criteria
     * @param int $limit Result limit
     * @return array Cached file listing
     */
    private function getCachedFileListing(
        array $conditions = [],
        array $orderBy = ['created_at' => 'DESC'],
        int $limit = 25
    ): array {
        $cacheKey = 'file_listing_' . md5(serialize([
            'conditions' => $conditions,
            'order' => $orderBy,
            'limit' => $limit,
            'user' => $this->currentUser->uuid,
            'is_admin' => $this->isAdmin()
        ]));

        return $this->cacheByPermission(
            $cacheKey,
            function () use ($conditions, $orderBy, $limit) {
                // Add ownership filter for non-admin users
                if (!$this->isAdmin()) {
                    $conditions['created_by'] = $this->currentUser->uuid;
                }

                return $this->blobRepository->findWhere($conditions, $orderBy, $limit);
            },
            600 // 10 minutes for file listings
        );
    }

    /**
     * Create cached paginated file response
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param array $conditions Filter conditions
     * @param array $orderBy Sort criteria
     * @return array Paginated response with caching
     */
    private function getCachedPaginatedFiles(
        int $page = 1,
        int $perPage = 25,
        array $conditions = [],
        array $orderBy = ['created_at' => 'DESC']
    ): array {
        // Use BaseController's cachePaginatedResponse with permission awareness
        return $this->cachePaginatedResponse(
            'Blob',
            $page,
            $perPage,
            $this->addOwnershipConditions($conditions),
            $orderBy,
            300 // 5 minutes cache for pagination
        );
    }

    /**
     * Add ownership conditions for non-admin users
     *
     * @param array $conditions Existing conditions
     * @return array Conditions with ownership filter
     */
    private function addOwnershipConditions(array $conditions): array
    {
        if (!$this->isAdmin()) {
            $conditions['created_by'] = $this->currentUser->uuid;
        }

        return $conditions;
    }

    /**
     * Enhanced audit logging with permission context
     *
     * @param string $action Action being performed
     * @param string $severity Severity level
     * @param array $context Additional context data
     * @param array|null $fileInfo Optional file information
     */
    private function auditWithPermissionContext(
        string $action,
        string $severity,
        array $context = [],
        ?array $fileInfo = null
    ): void {
        $enhancedContext = array_merge($context, [
            // User context
            'user_uuid' => $this->currentUser->uuid,
            'user_is_admin' => $this->isAdmin(),
            'ip_address' => $this->request->getClientIp() ?? 'unknown',
            'user_agent' => $this->request->headers->get('User-Agent') ?? 'unknown',

            // Request context
            'request_id' => $this->request->headers->get('X-Request-ID') ?? uniqid(),
            'endpoint' => $this->request->getPathInfo(),
            'method' => $this->request->getMethod(),
            'timestamp' => date('Y-m-d H:i:s'),

            // Permission context
            'has_admin_privileges' => $this->isAdmin(),
            'permission_provider_active' => $this->hasPermissionProvider(),

            // Session context
            'session_duration' => $this->getSessionDuration(),
            'requests_in_session' => $this->getSessionRequestCount(),
        ]);

        // Add file-specific context if provided
        if ($fileInfo) {
            $enhancedContext = array_merge($enhancedContext, [
                'file_uuid' => $fileInfo['uuid'] ?? null,
                'file_owner' => $fileInfo['created_by'] ?? null,
                'file_type' => $fileInfo['mime_type'] ?? null,
                'file_size' => $fileInfo['size'] ?? null,
                'is_own_file' => ($fileInfo['created_by'] ?? null) === $this->currentUser->uuid,
                'file_age_days' => $this->calculateFileAge($fileInfo),
            ]);
        }

        $this->auditLogger->audit(
            AuditEvent::CATEGORY_AUTHZ,
            $action,
            $severity,
            $enhancedContext
        );
    }

    /**
     * Track rate limit violations with detailed context
     *
     * @param string $limitType Type of rate limit violated
     * @param array $limitDetails Details about the rate limit
     * @param array $additionalContext Additional context
     */
    private function auditRateLimitViolation(
        string $limitType,
        array $limitDetails,
        array $additionalContext = []
    ): void {
        $context = array_merge($additionalContext, [
            'violation_type' => $limitType,
            'limit_details' => $limitDetails,
            'violation_time' => date('Y-m-d H:i:s'),
            'user_behavior_score' => $this->getCurrentBehaviorScore(),
            'recent_violations' => $this->getRecentViolationCount(),
            'controller' => static::class,
        ]);

        $this->auditWithPermissionContext(
            'rate_limit_violation',
            AuditEvent::SEVERITY_WARNING,
            $context
        );
    }

    /**
     * Track suspicious file access patterns
     *
     * @param array $fileInfo File information
     * @param string $accessType Type of access
     * @param array $suspiciousIndicators Indicators of suspicious behavior
     */
    private function auditSuspiciousFileAccess(
        array $fileInfo,
        string $accessType,
        array $suspiciousIndicators
    ): void {
        $context = [
            'access_type' => $accessType,
            'suspicious_indicators' => $suspiciousIndicators,
            'risk_score' => $this->calculateRiskScore($suspiciousIndicators),
            'access_pattern' => $this->analyzeAccessPattern($fileInfo),
        ];

        $this->auditWithPermissionContext(
            'suspicious_file_access',
            AuditEvent::SEVERITY_ERROR,
            $context,
            $fileInfo
        );
    }

    /**
     * Track permission violations with context
     *
     * @param string $permission Permission that was checked
     * @param string $resource Resource being accessed
     * @param array $permissionContext Permission check context
     */
    private function auditPermissionViolation(
        string $permission,
        string $resource,
        array $permissionContext = []
    ): void {
        $context = array_merge($permissionContext, [
            'denied_permission' => $permission,
            'target_resource' => $resource,
            'permission_check_result' => 'denied',
            'attempted_escalation' => $this->detectPrivilegeEscalation($permission),
        ]);

        $this->auditWithPermissionContext(
            'permission_violation',
            AuditEvent::SEVERITY_WARNING,
            $context
        );
    }

    /**
     * Calculate session duration in minutes
     *
     * @return int Session duration in minutes
     */
    private function getSessionDuration(): int
    {
        // This would integrate with actual session tracking
        // For now, return a placeholder
        return 0;
    }

    /**
     * Get request count for current session
     *
     * @return int Number of requests in session
     */
    private function getSessionRequestCount(): int
    {
        // This would integrate with actual session tracking
        // For now, return a placeholder
        return 0;
    }

    /**
     * Calculate file age in days
     *
     * @param array $fileInfo File information
     * @return int File age in days
     */
    private function calculateFileAge(array $fileInfo): int
    {
        if (!isset($fileInfo['created_at'])) {
            return 0;
        }

        $created = strtotime($fileInfo['created_at']);
        $now = time();
        return (int)(($now - $created) / 86400); // 86400 seconds = 1 day
    }

    /**
     * Get current user's behavior score
     *
     * @return float Behavior score (0.0 to 1.0)
     */
    private function getCurrentBehaviorScore(): float
    {
        // This would integrate with the adaptive rate limiter
        // For now, return a placeholder
        return 0.5;
    }

    /**
     * Get recent violation count for user
     *
     * @return int Number of recent violations
     */
    private function getRecentViolationCount(): int
    {
        // This would query recent audit logs for violations
        // For now, return a placeholder
        return 0;
    }

    /**
     * Calculate risk score based on suspicious indicators
     *
     * @param array $indicators Suspicious behavior indicators
     * @return float Risk score (0.0 to 1.0)
     */
    private function calculateRiskScore(array $indicators): float
    {
        $score = 0.0;
        $maxScore = count($indicators);

        foreach ($indicators as $weight) {
            $score += is_numeric($weight) ? $weight : 0.1;
        }

        return $maxScore > 0 ? min($score / $maxScore, 1.0) : 0.0;
    }

    /**
     * Analyze file access patterns for anomalies
     *
     * @param array $fileInfo File information
     * @return array Access pattern analysis
     */
    private function analyzeAccessPattern(array $fileInfo): array
    {
        return [
            'file_owner_match' => ($fileInfo['created_by'] ?? null) === $this->currentUser->uuid,
            'file_age_days' => $this->calculateFileAge($fileInfo),
            'access_time_pattern' => $this->analyzeAccessTime(),
            'file_type_pattern' => $this->analyzeFileTypeAccess($fileInfo['mime_type'] ?? ''),
        ];
    }

    /**
     * Analyze access time patterns
     *
     * @return array Time-based access analysis
     */
    private function analyzeAccessTime(): array
    {
        $hour = (int)date('H');
        $dayOfWeek = (int)date('N'); // 1 = Monday, 7 = Sunday

        return [
            'hour_of_day' => $hour,
            'is_business_hours' => ($hour >= 9 && $hour <= 17),
            'is_weekend' => ($dayOfWeek >= 6),
            'is_unusual_time' => ($hour < 6 || $hour > 22),
        ];
    }

    /**
     * Analyze file type access patterns
     *
     * @param string $mimeType File MIME type
     * @return array File type access analysis
     */
    private function analyzeFileTypeAccess(string $mimeType): array
    {
        return [
            'mime_type' => $mimeType,
            'is_executable' => str_starts_with($mimeType, 'application/'),
            'is_media' => str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/'),
            'is_document' => in_array($mimeType, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ]),
        ];
    }

    /**
     * Detect potential privilege escalation attempts
     *
     * @param string $permission Permission being requested
     * @return bool Whether this might be privilege escalation
     */
    private function detectPrivilegeEscalation(string $permission): bool
    {
        $sensitivePermissions = [
            'files.delete',
            'files.upload.executable',
            'files.upload.large',
            'system.admin',
            'users.manage'
        ];

        return in_array($permission, $sensitivePermissions) && !$this->isAdmin();
    }

    /**
     * Detect suspicious access patterns
     *
     * @param array $fileInfo File information
     * @param string $operation Operation being performed
     * @return array Suspicious indicators found
     */
    private function detectSuspiciousPatterns(array $fileInfo, string $operation): array
    {
        $indicators = [];

        // Check for unusual time access
        $timeAnalysis = $this->analyzeAccessTime();
        if ($timeAnalysis['is_unusual_time']) {
            $indicators['unusual_time'] = 0.3;
        }

        // Check for cross-owner access
        if (($fileInfo['created_by'] ?? null) !== $this->currentUser->uuid) {
            $indicators['cross_owner_access'] = 0.4;
        }

        // Check for rapid file access (potential scraping)
        if ($this->detectRapidAccess()) {
            $indicators['rapid_access'] = 0.6;
        }

        // Check for large file access outside business hours
        $fileSize = $fileInfo['size'] ?? 0;
        if ($fileSize > 100 * 1024 * 1024 && !$timeAnalysis['is_business_hours']) { // 100MB
            $indicators['large_file_unusual_time'] = 0.5;
        }

        // Check for executable file access
        $mimeType = $fileInfo['mime_type'] ?? '';
        if (str_starts_with($mimeType, 'application/') && $operation === 'download') {
            $indicators['executable_download'] = 0.4;
        }

        // Check for bulk operations
        if ($operation === 'bulk_download' || $operation === 'bulk_upload') {
            $indicators['bulk_operation'] = 0.3;
        }

        return $indicators;
    }

    /**
     * Detect rapid access patterns
     *
     * @return bool Whether rapid access is detected
     */
    private function detectRapidAccess(): bool
    {
        // This would check recent access logs for rapid consecutive requests
        // For now, return false as placeholder
        return false;
    }

    /**
     * Override requirePermission to add audit logging for violations
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     */
    protected function requirePermission(
        string $permission,
        string $resource = 'system',
        array $context = []
    ): void {
        try {
            parent::requirePermission($permission, $resource, $context);
        } catch (UnauthorizedException $e) {
            // Audit the permission violation
            $this->auditPermissionViolation($permission, $resource, $context);
            throw $e;
        }
    }

    /**
     * Override rateLimit to add audit logging for violations
     *
     * @param string $action Action identifier
     * @param int|null $maxAttempts Maximum attempts
     * @param int|null $windowSeconds Time window in seconds
     * @param bool $useAdaptive Whether to use adaptive rate limiting
     */
    protected function rateLimit(
        string $action,
        ?int $maxAttempts = null,
        ?int $windowSeconds = null,
        bool $useAdaptive = true
    ): void {
        try {
            parent::rateLimit($action, $maxAttempts, $windowSeconds, $useAdaptive);
        } catch (\Exception $e) {
            // Check if this is a rate limit exception
            if (
                strpos($e->getMessage(), 'Rate limit') !== false ||
                strpos($e->getMessage(), 'exceeded') !== false
            ) {
                $retryAfter = null;
                if (method_exists($e, 'getRetryAfter')) {
                    try {
                        $retryAfter = call_user_func([$e, 'getRetryAfter']);
                    } catch (\Throwable) {
                        $retryAfter = null;
                    }
                }

                $this->auditRateLimitViolation(
                    $action,
                    [
                        'max_attempts' => $maxAttempts,
                        'window_seconds' => $windowSeconds,
                        'adaptive' => $useAdaptive
                    ],
                    [
                        'exception_message' => $e->getMessage(),
                        'retry_after' => $retryAfter
                    ]
                );
            }
            throw $e;
        }
    }
}
