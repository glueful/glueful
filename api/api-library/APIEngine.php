<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\{QueryAction, Utils, JWTService, SessionManager};
use Glueful\Api\Http\Response;
use Glueful\Api\Extensions\Uploader\Storage\StorageInterface;
use Glueful\Api\Library\Logging\LogManager;
use Monolog\Level;

/**
 * API Engine Core
 * 
 * Handles core API functionality including data operations, authentication,
 * session management, file handling, and audit logging.
 */
class APIEngine 
{
    /** @var string|null Query builder class name */
    private static ?string $queryBuilderClass = null;
    
    /** @var string Current database resource */
    private static string $currentResource;

    /** @var LogManager Logger instance */
    private static ?LogManager $logger = null;

    /**
     * Initialize API Engine
     * 
     * Sets up query builder and database connection.
     * 
     * @param string $queryBuilderClass Class name for query building
     */
    public static function initialize(string $queryBuilderClass): void 
    {
        self::$queryBuilderClass = $queryBuilderClass;
        // Initialize with first available database resource
        $dbConfig = config('database');
        self::$currentResource = array_key_first(array_filter($dbConfig, 'is_array'));
        
        // Initialize logger
        self::$logger = new LogManager();
    }

    /**
     * Set active database resource
     * 
     * @param string $resource Database resource identifier
     */
    public static function setDatabaseResource(string $resource): void
    {
        self::$currentResource = $resource;
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
            self::$logger?->log(
                "Session validation failed - No token provided",
                ['ip' => $_SERVER['REMOTE_ADDR']],
                Level::Warning,
                'auth'
            );
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
            self::$logger?->log(
                "Session validation failed - Invalid token",
                [
                    'token' => substr($token, 0, 8) . '...',
                    'ip' => $_SERVER['REMOTE_ADDR']
                ],
                Level::Warning,
                'auth'
            );
            return Response::unauthorized('Invalid or expired session')->send();
        }

        // Rest of validation logic
        if (!self::validateSecurityLevel($session)) {
            return Response::error('Session security check failed', Response::HTTP_FORBIDDEN)->send();
        }

        if ($function) {
            $prefix = str_contains($function, '.') ? 'api.ext.' : 'api.' . self::$currentResource . '.';
            $model = $prefix . $function;

            if (!Permissions::hasPermission($model, Permission::VIEW, $token)) {
                return Response::error('Permission denied', Response::HTTP_FORBIDDEN)->send();
            }
        }

