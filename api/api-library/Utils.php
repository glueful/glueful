<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

require_once dirname(__DIR__, 2) . '/api/bootstrap.php';

class Utils 
{
    /** @var array<string, \PDO> */
    private static array $resources = [];

    /**
     * @return \PDO
     * @throws \RuntimeException
     */
    public static function getMySQLConnection(string $dbIndex): \PDO 
    {
        global $databaseServer, $connections;

        if (!isset($databaseServer[$dbIndex])) {
            // Fallback to primary database if specified resource doesn't exist
            $dbIndex = 'primary';
            if (!isset($databaseServer[$dbIndex])) {
                throw new \RuntimeException("Invalid database configuration for: $dbIndex");
            }
        }

        if (!isset(self::$resources[$dbIndex])) {
            self::createMySQLConnection($dbIndex);
        }
        return self::$resources[$dbIndex];
    }

    /**
     * @throws \RuntimeException
     */
    public static function createMySQLConnection(string $dbIndex): void 
    {
        global $databaseServer;

        if (!isset($databaseServer[$dbIndex])) {
            // Fallback to primary database if specified resource doesn't exist
            $dbIndex = 'primary';
            if (!isset($databaseServer[$dbIndex])) {
                throw new \RuntimeException("Invalid database configuration for: $dbIndex");
            }
        }

        if (!isset($databaseServer[$dbIndex]) || !is_array($databaseServer[$dbIndex])) {
            // Try to load database config if not already loaded
            $configPath = config('paths.base') . 'db.config.php';
            if (file_exists($configPath)) {
                require_once($configPath);
            }
            
            if (!isset($databaseServer[$dbIndex]) || !is_array($databaseServer[$dbIndex])) {
                throw new \RuntimeException("Invalid database configuration for: $dbIndex");
            }
        }

        $settings = $databaseServer[$dbIndex];
        $required = ['host', 'user', 'pass', 'db'];
        
        foreach ($required as $field) {
            if (!isset($settings[$field])) {
                throw new \RuntimeException("Missing required database setting: $field");
            }
        }
        
        try {
            self::$resources[$dbIndex] = self::createPDOConnection($settings);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
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
}

// Initialize cache engine with optional prefix
CacheEngine::initialize('mapi:');
?>
