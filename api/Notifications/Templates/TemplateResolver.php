<?php

declare(strict_types=1);

namespace Glueful\Notifications\Templates;

use Glueful\Notifications\Models\NotificationTemplate;
use InvalidArgumentException;

/**
 * Template Resolver
 *
 * Resolves notification templates based on notification type, channel, and template name.
 * Handles template lookups and fallbacks for different notification scenarios.
 *
 * @package Glueful\Notifications\Templates
 */
class TemplateResolver
{
    /**
     * @var array Template cache for quick lookups
     */
    protected array $templateCache = [];

    /**
     * @var array Fallback patterns for templates
     */
    protected array $fallbackPatterns = [];

    /**
     * TemplateResolver constructor
     *
     * @param array $fallbackPatterns Optional custom fallback patterns
     */
    public function __construct(array $fallbackPatterns = [])
    {
        // Default fallback patterns if none provided
        $this->fallbackPatterns = !empty($fallbackPatterns) ? $fallbackPatterns : [
            // Try type-specific template for channel first
            '%s.%s.%s', // [type].[name].[channel]

            // Fall back to default template for type and channel
            '%s.default.%s', // [type].default.[channel]

            // Fall back to generic template for channel
            'default.%s.%s', // default.[name].[channel]

            // Fall back to completely generic template
            'default.default.%s' // default.default.[channel]
        ];
    }

    /**
     * Resolve a template for a specific notification type, name, and channel
     *
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @param array $templates Array of available templates
     * @return NotificationTemplate|null The resolved template or null if not found
     */
    public function resolve(string $type, string $name, string $channel, array $templates): ?NotificationTemplate
    {
        $cacheKey = "{$type}.{$name}.{$channel}";

        // Check if already resolved and cached
        if (isset($this->templateCache[$cacheKey])) {
            return $this->templateCache[$cacheKey];
        }

        // Try each fallback pattern
        foreach ($this->fallbackPatterns as $pattern) {
            $templateKey = sprintf($pattern, $type, $name, $channel);

            // Check if this pattern resolves to a template
            foreach ($templates as $template) {
                $type = $template->getNotificationType();
                $name = $template->getName();
                $channel = $template->getChannel();
                $templateId = $this->generateTemplateId($type, $name, $channel);

                if ($templateId === $templateKey) {
                    // Cache the result
                    $this->templateCache[$cacheKey] = $template;
                    return $template;
                }
            }
        }

        // No template found for any pattern
        $this->templateCache[$cacheKey] = null;
        return null;
    }

    /**
     * Resolve templates for all channels
     *
     * @param string $type Notification type
     * @param string $name Template name
     * @param array $channels List of channels to resolve for
     * @param array $templates Array of available templates
     * @return array Map of channel => template
     */
    public function resolveForChannels(string $type, string $name, array $channels, array $templates): array
    {
        $results = [];

        foreach ($channels as $channel) {
            $results[$channel] = $this->resolve($type, $name, $channel, $templates);
        }

        return $results;
    }

    /**
     * Generate a unique template identifier
     *
     * @param string $type Notification type
     * @param string $name Template name
     * @param string $channel Channel name
     * @return string Template identifier
     */
    public function generateTemplateId(string $type, string $name, string $channel): string
    {
        return "{$type}.{$name}.{$channel}";
    }

    /**
     * Parse a template identifier into its components
     *
     * @param string $templateId Template identifier
     * @return array Array with type, name, and channel
     * @throws InvalidArgumentException If the template ID format is invalid
     */
    public function parseTemplateId(string $templateId): array
    {
        $parts = explode('.', $templateId);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException(
                "Invalid template ID format: {$templateId}. Expected format: type.name.channel"
            );
        }

        return [
            'type' => $parts[0],
            'name' => $parts[1],
            'channel' => $parts[2]
        ];
    }

    /**
     * Clear the template cache
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->templateCache = [];
        return $this;
    }

    /**
     * Set custom fallback patterns
     *
     * @param array $patterns Fallback patterns
     * @return self
     */
    public function setFallbackPatterns(array $patterns): self
    {
        $this->fallbackPatterns = $patterns;
        return $this;
    }

    /**
     * Get current fallback patterns
     *
     * @return array Current fallback patterns
     */
    public function getFallbackPatterns(): array
    {
        return $this->fallbackPatterns;
    }
}
