<?php

namespace Glueful\Uploader\Storage;

interface StorageInterface
{
    public function store(string $sourcePath, string $destinationPath): string;
    public function getUrl(string $path): string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
}
