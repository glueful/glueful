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
        global $databaseResource;

        $isFile = (isset($param['type']) && $param['type'] === 'file');
        $isImage = (isset($param['type']) && $param['type'] === 'image');
        
        $thumbOptions = [
            'width' => $param['w'] ?? null,
            'height' => $param['h'] ?? null,
            'zoom' => $param['z'] ?? null,
            'quality' => $param['q'] ?? 100
        ];

        // Remove thumb parameters from query
        unset($param['w'], $param['h'], $param['z'], $param['q'], $param['type']);

        // Get blob info from database
        $fields =['fields' => 'id,url,mime_type'];

        if (!isset($param['id'])) {
            return Response::error('Blob ID is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $param = array_merge($param, $fields);
        $definition = self::loadDefinition($function);
        $blob = self::executeQuery('list', $definition, $param);

        if (empty($blob)) {
            return Response::notFound('Blob not found')->send();
        }

        $blobInfo = $blob[0];
        $blobUrl = $blobInfo['url'];
        $fullUrl = file_exists($blobUrl) ? $blobUrl : config('paths.cdn') . $blobUrl;

        if ($isFile) {
            return Response::ok(self::getBlobAsFile($blobInfo))->send();
        }

        if ($isImage) {
            return Response::ok(self::getBlobAsCachedImage($thumbOptions, $fullUrl))->send();
        }

        return Response::ok(self::resizeBlob($thumbOptions, $fullUrl))->send();
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

    private static function getBlobAsCachedImage(array $options, string $src): array 
    {
        $params = [];
        if ($options['width']) $params[] = "w={$options['width']}";
        if ($options['height']) $params[] = "h={$options['height']}";
        if ($options['quality']) $params[] = "q={$options['quality']}";
        if ($options['zoom']) $params[] = "zc={$options['zoom']}";

        $queryString = implode('&', $params);
        $imageUrl = config('paths.cdn') . "images/?src=" . urlencode($src);
        
        if ($queryString) {
            $imageUrl .= '&' . $queryString;
        }

        return ['url' => $imageUrl];
    }

    private static function resizeBlob(array $options, string $src): array 
    {
        $_GET['src'] = $src;
        $_GET['w'] = $options['width'] ?? 'auto';
        $_GET['h'] = $options['height'] ?? 'auto';
        $_GET['q'] = $options['quality'] ?? 100;
        $_GET['zc'] = $options['zoom'] ?? 1;

        require_once(config('paths.api_library') . 'TimThumb.php');
        // TimThumb::start();

        return [];
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
