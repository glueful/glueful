<?php
declare(strict_types=1);
namespace Glueful\Api\Library;

use PDO;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Api\Library\{Utils, JWTService, SessionManager};
use Glueful\Api\Extensions\Uploader\Storage\StorageInterface;
use Glueful\Api\Http\Response;

class APIEngine{
    private static PDO $db;
    private static DatabaseDriver $driver;
    private static string $currentResource;

    public static function initialize(): void 
    {
        self::initializeDatabase();
    }

    /**
     * Initialize API Engine with new database connection
     */
    private static function initializeDatabase(): void 
    {
        try {
            // Get database configuration
            $dbConfig = config('database');
            
            // Create database connection
            $connection = new Connection();
            
            // Store connection and driver
            self::$db = $connection->getPDO();
            self::$driver = $connection->getDriver();
            
            // Set current database resource
            self::$currentResource = config('database.json_prefix');
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to initialize database: " . $e->getMessage());
        }
    }

     /**
     * Retrieve data from database
     * 
     * @param string $function Resource/table name
     * @param string $action Query action (list, view, etc)
     * @param array $param Query parameters
     * @param array|null $filter Optional filters
     * @return array Query results
     */
    public static function getData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        return self::processData($function, $action, $param, $filter);
    }

    /**
     * Save data to database
     * 
     * Handles insert, update, and delete operations.
     * 
     * @param string $function Resource/table name
     * @param string $action Save action type
     * @param array $param Data to save
     * @return array Operation result
     */
    public static function saveData(string $function, string $action, array $param): array 
    {
        return self::processData($function, $action, $param);
    }

    /**
     * Create user session
     * 
     * Handles user authentication and session creation.
     * 
     * @param string $function Authentication function
     * @param string $action Auth action type
     * @param array $param Credentials and options
     * @return array Session data or error
     */
    public static function createSession(string $function, string $action, array $param): array 
    {
        if (empty($param)) {
            return Response::error("Fields Not Defined For Authentication")->send();
        }

        $userData = self::getUserData($function, $param);
        if (!$userData) {
            return Response::error("Invalid Credentials")->send();
        }
        unset($userData['id']);
        try {
            $remember = $param['remember'] ?? false;
            $sessionData = self::createSessionData($userData, $remember);
            return Response::ok([
                'tokens' => [
                    'access_token' => $sessionData['token'],
                    'refresh_token' => $sessionData['refresh_token'],
                    'expires_in' => config('session.access_token_lifetime'),
                    'token_type' => 'Bearer'
                ],
                'user' => $userData,
            ])->send();
        } catch (\Exception $e) {
            return Response::error('Failed to create session: ' . $e->getMessage())->send();
        }
    }

    /**
     * End user session
     * 
     * @param array $param Session parameters
     * @return array Operation result
     */
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

    /**
     * Validate session token
     * 
     * @param string|null $function Optional resource to check
     * @param string|null $action Optional action to verify
     * @param array $params Validation parameters
     * @return array Validation result
     */
    public static function validateSession(?string $function, ?string $action, array $params): array 
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            return Response::unauthorized('No session token provided')->send();
        }

        // Try to get session
        $session = SessionManager::get($token);
        
        // If session not found, check if it's an expired access token
        if (!$session && isset($params['refresh_token'])) {
            $tokens = TokenManager::refreshTokens($params['refresh_token']);
            if ($tokens) {
                return Response::ok([
                    'token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token']
                ])->send();
            }
        }

        if (!$session) {
            return Response::unauthorized('Invalid or expired session')->send();
        }

        if ($function) {
            $prefix = str_contains($function, '.') ? 'api.ext.' : 'api.' . self::$currentResource . '.';
            $model = $prefix . $function;

            if (!Permissions::hasPermission($model, Permission::VIEW, $token)) {
                return Response::error('Permission denied', Response::HTTP_FORBIDDEN)->send();
            }
        }
        return Response::ok($session)->send();
    }

    private static function createSessionData(array $userInfo, bool $remember): array 
    {
        $sessionData = [
            'uuid' => $userInfo['uuid'],
            'info' => array_diff_key($userInfo, ['password' => '']),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unspecified',
            'role' => $userInfo['role'],
            'permissions' => self::getUserPermissions($userInfo['uuid']),
            'login_timestamp' => gmdate('Y-m-d H:i:s')
        ];

        // Generate token pair
        $tokens = TokenManager::generateTokenPair($sessionData);
        
        // Add refresh token to session data for storage
        $sessionData['refresh_token'] = $tokens['refresh_token'];
        
        // Store session with access token
        SessionManager::start($sessionData, $tokens['access_token']);
        
        return [
            ...$sessionData,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token']
        ];
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
        $newToken = JWTService::generate($session, config('session.access_token_lifetime'));
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
        $newToken = JWTService::generate($session, config('session.access_token_lifetime'));
        SessionManager::update($token, $session, $newToken);

        return Response::ok([
            'token' => $newToken,
            'permissions' => $session['role']
        ])->send();
    }

    /**
 * Get user data using QueryBuilder
 */
