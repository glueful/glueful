<?php

declare(strict_types=1);

namespace Mapi\Api\Extensions\Uploader;

require_once __DIR__ . '/../../bootstrap.php';

use Mapi\Api\Library\APIEngine;
use RuntimeException;
use InvalidArgumentException;

class UploadException extends RuntimeException {}
class ValidationException extends InvalidArgumentException {}

final class FileUploader 
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    private const MAX_FILE_SIZE = 10485760; // 10MB

    public function __construct(
        private readonly string $uploadsDirectory = '',
        private readonly string $cdnBaseUrl = ''
    ) {
        $this->uploadsDirectory = $this->uploadsDirectory ?: config('paths.uploads');
        $this->cdnBaseUrl = $this->cdnBaseUrl ?: config('paths.cdn');
        $this->validateDirectory();
    }

    private function validateDirectory(): void 
    {
        if (!is_dir($this->uploadsDirectory) || !is_writable($this->uploadsDirectory)) {
            throw new UploadException("Upload directory is not writable");
        }
    }

    public function handleUpload(string $token, array $getParams, array $fileParams): array 
    {
        try {
            $this->validateRequest($token, $getParams, $fileParams);
            
            $file = $this->processUploadedFile($fileParams, $getParams['key'] ?? null);
            $filename = $this->generateSecureFilename($file['name']);
            
            $this->validateFileContent($file);
            
            $uploadPath = $this->moveFile($file['tmp_name'], $filename);
            
            return $this->saveFileRecord($token, $getParams, $file, $filename);
            
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        } catch (UploadException $e) {
            error_log("Upload error: " . $e->getMessage());
            return ['error' => 'Upload failed', 'code' => 500];
        }
    }

    private function validateRequest(string $token, array $getParams, array $fileParams): void 
    {
        if (empty($getParams['user_id']) || empty($token) || empty($fileParams)) {
            throw new ValidationException('Missing required parameters');
        }
    }

    private function processUploadedFile(array $fileParams, ?string $key): array 
    {
        $file = isset($key) ? ($fileParams[$key] ?? null) : $fileParams;
        
        if (!is_array($file) || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new UploadException('Invalid file upload');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException('File size exceeds limit');
        }

        return $file;
    }

    private function validateFileContent(array $file): void 
    {
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new ValidationException('Invalid file type');
        }

        // Additional security checks
        if ($this->isFileHazardous($file['tmp_name'])) {
            throw new ValidationException('File content not allowed');
        }
    }

    private function isFileHazardous(string $filepath): bool 
    {
        // Check for PHP code or other potentially harmful content
        $content = file_get_contents($filepath);
        return (
            str_contains($content, '<?php') ||
            str_contains($content, '<?=') ||
            str_contains($content, '<script')
        );
    }

    private function moveFile(string $tempPath, string $filename): string 
    {
        $destination = rtrim($this->uploadsDirectory, '/') . '/' . $filename;
        
        if (file_exists($destination)) {
            throw new UploadException('File already exists');
        }

        if (!move_uploaded_file($tempPath, $destination)) {
            throw new UploadException('Failed to move uploaded file');
        }

        chmod($destination, 0644);
        return $destination;
    }

    private function generateSecureFilename(string $originalName): string 
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $sanitized = preg_replace('/[^a-zA-Z0-9-_.]/', '', $extension);
        return sprintf('%s_%s.%s', 
            time(),
            bin2hex(random_bytes(8)),
            $sanitized
        );
    }

    private function saveFileRecord(string $token, array $getParams, array $file, string $filename): array 
    {
        $params = [
            'name' => $file['name'],
            'mime_type' => mime_content_type($this->uploadsDirectory . '/' . $filename),
            'url' => $filename,
            'user_id' => $getParams['user_id'],
            'size' => $file['size'],
            'status' => ACTIVE_STATUS,
            'token' => $token,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $response = APIEngine::saveData('blobs', 'save', $params);
        $response['url'] = $this->cdnBaseUrl . $filename;
        
        return $response;
    }

    public function handleBase64Upload(string $base64String): string 
    {
        if (empty($base64String)) {
            throw new ValidationException('Empty base64 string');
        }

        $tempFile = sprintf('/tmp/%s', bin2hex(random_bytes(16)));
        
        try {
            $data = base64_decode($base64String, true);
            if ($data === false) {
                throw new ValidationException('Invalid base64 string');
            }

            if (file_put_contents($tempFile, $data) === false) {
                throw new UploadException('Failed to save temporary file');
            }

            return $tempFile;
            
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            throw new UploadException('Base64 processing failed: ' . $e->getMessage());
        }
    }
}

// Usage example:
// $uploader = new FileUploader();
// $result = $uploader->handleUpload($_POST['token'], $_GET, $_FILES);
?>