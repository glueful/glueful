<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

use Mapi\Api\Library\Security\RandomStringGenerator;

class Utils 
{
    /** @var array<string, \PDO> */
    private static array $resources = [];

    public static function init(): void
    {
        self::$resources = config('database.primary');
    }

    /**
     * @return \PDO
     * @throws \RuntimeException
     */
    public static function getMySQLConnection(array $settings = null): \PDO 
    {
        $connectionKey = 'primary';
        if (!isset(self::$resources[$connectionKey])) {
            // Always use primary database settings
            $dbSettings = $settings ?? config('database.primary');
            
            if (!$dbSettings) {
                throw new \RuntimeException("Invalid database configuration");
            }

            try {
                self::$resources[$connectionKey] = self::createPDOConnection($dbSettings);
            } catch (\PDOException $e) {
                throw new \RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$resources[$connectionKey];
    }

    public static function createPDOConnection(array $settings): \PDO 
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%d;charset=utf8mb4',
            $settings['host'] ?? 'localhost',
            $settings['db'] ?? '',
            $settings['port'] ?? 3306
        );

        return new \PDO(
            $dsn,
            $settings['user'] ?? 'root',
            $settings['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private static function createPgPDO(array $settings): \PDO 
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $settings['host'] ?? 'localhost',
            $settings['port'] ?? 5432,
            $settings['db'] ?? ''
        );

        return new \PDO(
            $dsn,
            $settings['user'] ?? 'postgres',
            $settings['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
            ]
        );
    }

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
        return SessionManager::get($token);
    }

    public static function getCurrentUser(): ?array 
    {
        $session = self::getSession();
        return $session['user'] ?? null;
    }

    public static function generateNanoID(int $length = 12): string {
        return RandomStringGenerator::generate(
            length: $length,
            charset: RandomStringGenerator::CHARSET_ALPHANUMERIC
        );
    }
}

// Initialize cache engine with optional prefix
CacheEngine::initialize('mapi:');
?>
