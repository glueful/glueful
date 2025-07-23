<?php

/**
 * Glueful Serialization Attributes Index
 *
 * This file provides a convenient way to import all Glueful serialization
 * attributes. These attributes provide a clean, Glueful-specific interface
 * for Symfony Serializer functionality.
 *
 * Usage:
 * use Glueful\Serialization\Attributes\{Groups, SerializedName, Ignore, MaxDepth, DateFormat};
 *
 * @package Glueful\Serialization\Attributes
 */

declare(strict_types=1);

// Core serialization attributes
require_once __DIR__ . '/Groups.php';
require_once __DIR__ . '/SerializedName.php';
require_once __DIR__ . '/Ignore.php';
require_once __DIR__ . '/MaxDepth.php';
require_once __DIR__ . '/DateFormat.php';

// Advanced serialization attributes
require_once __DIR__ . '/Context.php';
require_once __DIR__ . '/DiscriminatorMap.php';
require_once __DIR__ . '/SerializedPath.php';
