<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Glueful\Extensions\Services\Interfaces\ExtensionCatalogInterface;
use Glueful\Extensions\Exceptions\ExtensionException;
use Glueful\Http\Client;
use Glueful\Services\FileManager;
use Glueful\DI\ContainerBootstrap;
use Psr\Log\LoggerInterface;

class ExtensionCatalog implements ExtensionCatalogInterface
{
    private ?array $catalogCache = null;
    private int $cacheTimeout = 3600; // 1 hour
    private string $cacheFile;
    private bool $debug = false;

    public function __construct(
        private string $registryUrl = 'https://raw.githubusercontent.com/glueful/catalog/main/catalog.json',
        private int $timeout = 30,
        private ?Client $httpClient = null,
        private ?FileManager $fileManager = null,
        private ?LoggerInterface $logger = null
    ) {
        $this->cacheFile = $this->getCacheFilePath();
        $this->initializeServices();
    }

    private function initializeServices(): void
    {
        if ($this->httpClient === null || $this->fileManager === null || $this->logger === null) {
            try {
                $container = ContainerBootstrap::getContainer();
                $this->httpClient ??= $container->get(Client::class);
                $this->fileManager ??= $container->get(FileManager::class);
                $this->logger ??= $container->get(LoggerInterface::class);
            } catch (\Exception $e) {
                // Fallback: Create services directly if container not available
                // Note: This should rarely happen in normal operation
                if ($this->httpClient === null) {
                    // For fallback, we'll need to create the HTTP client with dependencies
                    // In practice, this should be avoided and the container should be available
                    throw new ExtensionException(
                        'HTTP Client not available and container initialization failed: ' . $e->getMessage()
                    );
                }
                $this->fileManager ??= new FileManager();
            }
        }
    }

    public function setDebugMode(bool $enable = true): void
    {
        $this->debug = $enable;
    }

