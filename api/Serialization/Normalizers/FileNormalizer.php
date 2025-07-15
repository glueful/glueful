<?php

declare(strict_types=1);

namespace Glueful\Serialization\Normalizers;

use Glueful\Serialization\File;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * File Normalizer
 *
 * Handles serialization of File objects with metadata,
 * security considerations, and URL generation.
 */
class FileNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Normalize File object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        if (!$object instanceof File) {
            throw new \InvalidArgumentException('Object must be an instance of File');
        }

        $data = [
            'name' => $object->getName(),
            'original_name' => $object->getOriginalName(),
            'size' => $object->getSize(),
            'mime_type' => $object->getMimeType(),
            'extension' => $object->getExtension(),
            'created_at' => $object->getCreatedAt()->format('c'),
        ];

        // Include URL only if file is publicly accessible
        if ($object->isPublic()) {
            $data['url'] = $object->getUrl();
        }

        // Include metadata if available
        if ($object->hasMetadata()) {
            $data['metadata'] = $object->getMetadata();
        }

        // Include security hash for verification
        $data['hash'] = $object->getHash();

        return $data;
    }

    /**
     * Denormalize data to File object
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): File
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Data must be an array');
        }

        $file = new File(
            $data['name'] ?? '',
            $data['original_name'] ?? $data['name'] ?? '',
            $data['size'] ?? 0,
            $data['mime_type'] ?? 'application/octet-stream'
        );

        if (isset($data['metadata'])) {
            $file->setMetadata($data['metadata']);
        }

        return $file;
    }

    /**
     * Check if normalization is supported
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof File;
    }

    /**
     * Check if denormalization is supported
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === File::class || is_subclass_of($type, File::class);
    }

    /**
     * Get supported types for this normalizer
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            File::class => true,
        ];
    }
}
