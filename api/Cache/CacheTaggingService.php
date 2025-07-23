<?php

namespace Glueful\Cache;

use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Helpers\CacheHelper;

class CacheTaggingService
{
    use DatabaseConnectionTrait;

    private static array $tagMappings = [];
    private static array $keyTags = [];
    private static bool $enabled = true;
    private static ?CacheStore $cache = null;

    private static array $predefinedTags = [
        'config' => ['app_config', 'database_config', 'cache_config'],
        'permissions' => ['user_permissions', 'role_permissions', 'permission_definitions'],
        'roles' => ['user_roles', 'role_definitions', 'role_hierarchy'],
        'users' => ['user_data', 'user_sessions', 'user_profiles'],
        'auth' => ['jwt_tokens', 'session_data', 'auth_cache'],
        'api' => ['api_routes', 'api_definitions', 'api_metadata'],
        'files' => ['file_metadata', 'upload_cache', 'image_cache'],
        'notifications' => ['notification_templates', 'notification_preferences', 'notification_queue']
    ];

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled && self::getCacheInstance() !== null;
    }

    private static function getCacheInstance(): ?CacheStore
    {
        if (self::$cache === null) {
            self::$cache = CacheHelper::createCacheInstance();
        }
        return self::$cache;
    }

    public static function tagCache(string $key, array $tags): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::$keyTags[$key] = array_unique(array_merge(self::$keyTags[$key] ?? [], $tags));

        foreach ($tags as $tag) {
            if (!isset(self::$tagMappings[$tag])) {
                self::$tagMappings[$tag] = [];
            }
            self::$tagMappings[$tag][] = $key;
            self::$tagMappings[$tag] = array_unique(self::$tagMappings[$tag]);
        }

        self::persistTagMappings();
    }

    public static function getKeyTags(string $key): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        self::loadTagMappings();
        return self::$keyTags[$key] ?? [];
    }

    public static function getTaggedKeys(string $tag): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        self::loadTagMappings();
        return self::$tagMappings[$tag] ?? [];
    }

    public static function invalidateByTag(string $tag): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        self::loadTagMappings();
        $keys = self::getTaggedKeys($tag);
        $invalidated = [];
        $failed = [];

        foreach ($keys as $key) {
            try {
                $cache = self::getCacheInstance();
                if ($cache !== null) {
                    try {
                        $cache->delete($key);
                        $invalidated[] = $key;
                    } catch (\Exception $e) {
                        error_log("Cache delete failed for key '{$key}': " . $e->getMessage());
                        $failed[] = $key;
                    }
                } else {
                    $failed[] = $key;
                }

                self::removeKeyFromTag($key, $tag);
            } catch (\Exception $e) {
                $failed[] = $key;
                error_log("Cache invalidation failed for key '$key': " . $e->getMessage());
            }
        }

        self::persistTagMappings();

        return [
            'status' => 'completed',
            'tag' => $tag,
            'invalidated' => $invalidated,
            'failed' => $failed,
            'total_keys' => count($keys),
            'success_count' => count($invalidated),
            'failure_count' => count($failed)
        ];
    }

    public static function invalidateByTags(array $tags): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        $results = [];
        $allInvalidated = [];
        $allFailed = [];

        foreach ($tags as $tag) {
            $result = self::invalidateByTag($tag);
            $results[$tag] = $result;

            if (isset($result['invalidated'])) {
                $allInvalidated = array_merge($allInvalidated, $result['invalidated']);
            }

            if (isset($result['failed'])) {
                $allFailed = array_merge($allFailed, $result['failed']);
            }
        }

        return [
            'status' => 'completed',
            'tags' => $tags,
            'results' => $results,
            'total_invalidated' => count(array_unique($allInvalidated)),
            'total_failed' => count(array_unique($allFailed)),
            'summary' => [
                'invalidated_keys' => array_unique($allInvalidated),
                'failed_keys' => array_unique($allFailed)
            ]
        ];
    }

    public static function invalidateRelated(string $category): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        $tags = self::$predefinedTags[$category] ?? [];

        if (empty($tags)) {
            return [
                'status' => 'error',
                'message' => "Unknown category: $category",
                'available_categories' => array_keys(self::$predefinedTags)
            ];
        }

        return self::invalidateByTags($tags);
    }

    public static function addPredefinedTag(string $category, array $tags): void
    {
        if (!isset(self::$predefinedTags[$category])) {
            self::$predefinedTags[$category] = [];
        }

        self::$predefinedTags[$category] = array_unique(
            array_merge(self::$predefinedTags[$category], $tags)
        );
    }

    public static function getPredefinedTags(): array
    {
        return self::$predefinedTags;
    }

    public static function getTagStats(): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        self::loadTagMappings();

        $stats = [
            'total_tags' => count(self::$tagMappings),
            'total_tagged_keys' => count(self::$keyTags),
            'tags' => []
        ];

        foreach (self::$tagMappings as $tag => $keys) {
            $stats['tags'][$tag] = [
                'key_count' => count($keys),
                'keys' => $keys
            ];
        }

        return $stats;
    }

    public static function cleanup(): array
    {
        if (!self::isEnabled()) {
            return ['status' => 'disabled'];
        }

        self::loadTagMappings();
        $cleanedKeys = [];
        $cleanedTags = [];

        foreach (self::$keyTags as $key => $tags) {
            $cache = self::getCacheInstance();
            $exists = false;
            if ($cache !== null) {
                try {
                    $exists = $cache->get($key) !== null;
                } catch (\Exception $e) {
                    error_log("Cache get failed for key '{$key}': " . $e->getMessage());
                }
            }
            if (!$exists) {
                unset(self::$keyTags[$key]);
                $cleanedKeys[] = $key;

                foreach ($tags as $tag) {
                    self::removeKeyFromTag($key, $tag);
                }
            }
        }

        foreach (self::$tagMappings as $tag => $keys) {
            if (empty($keys)) {
                unset(self::$tagMappings[$tag]);
                $cleanedTags[] = $tag;
            }
        }

        self::persistTagMappings();

        return [
            'status' => 'completed',
            'cleaned_keys' => $cleanedKeys,
            'cleaned_tags' => $cleanedTags,
            'key_count' => count($cleanedKeys),
            'tag_count' => count($cleanedTags)
        ];
    }

    private static function removeKeyFromTag(string $key, string $tag): void
    {
        if (isset(self::$tagMappings[$tag])) {
            self::$tagMappings[$tag] = array_filter(
                self::$tagMappings[$tag],
                fn($k) => $k !== $key
            );

            if (empty(self::$tagMappings[$tag])) {
                unset(self::$tagMappings[$tag]);
            }
        }

        if (isset(self::$keyTags[$key])) {
            self::$keyTags[$key] = array_filter(
                self::$keyTags[$key],
                fn($t) => $t !== $tag
            );

            if (empty(self::$keyTags[$key])) {
                unset(self::$keyTags[$key]);
            }
        }
    }

    private static function loadTagMappings(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        try {
            $cache = self::getCacheInstance();
            $mappings = null;
            $keyTags = null;
            if ($cache !== null) {
                try {
                    $mappings = $cache->get('_cache_tag_mappings');
                    $keyTags = $cache->get('_cache_key_tags');
                } catch (\Exception $e) {
                    error_log("Cache get failed for tag mappings: " . $e->getMessage());
                }
            }

            if ($mappings !== null) {
                self::$tagMappings = $mappings;
            }

            if ($keyTags !== null) {
                self::$keyTags = $keyTags;
            }

            $loaded = true;
        } catch (\Exception $e) {
            error_log("Failed to load cache tag mappings: " . $e->getMessage());
        }
    }

    private static function persistTagMappings(): void
    {
        try {
            $cache = self::getCacheInstance();
            if ($cache !== null) {
                try {
                    $cache->set('_cache_tag_mappings', self::$tagMappings, 3600 * 24);
                    $cache->set('_cache_key_tags', self::$keyTags, 3600 * 24);
                } catch (\Exception $e) {
                    error_log("Cache set failed for tag mappings: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to persist cache tag mappings: " . $e->getMessage());
        }
    }
}