        // Log successful validation
        self::$logger?->log(
            "Session validated successfully",
            [
                'user_uuid' => $session['uuid'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR']
            ],
            Level::Info,
            'auth'
        );

        return Response::ok($session)->send();
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

    private static function getUserPermissions(string $userUUID): array 
    {
        $databaseResource = config('database.primary');
        $currentResource = $databaseResource;
        
        // Use PDO connection instead of mysqli
        $db = Utils::getMySQLConnection($databaseResource);

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

    /**
     * Process database operations
     * 
     * Core method for handling all database interactions.
     * 
     * @param string $function Resource name
     * @param string $action Operation type
     * @param array $param Operation parameters
     * @param array|null $filter Optional filters
     * @return array Operation result
     * @throws \RuntimeException On database errors
     */
    private static function processData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        $definition = self::loadDefinition($function);
        
        // Get pagination configuration
        $paginationEnabled = config('pagination.enabled', true);
        $defaultSize = config('pagination.default_size', 25);
        $maxSize = config('pagination.max_size', 100);
        
        // Extract pagination and sorting parameters
        $usePagination = $param['paginate'] ?? $paginationEnabled;
        $page = max(1, (int)($param['page'] ?? 1));
        $perPage = $usePagination ? min($maxSize, max(1, (int)($param['per_page'] ?? $defaultSize))) : null;
        $sort = $param['sort'] ?? 'created_at';
        $order = strtolower($param['order'] ?? 'desc');
        $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';
        
        // Handle filters
        if ($filter) {
            $formattedFilters = [];
            foreach ($filter as $field => $conditions) {
                if (!is_array($conditions)) {
                    $formattedFilters[] = [
                        'field' => $field,
                        'operator' => 'eq',
                        'value' => $conditions
                    ];
                    continue;
                }
                
                foreach ($conditions as $operator => $value) {
                    $formattedFilters[] = [
                        'field' => $field,
                        'operator' => $operator,
                        'value' => $value
                    ];
                }
            }
            $param['_filter'] = $formattedFilters;
        }

        // Handle list actions with pagination
        if ($action === 'list' && $usePagination) {
            // Get total count first
            $countParams = array_merge($param, ['fields' => 'COUNT(*) as total']);
            unset($countParams['page'], $countParams['per_page'], $countParams['sort'], $countParams['order']);
            
            $totalResult = self::executeQuery('count', $definition, self::sanitizeParams($countParams));
            $totalRecords = (int)($totalResult[0]['total'] ?? 0);

            // Add pagination and sorting to params
            $param['_limit'] = $perPage;
            $param['_offset'] = ($page - 1) * $perPage;
            $param['_sort'] = $sort;
            $param['_order'] = $order;

            // Get paginated results
            $results = self::executeQuery(
                $action, 
                $definition, 
                self::sanitizeParams($param)
            );

            // Return with pagination metadata
            return [
                'data' => $results,
                'pagination' => [
                    'total' => $totalRecords,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($totalRecords / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $totalRecords),
                    'has_more' => ($page * $perPage) < $totalRecords
                ],
                'sort' => [
                    'field' => $sort,
                    'order' => $order
                ]
            ];
        }

        // For non-paginated list actions or other actions
        $result = self::executeQuery(
            $action, 
            $definition, 
            self::sanitizeParams($param)
        );

        // Audit changes for write operations
        if (in_array($action, ['insert', 'update', 'delete']) && isset($definition['table'])) {
            $recordUuid = match($action) {
                'insert' => $result['uuid'] ?? null,
                'update', 'delete' => $param['uuid'] ?? null
            };

            self::auditChanges(
                $action, 
                $definition['table']['name'],
                $recordUuid,
                ['params' => $param, 'result' => $result]
            );
        }

        return $result;
    }

    private static function executeQuery(string $action, array $definition, array $params): array 
    {
        $startTime = microtime(true);
        
        try {
            if (!self::$queryBuilderClass) {
                throw new \RuntimeException("Query builder not initialized");
            }

            $builder = self::$queryBuilderClass;
            $query = $builder::prepare(QueryAction::fromString($action), $definition, $params);

            // Log query before execution
            self::$logger?->log(
                "Executing query",
                [
                    'action' => $action,
                    'table' => $definition['table']['name'] ?? 'unknown',
                    'query' => $query['sql'],
                    'params' => $query['params'] ?? []
                ],
                Level::Debug,
                'database'
            );

            // Get PDO connection
            $databaseResource = config('database.primary');
            $db = Utils::getMySQLConnection($databaseResource);

            // Prepare the statement
            $stmt = $db->prepare($query['sql']);
            
            // Bind parameters
            if (isset($query['params'])) {
                foreach ($query['params'] as $param => $value) {
                    $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                    $stmt->bindValue($param, $value, $type);
                }
            }

            // Execute the query
            $stmt->execute();

            // Return results based on query type
            $result = match($action) {
                'list', 'view' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
                'count' => $stmt->fetchAll(\PDO::FETCH_ASSOC), // Return count as array
                'insert' => ['uuid' => self::getLastInsertedUUID($db, $definition['table']['name'])],
                'update', 'delete' => ['affected' => $stmt->rowCount()],
                default => []
            };

            $endTime = microtime(true);
            $execTime = round($endTime - $startTime, 6);

            // Log successful query execution
            self::$logger?->log(
                "Query executed successfully",
                [
                    'action' => $action,
                    'table' => $definition['table']['name'] ?? 'unknown',
                    'exec_time' => $execTime,
                    'affected_rows' => $stmt->rowCount()
                ],
                Level::Debug,
                'database'
            );

            return $result;

        } catch (\PDOException $e) {
            $endTime = microtime(true);
            $execTime = round($endTime - $startTime, 6);

            // Log query error
            self::$logger?->log(
                "Query execution failed",
                [
                    'action' => $action,
                    'table' => $definition['table']['name'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'exec_time' => $execTime,
                    'query' => $query['sql'] ?? null,
                    'params' => $query['params'] ?? []
                ],
                Level::Error,
                'database'
            );

            throw new \RuntimeException("Query execution failed: " . $e->getMessage());
        }
    }

    private static function getLastInsertedUUID(?\PDO $db, string $table): string 
    {
        $stmt = $db->query("SELECT uuid FROM `$table` WHERE id = LAST_INSERT_ID()");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$result || !isset($result['uuid'])) {
            throw new \RuntimeException("Failed to retrieve UUID for new record");
        }
        return $result['uuid'];
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

            // Include uuid in fields if it exists
            $fields = ['id', 'uuid', 'username', 'email', 'password', 'role', 'status', 'created_at'];
            $param['fields'] = implode(',', $fields);

            // Load users definition and execute query
            $definition = self::getDefinition('users');
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

            // Get profile data
            $profileResult = self::executeQuery(
                'list',
                self::getDefinition('profiles'),
                [
                    'fields' => 'first_name,last_name,photo_url',
                    'user_uuid' => $userData['uuid'],
                    'status' => 'active'
                ]
            );

            // Merge profile data if exists
            $profile = !empty($profileResult) ? $profileResult[0] : null;
            
            return [
                'id' => $userData['id'],
                'uuid' => $userData['uuid'] ?? null,
                'username' => $userData['username'] ?? null,
                'email' => $userData['email'] ?? null,
                'role' => $userData['role'] ?? 'user',
                'created_at' => $userData['created_at'] ?? null,
                'last_login' => date('Y-m-d H:i:s'),
                'profile' => [
                    'first_name' => $profile['first_name'] ?? null,
                    'last_name' => $profile['last_name'] ?? null,
                    'photo_url' => $profile['photo_url'] ?? null,
                    'full_name' => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: null
                ]
            ];
        } catch (\Exception $e) {
            error_log("Failed to get user data: " . $e->getMessage());
            return null;
        }
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

    private static function sanitizeParams(array $params): array 
    {
        return array_map(
            fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            $params
        );
    }
    
    private static function validateSecurityLevel(array $sessionData): bool 
    {
        $securityLevels = config('security.levels');
        
        return match($sessionData['type']) {
            $securityLevels['flexible'] => true,
            $securityLevels['moderate'] => $sessionData['ip'] === $_SERVER['REMOTE_ADDR'],
            $securityLevels['strict'] => $sessionData['ip'] === $_SERVER['REMOTE_ADDR'] 
                && $sessionData['user_agent'] === ($_SERVER['HTTP_USER_AGENT'] ?? 'Unspecified'),
            default => false
        };
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

    public static function auditChanges(string $action, string $table, ?string $recordUuid, array $changes): bool 
    {
        if (!config('app.enable_audit', false)) {
            return true; // Skip auditing if disabled
        }
        
        $session = SessionManager::getCurrentSession();
    }

    public static function getAuditTrail(string $function, string $action, array $param): array 
    {
        try {
            if (!isset($param['uuid'])) {
                return Response::error('UUID is required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Check if this is a table audit or specific record audit
            $tableAudit = $param['type'] ?? '' === 'table';
            $audits = $tableAudit 
                ? AuditLogger::getAuditTrail($param['table'], $param['uuid'])
                : AuditLogger::getAuditTrail($param['uuid']);

            if (empty($audits)) {
                return Response::ok(['audits' => []])->send();
            }

            return Response::ok(['audits' => $audits])->send();

        } catch (\Exception $e) {
            error_log("Audit trail error: " . $e->getMessage());
            return Response::error('Failed to retrieve audit trail')->send();
        }
    }
}

// Initialize with appropriate query builder class
/**
 * Initialize API Engine with appropriate query builder
 */
$queryBuilderClass = match(config('database.engine')) {
    'sqlite' => SQLiteQueryBuilder::class,
    'mysql' => MySQLQueryBuilder::class,
    default => MySQLQueryBuilder::class
};
APIEngine::initialize($queryBuilderClass);

// Initialize AuditLogger if auditing is enabled
if (config('app.enable_audit', false)) {
    AuditLogger::initialize($queryBuilderClass);
}
