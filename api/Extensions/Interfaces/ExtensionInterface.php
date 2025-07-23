<?php

declare(strict_types=1);

namespace Glueful\Extensions\Interfaces;

interface ExtensionInterface
{
    /**
     * Initialize extension
     *
     * @return void
     */
    public static function initialize(): void;

    /**
     * Get extension metadata
     *
     * @return array Extension metadata
     */
    public static function getMetadata(): array;

    /**
     * Check extension health
     *
     * @return array Health status
     */
    public static function checkHealth(): array;
}
