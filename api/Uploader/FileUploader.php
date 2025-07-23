<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Repository\BlobRepository;
use Glueful\Helpers\Utils;
use Glueful\Uploader\Storage\{StorageInterface, S3Storage, LocalStorage};
use Glueful\Uploader\UploadException;
use Glueful\Uploader\ValidationException;
use Glueful\Services\FileManager;

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

    private StorageInterface $storage;
    private BlobRepository $blobRepository;
    private FileManager $fileManager;

    public function __construct(
        private readonly string $uploadsDirectory = '',
        private readonly string $cdnBaseUrl = '',
        private readonly ?string $storageDriver = null,
        ?FileManager $fileManager = null
    ) {
        $this->fileManager = $fileManager ?? container()->get(FileManager::class);
        $this->blobRepository = new BlobRepository();
        $driver = $this->storageDriver ?: config('services.storage.driver');
        $this->storage = match ($driver) {
            's3' => new S3Storage(),
            default => new LocalStorage(
                $this->uploadsDirectory ?: config('app.paths.uploads'),
                $this->cdnBaseUrl ?: config('app.paths.cdn')
            )
        };
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
        return $this->storage->store($tempPath, $filename);
    }

    private function generateSecureFilename(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $sanitized = preg_replace('/[^a-zA-Z0-9-_.]/', '', $extension);
        return sprintf(
            '%s_%s.%s',
            time(),
            bin2hex(random_bytes(8)),
            $sanitized
        );
    }

    private function saveFileRecord(string $token, array $getParams, array $file, string $filename): array
    {
        $user = Utils::getUser();
        $uuid = $user['uuid'] ?? null;

        $blobData = [
            'name' => $file['name'],
            'mime_type' => $file['type'],
            'url' => $filename,
            'created_by' => $uuid,
            'size' => $file['size'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $blobUuid = $this->blobRepository->create($blobData);

        return [
            'uuid' => $blobUuid,
            'url' => $this->storage->getUrl($filename)
        ];
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

            if (!$this->fileManager->writeFile($tempFile, $data)) {
                throw new UploadException('Failed to save temporary file');
            }

            return $tempFile;
        } catch (\Exception $e) {
            if ($this->fileManager->exists($tempFile)) {
                $this->fileManager->remove($tempFile);
            }
            throw new UploadException('Base64 processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Get upload directory usage statistics
     *
     * @param string $directory Directory to analyze
     * @return array Usage statistics
     */
    public function getDirectoryStats(string $directory): array
    {
        if (!$this->fileManager->exists($directory)) {
            return [
                'exists' => false,
                'total_files' => 0,
                'total_size' => 0,
                'total_size_human' => '0 B'
            ];
        }

        $fileFinder = container()->get(\Glueful\Services\FileFinder::class);
        $finder = $fileFinder->createFinder();
        $files = $finder->files()->in($directory);

        $totalFiles = 0;
        $totalSize = 0;
        $fileTypes = [];

        foreach ($files as $file) {
            $totalFiles++;
            $size = $file->getSize();
            $totalSize += $size;

            $extension = strtolower($file->getExtension());
            $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
        }

        return [
            'exists' => true,
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'file_types' => $fileTypes,
            'directory' => $directory
        ];
    }

    /**
     * Clean up old files in upload directory
     *
     * @param string $directory Directory to clean
     * @param int $maxAge Maximum age in seconds
     * @return array Cleanup results
     */
    public function cleanupOldFiles(string $directory, int $maxAge = 86400): array
    {
        $fileFinder = container()->get(\Glueful\Services\FileFinder::class);
        $finder = $fileFinder->createFinder();
        $cutoffTime = time() - $maxAge;

        $files = $finder->files()
            ->in($directory)
            ->date('< ' . date('Y-m-d H:i:s', $cutoffTime));

        $deleted = 0;
        $totalSize = 0;

        foreach ($files as $file) {
            $size = $file->getSize();
            if ($this->fileManager->remove($file->getPathname())) {
                $deleted++;
                $totalSize += $size;
            }
        }

        return [
            'deleted_files' => $deleted,
            'freed_space' => $totalSize,
            'freed_space_human' => $this->formatBytes($totalSize)
        ];
    }

    /**
     * Validate file extension and MIME type
     *
     * @param string $filePath File path to validate
     * @return bool True if valid
     */
    public function validateFileType(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];

        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        // Check MIME type matches extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $validMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain']
        ];

        return in_array($mimeType, $validMimes[$extension]);
    }

    /**
     * Calculate file checksum
     *
     * @param string $filePath File path
     * @return string SHA256 checksum
     */
    public function calculateChecksum(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }


    /**
     * Format bytes to human readable format
     *
     * @param int $size Size in bytes
     * @return string Formatted size
     */
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;

        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
