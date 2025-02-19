<?php

declare(strict_types=1);

namespace Glueful\ImageProcessing;

use RuntimeException;
use InvalidArgumentException;
use GdImage;

interface ImageProcessorInterface 
{
    public function processImage(string $source): bool;
    public function outputImage(): void;
}

interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $data): bool;
    public function clean(): void;
}

final class TimThumb implements ImageProcessorInterface 
{
    private const VERSION = '3.0.0';
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png', 
        'image/gif'
    ];

    private string $source = '';
    private bool $is404 = false;
    private string $docRoot;
    private ?string $lastURLError = null;
    private string $localImage = '';
    private int $localImageMTime = 0;
    private array $url = [];
    private string $myHost = '';
    private bool $isURL = false;
    private string $cacheFile = '';
    private array $errors = [];
    private array $toDeletes = [];
    private string $cacheDirectory;
    private float $startTime;
    private float $lastBenchTime = 0;
    private bool $cropTop = false;
    private readonly string $salt;
    private readonly CacheInterface $cache;

    public function __construct(
        private readonly array $config,
        ?CacheInterface $cache = null
    ) {
        $this->startTime = microtime(true);
        $this->salt = $this->generateSalt();
        $this->cache = $cache ?? new FileCache($config['cacheDir']);
        $this->setupEnvironment();
    }

    private function generateSalt(): string 
    {
        return sprintf(
            '%s-%s',
            @filemtime(__FILE__),
            @fileinode(__FILE__)
        );
    }

    private function setupEnvironment(): void
    {
        date_default_timezone_set('UTC');
        $this->setMemoryLimit();
        $this->setupCacheDirectory();
        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        $required = ['maxWidth', 'maxHeight', 'quality'];
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new InvalidArgumentException("Missing required config: {$key}");
            }
        }
    }

    private function setMemoryLimit(): void
    {
        $currentLimit = ini_get('memory_limit');
        $requiredLimit = $this->config['memoryLimit'] ?? '128M';
        
        if ($this->returnBytes($currentLimit) < $this->returnBytes($requiredLimit)) {
            ini_set('memory_limit', $requiredLimit);
        }
    }

    private function returnBytes(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[-1]);
        $size = (int)$size;
        
        return match ($last) {
            'g' => $size * 1024 * 1024 * 1024,
            'm' => $size * 1024 * 1024,
            'k' => $size * 1024,
            default => $size
        };
    }

    private function setupCacheDirectory(): void
    {
        $cacheDir = $this->config['cacheDir'] ?? sys_get_temp_dir() . '/timthumb-cache';
        
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            throw new RuntimeException("Failed to create cache directory: $cacheDir");
        }

        if (!is_writable($cacheDir)) {
            throw new RuntimeException("Cache directory is not writable: $cacheDir");
        }

        $this->cacheDirectory = $cacheDir;
        
        // Create .htaccess for security
        $htaccess = $cacheDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }

    public function processImage(string $source): bool
    {
        $this->source = $source;
        
        if (!$this->validateSource()) {
            return false;
        }

        if ($this->tryCache()) {
            return true;
        }

        return $this->processAndCache();
    }

    private function validateSource(): bool
    {
        if (strlen($this->source) < 3) {
            throw new InvalidArgumentException("Invalid source image specified");
        }

        if ($this->isExternalSource() && !$this->config['allowExternal']) {
            throw new RuntimeException("External images are not allowed");
        }

        return true;
    }

    private function isExternalSource(): bool
    {
        return (bool)preg_match('#^https?://#i', $this->source);
    }

    private function processAndCache(): bool
    {
        $image = $this->loadImage();
        if (!$image) {
            return false;
        }

        $processed = $this->resizeImage($image);
        if (!$processed) {
            return false;
        }

        ob_start();
        imagejpeg($processed, null, $this->config['quality'] ?? 75);
        $imageData = ob_get_clean();
        return $this->cache->set($this->getCacheKey(), $imageData);
    }

    private function loadImage(): ?GdImage 
    {
        if ($this->isExternalSource()) {
            return $this->loadExternalImage();
        }
        return $this->loadLocalImage();
    }

    private function loadLocalImage(): ?GdImage
    {
        $path = $this->getLocalImagePath($this->source);
        if (!$path || !is_readable($path)) {
            throw new RuntimeException("Cannot read local image");
        }

        return $this->createImage($path);
    }

    private function createImage(string $path): ?GdImage
    {
        $mime = $this->getMimeType($path);
        if (!in_array($mime, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException("Invalid image type");
        }

        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => $this->createPngImage($path),
            'image/gif' => imagecreatefromgif($path),
            default => throw new RuntimeException("Unsupported image type")
        };
    }

    private function createPngImage(string $path): GdImage
    {
        $image = imagecreatefrompng($path);
        if (!$image) {
            throw new RuntimeException("Failed to create PNG image");
        }
        
        imagealphablending($image, true);
        imagesavealpha($image, true);
        
        return $image;
    }

    private function resizeImage(GdImage $source): GdImage
    {
        // Get new dimensions
        [$width, $height] = $this->calculateDimensions(
            imagesx($source),
            imagesy($source)
        );

        // Create new image
        $resized = imagecreatetruecolor($width, $height);
        if (!$resized) {
            throw new RuntimeException("Failed to create resized image");
        }

        // Handle transparency
        $this->preserveTransparency($resized);

        // Resize
        imagecopyresampled(
            $resized, 
            $source,
            0, 0, 0, 0,
            $width, $height,
            imagesx($source),
            imagesy($source)
        );

        return $resized;
    }

    private function calculateDimensions(int $origWidth, int $origHeight): array
    {
        $width = $this->config['width'] ?? 0;
        $height = $this->config['height'] ?? 0;

        // Calculate new dimensions
        if (!$width && !$height) {
            $width = $origWidth;
            $height = $origHeight;
        } elseif (!$height) {
            $height = floor($origHeight * ($width / $origWidth));
        } elseif (!$width) {
            $width = floor($origWidth * ($height / $origHeight));
        }

        // Enforce maximums
        $width = min($width, $this->config['maxWidth']);
        $height = min($height, $this->config['maxHeight']);

        return [$width, $height];
    }

    private function preserveTransparency(GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
    }

    public function outputImage(): void
    {
        $data = $this->cache->get($this->getCacheKey());
        if (!$data) {
            throw new RuntimeException("No cached image found");
        }

        $this->sendHeaders();
        echo $data;
    }

    private function sendHeaders(): void
    {
        $expires = gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT';
        
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Expires: ' . $expires);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    }

    private function getCacheKey(): string
    {
        return md5($this->source . serialize($this->config));
    }

    public function __destruct()
    {
        foreach ($this->toDeletes as $file) {
            @unlink($file);
        }
    }

    private function tryCache(): bool
    {
        $key = $this->getCacheKey();
        $cached = $this->cache->get($key);
        
        if ($cached !== null) {
            return true;
        }
        
        return false;
    }

    private function loadExternalImage(): ?GdImage
    {
        $tempFile = tempnam($this->cacheDirectory, 'thumb_temp_');
        
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 TimThumb/' . self::VERSION
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);

            $content = file_get_contents($this->source, false, $context);
            if ($content === false) {
                throw new RuntimeException("Failed to download external image");
            }

            file_put_contents($tempFile, $content);
            $this->toDeletes[] = $tempFile; // Clean up temp file later

            return $this->createImage($tempFile);
            
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            throw new RuntimeException("Failed to process external image: " . $e->getMessage());
        }
    }

    private function getLocalImagePath(string $src): string
    {
        $src = ltrim($src, '/');
        
        // Try direct path
        if (is_file($src) && is_readable($src)) {
            return realpath($src);
        }
        
        // Try relative to document root
        $docRoot = $this->getDocumentRoot();
        $fullPath = $docRoot . '/' . $src;
        
        if (is_file($fullPath) && is_readable($fullPath)) {
            return realpath($fullPath);
        }
        
        throw new RuntimeException("Image file not found: $src");
    }

    private function getDocumentRoot(): string
    {
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            return rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        }
        
        // Fallback to current directory
        return rtrim(dirname(__DIR__, 3), '/');
    }

    private function getMimeType(string $path): string
    {
        $info = @getimagesize($path);
        
        if ($info === false) {
            throw new RuntimeException("Could not determine image type");
        }
        
        if (!isset($info['mime'])) {
            throw new RuntimeException("Missing MIME type information");
        }
        
        return $info['mime'];
    }
}

final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $directory
    ) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Cannot create cache directory");
        }
    }

    public function get(string $key): ?string
    {
        $file = $this->getFilePath($key);
        return is_readable($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, string $data): bool
    {
        return file_put_contents($this->getFilePath($key), $data) !== false;
    }

    public function clean(): void
    {
        $files = glob($this->directory . '/*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file) && $now - filemtime($file) >= 86400) {
                @unlink($file);
            }
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->directory . '/' . $key . '.cache';
    }
}