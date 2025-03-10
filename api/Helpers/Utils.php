<?php
declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Security\RandomStringGenerator;
use Glueful\Cache\CacheEngine;
use PDO;
use PDOException;

use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\JWTService;

/**
 * Utility Functions
 * 
 * Provides common utility functions used throughout the API.
 * Handles database connections, UUID generation, and helper methods.
 */
class Utils 
{

    public static function export(
        string $format, 
        array $data, 
        string $key = '', 
        bool $encrypt = false
    ): void {
        match($format) {
            'xml' => self::exportXML($data, $key, $encrypt),
            'yaml' => self::exportYAML($data, $key, $encrypt),
            default => self::exportJSON($data, $key, $encrypt)
        };
    }

    private static function exportJSON(array $data, string $key, bool $encrypt): void 
    {
        $json = json_encode($data);
        
        // if ($encrypt && !empty($key)) {
        //     $json = GibberishAES::enc($json, $key);
        // }
        
        header('Content-Type: application/json');
        echo $json;
    }

    private static function exportXML(array $data, string $key, bool $encrypt): void 
    {
        // Convert array to XML
        $xml = new \SimpleXMLElement('<root/>');
        array_walk_recursive($data, [$xml, 'addChild']);
        $output = $xml->asXML();

        // if ($encrypt && !empty($key)) {
        //     $output = GibberishAES::enc($output, $key);
        // }

        header('Content-Type: application/xml');
        echo $output;
    }

    private static function exportYAML(array $data, string $key, bool $encrypt): void 
    {
        if (!function_exists('yaml_emit')) {
            self::exportJSON($data, $key, $encrypt);
            return;
        }

        $yaml = yaml_emit($data);
        
        // if ($encrypt && !empty($key)) {
        //     $yaml = GibberishAES::enc($yaml, $key);
        // }

        header('Content-Type: application/x-yaml');
        echo $yaml;
    }

    public static function cacheKey(string ...$parts): string 
    {
        return implode(':', array_filter($parts));
    }

    public static function withCache(string $key, callable $callback, ?int $ttl = 3600): mixed 
    {
        if (!CacheEngine::isEnabled()) {
            return $callback();
        }

        $cached = CacheEngine::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();
        CacheEngine::set($key, $result, $ttl);
        return $result;
    }

    public static function getSession(): ?array 
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$token) {
            return null;
        }

        // Remove 'Bearer ' if present
        $token = str_replace('Bearer ', '', $token);
        return SessionCacheManager::getSession($token);
    }

    public static function getCurrentUser(): ?array 
    {
        $session = self::getSession();
        return $session['user'] ?? null;
    }

    /**
     * Generate NanoID
     * 
     * Creates unique identifier using NanoID algorithm.
     * 
     * @param int $size Length of ID to generate
     * @return string Generated NanoID
     */
    public static function generateNanoID(?int $length = null): string {

        if (!$length) {
            $length = (int)config('security.nanoid_length', 12);
        }
        return RandomStringGenerator::generate(
            length: $length
        );
    }

    /**
     * Get user information from JWT token
     * 
     * @param string|null $token JWT token
     * @return array{uuid: string, role: string, info: array}|null User information or null if invalid
     */
    public static function getUser(?string $token = null): ?array
    {
        if (!$token) {
            // Try to get token from Authorization header
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $token = str_replace('Bearer ', '', $headers['Authorization']);
            }
        }

        if (!$token) {
            return null;
        }

        try {
            // Decode token
            $payload = JWTService::decode($token);
            
            if (!isset($payload['uuid'], $payload['role'], $payload['info'])) {
                return null;
            }

            return [
                'uuid' => $payload['uuid'],
                'role' => $payload['role'],
                'info' => $payload['info']
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get Database Connection Role
     * 
     * Determines if current database connection is primary or secondary (replica).
     * Used for read/write decision making and load balancing.
     * 
     * Features:
     * - Reads from configuration
     * - Supports multiple database engines
     * - Fallback to primary if not specified
     * - Used in replication scenarios
     * 
     * @return string Connection role ('primary' or 'secondary')
     * 
     * @example
     * ```php
     * $role = Utils::getDatabaseRole();
     * if ($role === 'primary') {
     *     // Perform write operations
     * }
     * ```
     */
    public static function getDatabaseRole(): string 
    {
        $engine = config('database.engine');
        $dbConfig = array_merge(
            config("database.{$engine}") ?? [],
        );

        return $dbConfig['role'] ?? 'primary';
    }

     /**
     * Pad column text for table output
     * 
     * @param string $text Text to pad
     * @param int $length Column length
     * @return string Padded text
     */
    public static function padColumn(string $text, int $length): string
    {
        return str_pad($text, $length);
    }
}

// Initialize cache engine with optional prefix
CacheEngine::initialize('glueful:');
?>