private static function getUserData(string $function, array $param): ?array 
{
    try {
        // Initialize QueryBuilder
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        // Base query parameters
        $conditions = [
            'status' => 'active'
        ];

        // Add username/email condition if provided
        if (isset($param['username'])) {
            $conditions['username'] = $param['username'];
        } elseif (isset($param['email'])) {
            $conditions['email'] = $param['email'];
        }

        // Get user data
        $fields = ['id', 'uuid', 'username', 'email', 'password', 'status', 'created_at'];

        $result = $queryBuilder
        ->select('users', $fields)
        ->where($conditions)
        ->get();
        if (empty($result)) {
            return null;
        }

        $userData = $result[0];

        // Verify password if provided
        if (isset($param['password'])) {
            if (!password_verify($param['password'], $userData['password'])) {
                return null;
            }
        }

        unset($userData['password']); // Remove sensitive data

        // Get profile data using user UUID
        $profileData = $queryBuilder
        ->select('profiles', ['first_name', 'last_name', 'photo_url'])
        ->where([
            'user_uuid' => $userData['uuid'],
            'status' => 'active'
        ])
        ->get();

        $profile = !empty($profileData) ? $profileData[0] : [];

        // Format response
        return [
            'id' => $userData['id'],
            'uuid' => $userData['uuid'],
            'username' => $userData['username'],
            'email' => $userData['email'],
            // 'role' => $userData['role'] ?? 'user',
            'created_at' => $userData['created_at'],
            'last_login' => date('Y-m-d H:i:s'),
            'profile' => [
                'first_name' => $profile['first_name'] ?? null,
                'last_name' => $profile['last_name'] ?? null,
                'photo_url' => $profile['photo_url'] ?? null,
                'full_name' => trim(
                    ($profile['first_name'] ?? '') . ' ' . 
                    ($profile['last_name'] ?? '')
                ) ?: null
            ]
        ];

    } catch (\Exception $e) {
        error_log("Failed to get user data: " . $e->getMessage());
        return null;
    }
}

