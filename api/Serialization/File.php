<?php

declare(strict_types=1);

namespace Glueful\Serialization;

/**
 * File Value Object
 *
 * Represents a file with metadata and security features.
 */
class File
{
    private string $name;
    private string $originalName;
    private int $size;
    private string $mimeType;
    private string $extension;
    private \DateTime $createdAt;
    private array $metadata = [];
    private bool $isPublic = false;
    private ?string $path = null;

    /**
     * Constructor
     */
    public function __construct(
        string $name,
        string $originalName,
        int $size,
        string $mimeType
    ) {
        $this->name = $name;
        $this->originalName = $originalName;
        $this->size = $size;
        $this->mimeType = $mimeType;
        $this->extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $this->createdAt = new \DateTime();
    }

    /**
     * Create from uploaded file
     */
    public static function fromUpload(array $uploadedFile): self
    {
        return new self(
            uniqid() . '.' . pathinfo($uploadedFile['name'], PATHINFO_EXTENSION),
            $uploadedFile['name'],
            $uploadedFile['size'],
            $uploadedFile['type']
        );
    }

    /**
     * Create from path
     */
    public static function fromPath(string $path): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File does not exist: {$path}");
        }

        $info = pathinfo($path);
        $file = new self(
            $info['basename'],
            $info['basename'],
            filesize($path),
            mime_content_type($path) ?: 'application/octet-stream'
        );

        $file->path = $path;
        return $file;
    }

    /**
     * Get file name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get original name
     */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * Get file size in bytes
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Get human-readable size
     */
    public function getHumanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Get file extension
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Get creation date
     */
    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    /**
     * Set metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Get metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Add metadata item
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Check if has metadata
     */
    public function hasMetadata(): bool
    {
        return !empty($this->metadata);
    }

    /**
     * Set public accessibility
     */
    public function setPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    /**
     * Check if file is public
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Get file URL (if public)
     */
    public function getUrl(): ?string
    {
        if (!$this->isPublic) {
            return null;
        }

        // In real implementation, this would generate actual URLs
        return "/files/{$this->name}";
    }

    /**
     * Get file hash for verification
     */
    public function getHash(): string
    {
        if ($this->path && file_exists($this->path)) {
            return hash_file('sha256', $this->path);
        }

        // Generate hash from file properties if no path available
        return hash('sha256', $this->name . $this->size . $this->mimeType);
    }

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * Check if file is a document
     */
    public function isDocument(): bool
    {
        $documentTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/html',
        ];

        return in_array($this->mimeType, $documentTypes);
    }

    /**
     * Check if file is an archive
     */
    public function isArchive(): bool
    {
        $archiveTypes = [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-tar',
            'application/gzip',
        ];

        return in_array($this->mimeType, $archiveTypes);
    }

    /**
     * Get file type category
     */
    public function getTypeCategory(): string
    {
        if ($this->isImage()) {
            return 'image';
        }

        if ($this->isDocument()) {
            return 'document';
        }

        if ($this->isArchive()) {
            return 'archive';
        }

        if (str_starts_with($this->mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($this->mimeType, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    /**
     * Validate file security
     */
    public function isSecure(): bool
    {
        // Check for dangerous file extensions
        $dangerousExtensions = [
            'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
            'php', 'asp', 'aspx', 'jsp', 'pl', 'py', 'rb', 'sh'
        ];

        return !in_array(strtolower($this->extension), $dangerousExtensions);
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->originalName;
    }
}