    public function setRegistryUrl(string $url): void
    {
        $this->registryUrl = rtrim($url, '/');
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getAvailableExtensions(): array
    {
        return $this->fetchCatalog();
    }

    public function searchExtensions(string $query): array
    {
        $catalog = $this->fetchCatalog();

        if (empty($catalog)) {
            return [];
        }

        $results = [];
        $queryLower = strtolower($query);

        foreach ($catalog as $extension) {
            $searchText = strtolower(
                ($extension['name'] ?? '') . ' ' .
                ($extension['displayName'] ?? '') . ' ' .
                ($extension['description'] ?? '') . ' ' .
                implode(' ', $extension['keywords'] ?? []) . ' ' .
                implode(' ', $extension['categories'] ?? [])
            );

            if (str_contains($searchText, $queryLower)) {
                $results[] = $extension;
            }
        }

        return $results;
    }

    public function downloadExtension(string $name, ?string $version = null): string
    {
        $this->debugLog("Downloading extension: {$name}" . ($version ? " (v{$version})" : ""));

        $extensionMetadata = $this->getRemoteMetadata($name);
        if (!$extensionMetadata) {
            throw new ExtensionException("Extension not found in catalog: {$name}");
        }

        $downloadUrl = $this->getDownloadUrl($name, $version ?? $extensionMetadata['version']);
        $tempFile = $this->downloadFile($downloadUrl);

        if (!$this->verifyPackage($tempFile)) {
            unlink($tempFile);
            throw new ExtensionException("Package verification failed for extension: {$name}");
        }

        return $tempFile;
    }

    public function getRemoteMetadata(string $name): array
    {
        $catalog = $this->fetchCatalog();

        foreach ($catalog as $extension) {
            if ($extension['name'] === $name || $extension['id'] === $name) {
                return $extension;
            }
        }

        return [];
    }

    public function checkForUpdates(): array
    {
        // This would check installed extensions against the catalog
        // For now, return empty array as we'd need the extension config
        return [];
    }

    public function getCategories(): array
    {
        $catalog = $this->fetchCatalog();
        $categories = [];

        foreach ($catalog as $extension) {
            $extensionCategories = $extension['categories'] ?? [];
            $categories = array_merge($categories, $extensionCategories);
        }

        return array_unique($categories);
    }

    public function getFeaturedExtensions(): array
    {
        $catalog = $this->fetchCatalog();

        return array_filter($catalog, function ($extension) {
            return $extension['featured'] ?? false;
        });
    }

    public function getExtensionsByCategory(string $category): array
    {
        $catalog = $this->fetchCatalog();

        return array_filter($catalog, function ($extension) use ($category) {
            $categories = $extension['categories'] ?? [];
            return in_array($category, $categories);
        });
    }

    public function verifyPackage(string $packagePath): bool
    {
        if (!file_exists($packagePath)) {
            return false;
        }

        // Basic file verification
        if (filesize($packagePath) === 0) {
            return false;
        }

        // Check if it's a valid ZIP file
        $zip = new \ZipArchive();
        $result = $zip->open($packagePath, \ZipArchive::CHECKCONS);

        if ($result !== true) {
            $this->debugLog("Package verification failed: Invalid ZIP file");
            return false;
        }

        // Check for required files (manifest.json)
        $hasManifest = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, 'manifest.json')) {
                $hasManifest = true;
                break;
            }
        }

        $zip->close();

        if (!$hasManifest) {
            $this->debugLog("Package verification failed: No manifest.json found");
            return false;
        }

        return true;
    }

    public function extractPackage(string $packagePath, string $destination): bool
    {
        if (!$this->verifyPackage($packagePath)) {
            return false;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($packagePath);

        if ($result !== true) {
            $this->debugLog("Failed to open package for extraction: {$packagePath}");
            return false;
        }

        // Create destination directory
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                $zip->close();
                return false;
            }
        }

        // Extract files
        $extracted = $zip->extractTo($destination);
        $zip->close();

        if (!$extracted) {
            $this->debugLog("Failed to extract package to: {$destination}");
            return false;
        }

        $this->debugLog("Successfully extracted package to: {$destination}");
        return true;
    }

    public function clearCache(): bool
    {
        $this->catalogCache = null;

        if (file_exists($this->cacheFile)) {
            $result = unlink($this->cacheFile);
            $this->debugLog("Catalog cache cleared: " . ($result ? 'success' : 'failed'));
            return $result;
        }

        return true;
    }

    public function setCacheTimeout(int $timeout): void
    {
        $this->cacheTimeout = $timeout;
    }

    /**
     * Get registry URL
     *
     * @return string Registry URL
     */
    public function getRegistryUrl(): string
    {
        return $this->registryUrl;
    }

    private function fetchCatalog(bool $useCache = true): array
    {
        // Check cache first
        if ($useCache && $this->catalogCache !== null) {
            return $this->catalogCache;
        }

        if ($useCache && $this->isCacheValid()) {
            $this->catalogCache = $this->loadCacheFile();
            if ($this->catalogCache !== null) {
                return $this->catalogCache;
            }
        }

        // Fetch from remote
        try {
            $this->debugLog("Fetching catalog from: {$this->registryUrl}");

            $url = $this->registryUrl;
            $response = $this->httpClient->get($url, [
                'timeout' => $this->timeout,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Glueful-Extensions-Manager/1.0'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new ExtensionException("HTTP {$response->getStatusCode()}: Failed to fetch catalog");
            }

            $catalogData = json_decode($response->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ExtensionException("Invalid JSON response from catalog");
            }

            // Extract extensions array from GitHub catalog format
            $catalog = $catalogData['extensions'] ?? [];

            // Cache the result
            $this->catalogCache = $catalog;
            $this->saveCacheFile($catalog);

            $this->debugLog("Successfully fetched catalog with " . count($catalog) . " extensions");
            return $catalog;
        } catch (\Exception $e) {
            $this->debugLog("Failed to fetch catalog: " . $e->getMessage());

            // Return cached data if available, even if expired
            if ($this->catalogCache !== null) {
                return $this->catalogCache;
            }

            // Load from cache file as last resort
            $cached = $this->loadCacheFile();
            return $cached ?? [];
        }
    }

    private function getDownloadUrl(string $name, string $version): string
    {
        return $this->registryUrl . "/api/extensions/{$name}/download/{$version}";
    }

    private function downloadFile(string $url): string
    {
        $this->debugLog("Downloading file from: {$url}");

        $tempFile = tempnam(sys_get_temp_dir(), 'glueful_extension_');

        $response = $this->httpClient->get($url, [
            'timeout' => $this->timeout * 3, // Longer timeout for downloads
            'headers' => [
                'User-Agent' => 'Glueful-Extensions-Manager/1.0'
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ExtensionException("HTTP {$response->getStatusCode()}: Failed to download extension");
        }

        if (file_put_contents($tempFile, $response->getContent()) === false) {
            throw new ExtensionException("Failed to save downloaded file");
        }

        $this->debugLog("Downloaded file saved to: {$tempFile}");
        return $tempFile;
    }

    private function isCacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($this->cacheFile);
        return (time() - $cacheTime) < $this->cacheTimeout;
    }

    private function loadCacheFile(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    private function saveCacheFile(array $data): void
    {
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json, LOCK_EX);
    }

    private function getCacheFilePath(): string
    {
        $cacheDir = sys_get_temp_dir() . '/glueful_extensions';
        return $cacheDir . '/catalog_cache.json';
    }

    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger) {
            $this->logger->debug("[ExtensionCatalog] {$message}");
        } else {
            error_log("[ExtensionCatalog] {$message}");
        }
    }
}
