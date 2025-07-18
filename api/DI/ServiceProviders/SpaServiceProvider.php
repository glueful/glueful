<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class SpaServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register StaticFileDetector
        $container->register(\Glueful\Helpers\StaticFileDetector::class)
            ->setArguments([
                '$config' => $this->getStaticFileConfig()
            ])
            ->setPublic(true);

        // Register SpaManager
        $container->register(\Glueful\SpaManager::class)
            ->setArguments([
                new Reference(\Psr\Log\LoggerInterface::class),
                new Reference(\Glueful\Helpers\StaticFileDetector::class)
            ])
            ->setPublic(true);
    }

    public function boot(\Glueful\DI\Container $container): void
    {
        // Boot logic if needed
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'spa';
    }

    private function getStaticFileConfig(): array
    {
        return [
            'extensions' => [
                // Web assets
                'css', 'js', 'map', 'json', 'txt', 'xml',
                // Images
                'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tiff',
                // Fonts
                'woff', 'woff2', 'ttf', 'eot', 'otf',
                // Media
                'mp4', 'webm', 'ogg', 'mp3', 'wav', 'flac',
                // Documents
                'pdf', 'zip', 'tar', 'gz',
                // Other
                'manifest', 'webmanifest', 'robots'
            ],
            'mime_types' => [
                'text/css',
                'application/javascript',
                'text/javascript',
                'image/',
                'font/',
                'audio/',
                'video/',
                'application/font',
                'application/octet-stream'
            ],
            'cache_enabled' => true,
            'cache_size' => 1000
        ];
    }
}
