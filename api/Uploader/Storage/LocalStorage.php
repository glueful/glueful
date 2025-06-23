<?php

namespace Glueful\Uploader\Storage;

use Glueful\Uploader\UploadException;
use Glueful\Services\FileManager;

class LocalStorage implements StorageInterface
{
    private FileManager $fileManager;

    public function __construct(
        private readonly string $baseDir,
        private readonly string $baseUrl,
        ?FileManager $fileManager = null
    ) {
        $this->fileManager = $fileManager ?? container()->get(FileManager::class);
    }

    public function store(string $sourcePath, string $destinationPath): string
    {
        $fullPath = rtrim($this->baseDir, '/') . '/' . $destinationPath;

        if ($this->fileManager->exists($fullPath)) {
            throw new UploadException('File already exists');
        }

        // Ensure destination directory exists
        $directory = dirname($fullPath);
        if (!$this->fileManager->exists($directory)) {
            $this->fileManager->createDirectory($directory);
        }

        // Use FileManager for secure file copying
        if (is_uploaded_file($sourcePath)) {
            // For uploaded files, copy content then remove source
            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new UploadException('Failed to read uploaded file');
            }

            if (!$this->fileManager->writeFile($fullPath, $content)) {
                throw new UploadException('Failed to store uploaded file');
            }
        } else {
            // For non-uploaded files (e.g., temporary files), use copy
            if (!$this->fileManager->copy($sourcePath, $fullPath)) {
                throw new UploadException('Failed to copy file to destination');
            }
        }

        return $destinationPath;
    }

    public function getUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . $path;
    }

    public function exists(string $path): bool
    {
        return $this->fileManager->exists(rtrim($this->baseDir, '/') . '/' . $path);
    }

    public function delete(string $path): bool
    {
        $fullPath = rtrim($this->baseDir, '/') . '/' . $path;
        return $this->fileManager->exists($fullPath) && $this->fileManager->remove($fullPath);
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        // For local storage, we'll just return the normal URL
        // since we don't need signed URLs for local files
        return $this->getUrl($path);
    }
}
