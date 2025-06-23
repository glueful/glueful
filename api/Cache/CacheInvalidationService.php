<?php

namespace Glueful\Cache;

use Glueful\Helpers\CacheHelper;

class CacheInvalidationService
{
    private static array $patterns = [];
    private static bool $enabled = true;
    private static ?CacheStore $cache = null;
    private static array $stats = [
        'invalidations' => 0,
        'patterns_matched' => 0,
        'keys_invalidated' => 0,
        'errors' => 0
    ];

    private static array $defaultPatterns = [
        'user_updated' => [
            'tags' => ['user_data', 'user_sessions', 'user_permissions'],
            'keys' => ['user_{id}', 'user_permissions_{id}', 'user_roles_{id}']
        ],
        'role_updated' => [
            'tags' => ['role_definitions', 'user_roles', 'role_permissions'],
            'keys' => ['role_{id}', 'role_permissions_{id}']
        ],
        'permission_updated' => [
            'tags' => ['permission_definitions', 'user_permissions', 'role_permissions'],
            'keys' => ['permission_{id}']
        ],
        'config_updated' => [
            'tags' => ['app_config', 'database_config', 'cache_config'],
            'keys' => ['config', 'app_settings']
        ],
        'notification_template_updated' => [
            'tags' => ['notification_templates'],
            'keys' => ['notification_template_{id}', 'notification_templates']
        ],
        'file_uploaded' => [
            'tags' => ['file_metadata'],
            'keys' => ['file_list', 'recent_uploads']
        ],
        'auth_session_created' => [
            'tags' => ['auth_cache'],
            'keys' => ['active_sessions']
        ],
        'auth_session_destroyed' => [
            'tags' => ['auth_cache', 'user_sessions'],
            'keys' => ['session_{session_id}', 'user_sessions_{user_id}']
        ]
    ];

    public static function enable(): void
    {
        self::$enabled = true;
        self::registerDefaultPatterns();
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled && self::getCacheInstance() !== null && CacheTaggingService::isEnabled();
    }

    private static function getCacheInstance(): ?CacheStore
    {
        if (self::$cache === null) {
            try {
                self::$cache = CacheHelper::createCacheInstance();
            } catch (\Exception $e) {
                // Cache not available
                self::$cache = null;
            }
        }
        return self::$cache;
    }