/**
     * Retrieve binary content
     * 
     * Handles file downloads and image processing.
     * 
     * @param string $function Resource type
     * @param string $action Retrieval action
     * @param array $param Content parameters
     * @return array Content data
     */
    public static function getBlob(string $function, string $action, array $param): array 
    {
        try {
            if (!isset($param['uuid'])) {
                return Response::error('Blob ID is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $blob = self::getBlobInfo($function, $param);
            if (empty($blob)) {
                return Response::notFound('Blob not found')->send();
            }

            $blobInfo = $blob[0];
            $storage = self::getStorageDriver();
            $fullUrl = $storage->getUrl($blobInfo['filepath']);
            
            $requestType = $param['type'] ?? 'image';

            return match($requestType) {
                'file' => Response::ok(self::getBlobAsFile($storage, $blobInfo))->send(),
                'image' => Response::ok(self::processImageBlob($fullUrl, $param))->send(),
                'download' => self::downloadBlob($storage, $blobInfo),
                default => Response::error('Invalid blob type requested')->send()
            };

        } catch (\Exception $e) {
            error_log("Blob processing error: " . $e->getMessage());
            return Response::error('Failed to process blob: ' . $e->getMessage())->send();
        }
    }

    private static function getBlobAsFile(StorageInterface $storage, array $blob): array 
    {
        return [
            'uuid' => $blob['uuid'],
            'url' => $storage->getUrl($blob['filepath']),
            'name' => $blob['filename'] ?? basename($blob['filepath']),
            'mime_type' => $blob['mime_type'],
            'size' => $blob['file_size'] ?? 0,
            'type' => self::getMimeType($blob['mime_type']),
            'created_at' => $blob['created_at'],
            'updated_at' => $blob['updated_at'],
            'status' => $blob['status']
        ];
    }

    private static function downloadBlob(StorageInterface $storage, array $blob): array 
    {
        $filename = $blob['filename'] ?? basename($blob['filepath']);
        $mime = $blob['mime_type'] ?? 'application/octet-stream';
        
        if ($blob['status'] !== 'active') {
            return Response::error('Blob is not active', Response::HTTP_FORBIDDEN)->send();
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        
        // For S3/remote storage, redirect to presigned URL
        if ($storage instanceof \Glueful\Api\Extensions\Uploader\Storage\S3Storage) {
            $url = $storage->getSignedUrl($blob['filepath'], 300); // 5 minutes expiry
            header('Location: ' . $url);
            exit;
        }
        
        // For local storage, read and output file
        $path = config('paths.uploads') . '/' . $blob['filepath'];
        if (file_exists($path)) {
            readfile($path);
            exit;
        }

        return Response::error('File not found', Response::HTTP_NOT_FOUND)->send();
    }

private static function getStorageDriver(): StorageInterface 
{
    $storageDriver = config('storage.driver');
    return match($storageDriver) {
        's3' => new \Glueful\Api\Extensions\Uploader\Storage\S3Storage(),
        default => new \Glueful\Api\Extensions\Uploader\Storage\LocalStorage(
            config('paths.uploads'),
            config('paths.cdn')
        )
    };
}

private static function getBlobInfo(string $function, array $param): array 
{
    $fields = [
        'fields' => 'uuid,filepath,filename,mime_type,file_size,created_at,updated_at,status'
    ];
    $queryParams = array_merge($param, $fields);
    
    $definition = self::loadDefinition($function);
    return self::executeQuery('list', $definition, $queryParams);
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

        $thumbnailer = new \Glueful\ImageProcessing\TimThumb($config);
        
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

        // Use storage driver for caching
        $storage = self::getStorageDriver();
        $cachedPath = 'cache/images/' . $cachedFilename;
        
        if ($storage->store($cachePath, $cachedPath)) {
            return [
                'url' => $storage->getUrl($cachedPath),
                'cached' => true,
                'dimensions' => [
                    'width' => $config['width'] ?? imagesx(imagecreatefromstring($imageData)),
                    'height' => $config['height'] ?? imagesy(imagecreatefromstring($imageData))
                ],
                'size' => strlen($imageData)
            ];
        }

        // Fallback to original URL if caching fails
        return [
            'url' => $src,
            'cached' => false,
            'error' => null
        ];

    } catch (\Exception $e) {
        return [
            'url' => $src,
            'cached' => false,
            'error' => 'Failed to process image'
        ];
    }
}

    /**
     * Process database operations using new QueryBuilder
     */
    private static function processData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        $definition = self::loadDefinition($function);
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        try {
            // Handle pagination configuration
            $paginationEnabled = config('pagination.enabled', true);
            $usePagination = $param['paginate'] ?? $paginationEnabled;
            $page = max(1, (int)($param['page'] ?? 1));
            $perPage = $usePagination ? min(
                config('pagination.max_size', 100),
                max(1, (int)($param['per_page'] ?? config('pagination.default_size', 25)))
            ) : null;

            // Handle sorting
            $sort = $param['sort'] ?? 'created_at';
            $order = strtolower($param['order'] ?? 'desc');
            $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';

            // Process filters
            if ($filter) {
                $conditions = [];
                foreach ($filter as $field => $value) {
                    if (is_array($value)) {
                        // Complex conditions handled by QueryBuilder's where methods
                        foreach ($value as $operator => $val) {
                            $conditions[$field] = [$operator => $val];
                        }
                    } else {
                        $conditions[$field] = $value;
                    }
                }
                $param['conditions'] = $conditions;
            }

            // Handle paginated list actions
            if ($action === 'list' && $usePagination) {
                return $queryBuilder->select(
                    $definition['table']['name'],
                    explode(',', $param['fields'] ?? '*')
                )
                ->where($param['conditions'] ?? [])
                ->orderBy($param['orderBy'] ?? [])
                ->paginate($page, $perPage);
            }

            // Handle other actions
            $result = self::executeQuery(
                $action, 
                $definition, 
                $param
            );


            return $result;

        } catch (\Exception $e) {
            throw new \RuntimeException("Data processing failed: " . $e->getMessage());
        }
    }

    /**
     * Execute database query using QueryBuilder's upsert functionality
     */
    private static function executeQuery(string $action, array $definition, array $params): array 
{
    try {
        $queryBuilder = new QueryBuilder(self::$db, self::$driver);
        
        $result = match($action) {
            'list', 'view' => $queryBuilder
                ->select(
                    $definition['table']['name'],
                    explode(',', $params['fields'] ?? '*')
                )
                ->where($params['where'] ?? [])
                ->orderBy($params['orderBy'] ?? [])
                ->limit($params['limit'] ?? null)
                ->get(),
                
            'count' => [['total' => $queryBuilder
                ->count($definition['table']['name'], $params['where'] ?? [])]],
                
            'insert' => [
                'uuid' => $queryBuilder->insert($definition['table']['name'], $params) ? 
                    self::getLastInsertedUUID(self::$db, $definition['table']['name']) : 
                    null
            ],
            
            'update' => [
                'affected' => $queryBuilder->upsert(
                    $definition['table']['name'],
                    [array_merge(
                        ['uuid' => $params['uuid']],
                        $params['data'] ?? []
                    )],
                    array_keys($params['data'] ?? [])
                )
            ],
            
            'delete' => [
                'affected' => $queryBuilder
                    ->delete(
                        $definition['table']['name'],
                        $params['where'] ?? [],
                        true
                    ) ? 1 : 0
            ],
            
            default => []
        };
        
        return $result;
    } catch (\Exception $e) {
        throw new \RuntimeException("Query execution failed: " . $e->getMessage());
    }
}

    /**
     * Get last inserted UUID from database
     */
    private static function getLastInsertedUUID(?\PDO $db, string $table): string 
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        try {
            return $queryBuilder->lastInsertId($table, 'uuid');
        } catch (\Exception $e) {
            error_log("Failed to get last inserted UUID: " . $e->getMessage());
            throw new \RuntimeException("Failed to retrieve UUID for new record: " . $e->getMessage());
        }
    }

    private static function getUserPermissions(string $userUUID): array 
    {
        $databaseResource = config('database.json_prefix');
        $currentResource = $databaseResource;
        
        // Get user roles using JSON definition and UUID
        $param = [
            'fields' => 'user_uuid,role_id', // Changed from user_id to user_uuid
            'user_uuid' => $userUUID 
        ];

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
        $databaseResource = $currentResource;

        return $formattedPermissions;
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

    protected static function getDefinition(string $function): ?array
    {
        return self::loadDefinition($function);
    }


    private static function loadDefinition(string $function): array 
    {
        $resource = self::$currentResource;
        $path = config('paths.json_definitions') . $resource . '.' . $function . '.json';
        
        if (!file_exists($path)) {
            throw new \RuntimeException(
                "The definition $resource.$function.json does not exist",
                Response::HTTP_NOT_FOUND
            );
        }

        $definition = json_decode(file_get_contents($path), true);
        if (!$definition) {
            throw new \RuntimeException("Invalid JSON definition for $function");
        }

        return $definition;
    }

}
APIEngine::initialize();