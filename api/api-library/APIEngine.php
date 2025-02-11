<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

require_once __DIR__ . '/../bootstrap.php';

use Mapi\Api\Library\{QueryAction, Utils, JWTService, SessionManager};
use Mapi\Api\Http\Response;

class APIEngine 
{
    private static ?string $queryBuilderClass = null;

    public static function initialize(string $queryBuilderClass): void 
    {
        self::$queryBuilderClass = $queryBuilderClass;
    }

    public static function getData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        return self::processData($function, $action, $param, $filter);
    }

    public static function saveData(string $function, string $action, array $param): array 
    {
        return self::processData($function, $action, $param);
    }

    public static function createSession(string $function, string $action, array $param): array 
    {
        if (empty($param)) {
            return Response::error("Fields Not Defined For Authentication")->send();
        }

        $userData = self::getUserData($function, $param);
        if (!$userData) {
            return Response::error("Invalid Credentials")->send();
        }

        try {
            $remember = $param['remember'] ?? false;
            $sessionData = self::createSessionData($userData, $remember);
            return Response::ok([
                'token' => $sessionData['token'],
                'user' => $sessionData['info']
            ])->send();
        } catch (\Exception $e) {
            return Response::error('Failed to create session: ' . $e->getMessage())->send();
        }
    }

    public static function killSession(array $param): array 
    {
        if (!isset($param['token'])) {
            return Response::error('No session token provided', Response::HTTP_BAD_REQUEST)->send();
        }

        if (SessionManager::destroy($param['token'])) {
            return Response::ok(null, 'Logout successful')->send();
        }

        return Response::error('Session already invalidated', Response::HTTP_BAD_REQUEST)->send();
    }

    public static function validateSession(?string $function, ?string $action, array $param): array 
    {
        if (!isset($param['token'])) {
            return Response::unauthorized('No session token provided')->send();
        }

        $session = SessionManager::get($param['token']);
        if (!$session) {
            return Response::unauthorized('Invalid or expired session')->send();
        }

        if (!self::validateSecurityLevel($session)) {
            return Response::error('Session security check failed', Response::HTTP_FORBIDDEN)->send();
        }

        return Response::ok($session)->send();
    }

    public static function getBlob(string $function, string $action, array $param): array 
    {
        try {
            if (!isset($param['id'])) {
                return Response::error('Blob ID is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $blob = self::getBlobInfo($function, $param);
            if (empty($blob)) {
                return Response::notFound('Blob not found')->send();
            }

            $blobInfo = $blob[0];
            $fullUrl = self::getBlobUrl($blobInfo['url']);
            $requestType = $param['type'] ?? 'image';

            return match($requestType) {
                'file' => Response::ok(self::getBlobAsFile($blobInfo))->send(),
                'image' => Response::ok(self::processImageBlob($fullUrl, $param))->send(),
                default => Response::error('Invalid blob type requested')->send()
            };

        } catch (\Exception $e) {
            error_log("Blob processing error: " . $e->getMessage());
            return Response::error('Failed to process blob')->send();
        }
    }

    private static function getBlobInfo(string $function, array $param): array 
    {
        $fields = ['fields' => 'id,url,mime_type'];
        $queryParams = array_merge($param, $fields);
        
        $definition = self::loadDefinition($function);
        return self::executeQuery('list', $definition, $queryParams);
    }

    private static function getBlobUrl(string $url): string 
    {
        return file_exists($url) ? $url : config('paths.cdn') . $url;
    }

    private static function processImageBlob(string $src, array $params): array 
    {
        try {
            $config = [
                'maxWidth' => 1500,
                'maxHeight' => 1500,
                'quality' => (int)($params['q'] ?? 90),
                'width' => isset($params['w']) ? (int)$params['w'] : null,
                'height' => isset($params['h']) ? (int)$params['h'] : null,
                'zoom' => isset($params['z']) ? (int)$params['z'] : null,
                'memoryLimit' => '256M',
                'allowExternal' => true,
                'cacheDir' => config('paths.cache') . '/images'
            ];

            $thumbnailer = new \Mapi\ImageProcessing\TimThumb($config);
            
            if (!$thumbnailer->processImage($src)) {
                throw new \RuntimeException("Failed to process image");
            }

            // Generate cache path and filename
            $cacheKey = md5($src . serialize($config));
            $cachedFilename = $cacheKey . '.jpg';
            $cachePath = $config['cacheDir'] . '/' . $cachedFilename;

            // Ensure cache directory exists and save image
            if (!is_dir($config['cacheDir'])) {
                mkdir($config['cacheDir'], 0755, true);
            }

            ob_start();
            $thumbnailer->outputImage();
            $imageData = ob_get_clean();

            if (!file_put_contents($cachePath, $imageData)) {
                throw new \RuntimeException("Failed to save processed image");
            }

            return [
                'url' => config('paths.cdn') . 'cache/images/' . $cachedFilename,
                'cached' => true,
                'dimensions' => [
                    'width' => $config['width'] ?? imagesx(imagecreatefromstring($imageData)),
                    'height' => $config['height'] ?? imagesy(imagecreatefromstring($imageData))
                ],
                'size' => strlen($imageData)
            ];

        } catch (\Exception $e) {
            error_log("Image processing error: " . $e->getMessage());
            return [
                'url' => $src,
                'cached' => false,
                'error' => 'Failed to process image'
            ];
        }
    }

    public static function updateSessionData(string $token, array $updates): array 
    {
        $session = SessionManager::get($token);
        if (!$session) {
            return Response::unauthorized('Invalid or expired session')->send();
        }

        // Update specific session data fields
        foreach ($updates as $key => $value) {
            if (isset($session[$key])) {
                $session[$key] = $value;
            }
        }

        // If user info is being updated, refresh permissions
        if (isset($updates['info']) && isset($session['uid'])) {
            $session['role'] = self::getUserPermissions($session['uid']);
        }

        // Generate new token with updated data
        $newToken = JWTService::generate($session, config('services.jwt.default_expiration'));
        SessionManager::update($token, $session, $newToken);

        return Response::ok([
            'token' => $newToken,
            'session' => $session
        ])->send();
    }

    public static function refreshPermissions(string $token): array 
    {
        $session = SessionManager::get($token);
        if (!$session) {
            return Response::unauthorized('Invalid or expired session')->send();
        }

        // Refresh permissions
        $session['role'] = self::getUserPermissions($session['uid']);

        // Generate new token with updated permissions
        $newToken = JWTService::generate($session, config('services.jwt.default_expiration'));
        SessionManager::update($token, $session, $newToken);

        return Response::ok([
            'token' => $newToken,
            'permissions' => $session['role']
        ])->send();
    }

    private static function createSessionData(array $userInfo, bool $remember): array 
    {
        $sessionData = [
            'uid' => $userInfo['id'],
            'info' => array_diff_key($userInfo, ['password' => '']),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unspecified',
            'role' => self::getUserPermissions($userInfo['id']),
            'login_timestamp' => gmdate('Y-m-d H:i:s')
        ];

        $expiration = $remember ? config('services.jwt.remember_expiration') : config('services.jwt.default_expiration');
        $token = JWTService::generate($sessionData, $expiration);
        
        return [...$sessionData, 'token' => $token];
    }

    private static function getUserPermissions(int $userID): array 
    {
        global $databaseResource;
        $currentResource = $databaseResource;
        $databaseResource = 'users';
        Utils::createMySQLResource($databaseResource);

        // Get user roles using JSON definition
        $param = ['fields' => 'user_id,role_id', 'user_id' => $userID];

        $definition = self::loadDefinition('user_roles_lookup');
        $userRole = self::executeQuery('list', $definition, $param);
        
        if (empty($userRole)) {
            return [];
        }

        // Get permissions using JSON definition
        $roleID = $userRole[0]['role_id'];
        $_SESSION['role_id'] = $roleID;

        $param = ['fields' => 'role_id,model,permissions', 'role_id' => $roleID];

        $definition = self::loadDefinition('permissions');
        $permissions = self::executeQuery('list', $definition, $param);

        // Format permissions
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = $permission['permissions'];
            $formattedPermissions[$model] = $perms;
        }

        // Restore original database resource
        Utils::createMySQLResource($currentResource);
        $databaseResource = $currentResource;

        return $formattedPermissions;
    }

    private static function processData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        $definition = self::loadDefinition($function);
        
        if ($filter) {
            $param['_filter'] = $filter;
        }

        return self::executeQuery(
            $action, 
            $definition, 
            self::sanitizeParams($param)
        );
    }

    private static function executeQuery(string $action, array $definition, array $params): array 
    {
        if (!self::$queryBuilderClass) {
            throw new \RuntimeException("Query builder not initialized");
        }

        $builder = self::$queryBuilderClass;
        return $builder::query($builder::prepare(QueryAction::fromString($action), $definition, $params));
    }

    private static function loadDefinition(string $function): array 
    {
        global $databaseResource;
        
        $path = config('paths.json_definitions') . $databaseResource . '.' . $function . ".json";
        
        if (!file_exists($path)) {
            throw new \RuntimeException(
                "The definition $databaseResource.$function.json does not exist",
                Response::HTTP_NOT_FOUND
            );
        }

        $definition = json_decode(file_get_contents($path), true);
        if (!$definition) {
            throw new \RuntimeException("Invalid JSON definition");
        }

        return $definition;
    }

    private static function sanitizeParams(array $params): array 
    {
        return array_map(
            fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            $params
        );
    }
    
    private static function validateSecurityLevel(array $sessionData): bool 
    {
        return match($sessionData['type']) {
            FLEXIBLE_SECURITY => true,
            MORDERATE_SECURITY => $sessionData['ip'] === $_SERVER['REMOTE_ADDR'],
            STRICT_SECURITY => $sessionData['ip'] === $_SERVER['REMOTE_ADDR'] 
                && $sessionData['user_agent'] === ($_SERVER['HTTP_USER_AGENT'] ?? 'Unspecified'),
            default => false
        };
    }

    private static function getBlobAsFile(array $blob): array 
    {
        $url = config('paths.cdn') . $blob['url'];
        $mimeType = $blob['mime_type'];

        return [
            'url' => $url,
            'type' => self::getMimeType($mimeType)
        ];
    }

    private static function getMimeType(string $mime): string 
    {
        return match(true) {
            str_starts_with($mime, 'image/') => 'image',
            str_contains($mime, 'word') => 'word',
            str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet') => 'excel',
            str_contains($mime, 'powerpoint') || str_contains($mime, 'presentation') => 'powerpoint',
            $mime === 'application/pdf' => 'pdf',
            str_contains($mime, 'zip') || str_contains($mime, 'compressed') => 'archive',
            default => 'file'
        };
    }

    protected static function requireAuthentication(): void 
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token) || !SessionManager::get($token)) {
            throw new \RuntimeException('Unauthorized access', 401);
        }
    }

    private static function getUserData(string $function, array $param): ?array 
    {
        try {
            // Add status check to parameters
            $param['status'] = 'active';
            
            // Hash password if provided
            if (isset($param['password'])) {
                $param['password'] = password_hash($param['password'], PASSWORD_DEFAULT);
            }

            $definition = self::loadDefinition('users');
            $result = self::executeQuery('list', $definition, self::sanitizeParams($param));
            
            if (count($result) !== 1) {
                return null;
            }
            
            $userData = $result[0];
            
            // Verify password if provided
            if (isset($param['password']) && !password_verify($param['password'], $userData['password'])) {
                return null;
            }
            
            unset($userData['password']); // Remove sensitive data
            
            return [
                'id' => $userData['id'],
                'username' => $userData['username'] ?? null,
                'email' => $userData['email'] ?? null,
                'role' => $userData['role'] ?? 'user',
                'created_at' => $userData['created_at'] ?? null,
                'last_login' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            error_log("Failed to get user data: " . $e->getMessage());
            return null;
        }
    }
}

// Initialize with appropriate query builder
$queryBuilderClass = match(config('database.engine')) {
    default => MySQLQueryBuilder::class
};
APIEngine::initialize($queryBuilderClass);