    public static function registerPattern(string $event, array $pattern): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::validatePattern($pattern);
        self::$patterns[$event] = $pattern;
    }

    public static function registerPatterns(array $patterns): void
    {
        foreach ($patterns as $event => $pattern) {
            self::registerPattern($event, $pattern);
        }
    }

    public static function removePattern(string $event): void
    {
        unset(self::$patterns[$event]);
    }

    public static function getPatterns(): array
    {
        return self::$patterns;
    }

    public static function invalidateByEvent(string $event, array $context = []): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        if (!isset(self::$patterns[$event])) {
            return [
                'status' => 'no_pattern',
                'event' => $event,
                'message' => "No invalidation pattern registered for event: $event"
            ];
        }

        $pattern = self::$patterns[$event];
        $results = [
            'status' => 'completed',
            'event' => $event,
            'context' => $context,
            'invalidated_tags' => [],
            'invalidated_keys' => [],
            'errors' => []
        ];

        try {
            if (isset($pattern['tags']) && is_array($pattern['tags'])) {
                foreach ($pattern['tags'] as $tag) {
                    $tagResult = CacheTaggingService::invalidateByTag($tag);
                    if ($tagResult['status'] === 'completed') {
                        $results['invalidated_tags'][$tag] = $tagResult;
                        self::$stats['patterns_matched']++;
                    } else {
                        $results['errors'][] = "Failed to invalidate tag: $tag";
                        self::$stats['errors']++;
                    }
                }
            }

            if (isset($pattern['keys']) && is_array($pattern['keys'])) {
                foreach ($pattern['keys'] as $keyPattern) {
                    $keys = self::expandKeyPattern($keyPattern, $context);
                    foreach ($keys as $key) {
                        try {
                            $cache = self::getCacheInstance();
                            if ($cache !== null) {
                                try {
                                    $cache->delete($key);
                                    $results['invalidated_keys'][] = $key;
                                    self::$stats['keys_invalidated']++;
                                } catch (\Exception $e) {
                                    error_log("Cache delete failed for key '{$key}': " . $e->getMessage());
                                    $results['errors'][] = "Failed to delete key '$key': " . $e->getMessage();
                                    self::$stats['errors']++;
                                }
                            } else {
                                $results['errors'][] = "Cache not available for key '$key'";
                                self::$stats['errors']++;
                            }
                        } catch (\Exception $e) {
                            $results['errors'][] = "Failed to invalidate key '$key': " . $e->getMessage();
                            self::$stats['errors']++;
                        }
                    }
                }
            }

            self::$stats['invalidations']++;
        } catch (\Exception $e) {
            $results['errors'][] = "Pattern execution failed: " . $e->getMessage();
            self::$stats['errors']++;
        }

        return $results;
    }

    public static function invalidateByEvents(array $events, array $context = []): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        $results = [
            'status' => 'completed',
            'events' => $events,
            'context' => $context,
            'results' => [],
            'summary' => [
                'total_events' => count($events),
                'successful_events' => 0,
                'failed_events' => 0,
                'total_invalidated_keys' => 0,
                'total_errors' => 0
            ]
        ];

        foreach ($events as $event) {
            $eventResult = self::invalidateByEvent($event, $context);
            $results['results'][$event] = $eventResult;

            if ($eventResult['status'] === 'completed') {
                $results['summary']['successful_events']++;
                if (isset($eventResult['invalidated_keys'])) {
                    $results['summary']['total_invalidated_keys'] += count($eventResult['invalidated_keys']);
                }
            } else {
                $results['summary']['failed_events']++;
            }

            if (isset($eventResult['errors'])) {
                $results['summary']['total_errors'] += count($eventResult['errors']);
            }
        }

        return $results;
    }

    public static function onEvent(string $event, array $data = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::invalidateByEvent($event, $data);
        } catch (\Exception $e) {
            error_log("Cache invalidation failed for event '$event': " . $e->getMessage());
        }
    }

    public static function registerWithEventDispatcher($dispatcher = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if ($dispatcher === null) {
            // Skip registration if no dispatcher provided
            return;
        }

        $events = array_keys(self::$patterns);
        foreach ($events as $event) {
            // Use Symfony EventDispatcher interface
            $dispatcher->addListener($event, [self::class, 'onEvent']);
        }
    }

    public static function getStats(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'registered_patterns' => count(self::$patterns),
            'statistics' => self::$stats,
            'patterns' => array_keys(self::$patterns)
        ];
    }

    public static function resetStats(): void
    {
        self::$stats = [
            'invalidations' => 0,
            'patterns_matched' => 0,
            'keys_invalidated' => 0,
            'errors' => 0
        ];
    }

    public static function warmupPatterns(): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        self::registerDefaultPatterns();

        return [
            'status' => 'completed',
            'patterns_loaded' => count(self::$patterns),
            'event_listeners_registered' => count(self::$patterns)
        ];
    }

    private static function registerDefaultPatterns(): void
    {
        foreach (self::$defaultPatterns as $event => $pattern) {
            self::$patterns[$event] = $pattern;
        }
    }

    private static function validatePattern(array $pattern): void
    {
        if (!isset($pattern['tags']) && !isset($pattern['keys'])) {
            throw new \InvalidArgumentException("Pattern must contain either 'tags' or 'keys' array");
        }

        if (isset($pattern['tags']) && !is_array($pattern['tags'])) {
            throw new \InvalidArgumentException("Pattern 'tags' must be an array");
        }

        if (isset($pattern['keys']) && !is_array($pattern['keys'])) {
            throw new \InvalidArgumentException("Pattern 'keys' must be an array");
        }
    }

    private static function expandKeyPattern(string $pattern, array $context): array
    {
        $keys = [];

        if (strpos($pattern, '{') === false) {
            $keys[] = $pattern;
            return $keys;
        }

        preg_match_all('/\{(\w+)\}/', $pattern, $matches);
        $placeholders = $matches[1];

        if (empty($placeholders)) {
            $keys[] = $pattern;
            return $keys;
        }

        $expandedPattern = $pattern;
        foreach ($placeholders as $placeholder) {
            if (isset($context[$placeholder])) {
                $value = $context[$placeholder];
                if (is_array($value)) {
                    foreach ($value as $val) {
                        $tempPattern = str_replace('{' . $placeholder . '}', $val, $expandedPattern);
                        if (!in_array($tempPattern, $keys)) {
                            $keys[] = $tempPattern;
                        }
                    }
                } else {
                    $expandedPattern = str_replace('{' . $placeholder . '}', $value, $expandedPattern);
                }
            } else {
                $expandedPattern = str_replace('{' . $placeholder . '}', '*', $expandedPattern);
            }
        }

        if (!in_array($expandedPattern, $keys) && !strpos($expandedPattern, '{')) {
            $keys[] = $expandedPattern;
        }

        return array_unique($keys);
    }
}
