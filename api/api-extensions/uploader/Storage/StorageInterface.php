<?php

namespace Glueful\Api\Extensions\Uploader\Storage;

interface StorageInterface {
    public function store(string $sourcePath, string $destinationPath): string;
    public function getUrl(string $path): string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    public function getSignedUrl(string $path, int $expiry = 3600): string;
}