<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{Request, FileHandler};
use Glueful\Logging\{AuditLogger, AuditEvent};

/**
 * Files Controller
 *
 * Handles file operations such as uploads, downloads, and retrieval.
 * Provides endpoints for working with file resources in the application.
 *
 * @package Glueful\Controllers
 */
class FilesController
{
    private FileHandler $fileHandler;
    private AuthController $authController;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->fileHandler = new FileHandler();
        $this->authController = new AuthController();
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
        $this->authController->validateToken();
        $request = new Request();
        $requestData = $request->getQueryParams();

        // Get parameters from request
        $uuid = $requestData['uuid'] ?? null;
        $type = $requestData['type'] ?? 'info';

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

        // Get the blob data
        $result = $this->fileHandler->getBlob($uuid, $type, $params);

        // Log the file access to audit logger
        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_DATA,
            'file_access',
            AuditEvent::SEVERITY_INFO,
            [
                'file_uuid' => $uuid,
                'access_type' => $type,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'parameters' => $params
            ]
        );

        // Note: For download and inline types, the getBlob method will automatically
        // set headers and stream the file, so this return is only reached for
        // info and image types, or if there was an error
        return Response::ok($result, 'File retrieved successfully')->send();
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
        $request = new Request();
        $this->authController->validateToken();
        $contentType = $request->getContentType();

        // Handle multipart form data (regular file upload)
        if (strpos($contentType, 'multipart/form-data') !== false) {
            if (empty($request->getFiles())) {
                return Response::error('No file uploaded', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->fileHandler->handleFileUpload($request->getQueryParams(), $request->getFiles());

            // Log successful file upload to audit logger
            $auditLogger = AuditLogger::getInstance();
            $auditLogger->audit(
                AuditEvent::CATEGORY_DATA,
                'file_upload',
                AuditEvent::SEVERITY_INFO,
                [
                    'file_uuid' => $result['uuid'] ?? null,
                    'filename' => $result['filename'] ?? 'unknown',
                    'file_size' => $result['size'] ?? 0,
                    'file_type' => $result['mime_type'] ?? 'unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]
            );

            return Response::ok($result, 'File uploaded successfully')->send();
        } else {
            // Handle JSON/base64 upload
            $postData = Request::getPostData();
            if (!isset($postData['base64'])) {
                return Response::error('Base64 content required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->fileHandler->handleBase64Upload($request->getQueryParams(), $postData);

            // Log successful base64 file upload to audit logger
            $auditLogger = AuditLogger::getInstance();
            $auditLogger->audit(
                AuditEvent::CATEGORY_DATA,
                'file_upload_base64',
                AuditEvent::SEVERITY_INFO,
                [
                    'file_uuid' => $result['uuid'] ?? null,
                    'filename' => $result['filename'] ?? 'unknown',
                    'file_size' => $result['size'] ?? 0,
                    'file_type' => $result['mime_type'] ?? 'unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]
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
        $this->authController->validateToken();
        $uuid = $params['uuid'] ?? null;

        if (!$uuid) {
            return Response::error('File UUID is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Using the existing getBlob method to check if file exists
        // Then manually delete the file
        if ($this->fileHandler->fileExists($uuid)) {
            // Get file metadata before deletion for audit purposes
            $fileInfo = $this->fileHandler->getBlob($uuid, 'info');
            $result = $this->fileHandler->deleteFile($uuid);

            // Log successful file deletion to audit logger
            if ($result) {
                $auditLogger = AuditLogger::getInstance();
                $auditLogger->audit(
                    AuditEvent::CATEGORY_DATA,
                    'file_delete',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'file_uuid' => $uuid,
                        'filename' => $fileInfo['data']['name'] ?? 'unknown',
                        'file_type' => $fileInfo['data']['mime_type'] ?? 'unknown',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]
                );
            }
        } else {
            $result = false;
            // Log attempted deletion of non-existent file
            $auditLogger = AuditLogger::getInstance();
            $auditLogger->audit(
                AuditEvent::CATEGORY_DATA,
                'file_delete_not_found',
                AuditEvent::SEVERITY_WARNING,
                [
                    'file_uuid' => $uuid,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]
            );
        }

        if (!$result) {
            return Response::error('File not found or could not be deleted', Response::HTTP_NOT_FOUND)->send();
        }

        return Response::ok(['uuid' => $uuid], 'File deleted successfully')->send();
    }
}
