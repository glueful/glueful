<?php

namespace Glueful\Uploader\Storage;

use Glueful\Uploader\UploadException;

class LocalStorage implements StorageInterface 
{
    public function __construct(
        private readonly string $baseDir,
        private readonly string $baseUrl
    ) {}

    public function store(string $sourcePath, string $destinationPath): string 
    {
        $fullPath = rtrim($this->baseDir, '/') . '/' . $destinationPath;
        
        if (file_exists($fullPath)) {
            throw new UploadException('File already exists');
        }

        if (!move_uploaded_file($sourcePath, $fullPath)) {
            throw new UploadException('Failed to move uploaded file');
        }

        chmod($fullPath, 0644);
        return $destinationPath;
    }

    public function getUrl(string $path): string 
    {
        return rtrim($this->baseUrl, '/') . '/' . $path;
    }

    public function exists(string $path): bool 
    {
        return file_exists(rtrim($this->baseDir, '/') . '/' . $path);
    }

    public function delete(string $path): bool 
    {
        $fullPath = rtrim($this->baseDir, '/') . '/' . $path;
        return file_exists($fullPath) && unlink($fullPath);
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string 
    {
        // For local storage, we'll just return the normal URL
        // since we don't need signed URLs for local files
        return $this->getUrl($path);
    }
}
