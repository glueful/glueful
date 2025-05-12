<?php

declare(strict_types=1);

namespace Glueful\ImageProcessing;

/**
 * Image Processing Interface
 *
 * Defines contract for image processing implementations.
 */
interface ImageProcessorInterface
{
    public function processImage(string $source): bool;
    public function outputImage(): void;
}
