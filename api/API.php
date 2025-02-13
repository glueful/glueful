<?php
declare(strict_types=1);

namespace Mapi\Api;

use Mapi\Api\Library\{
    Permissions,
    Permission,
    Utils,
    APIEngine,
    IExtensions,
    Logger
};
use Mapi\Api\Http\{
    Response,
    Router
};
use Mapi\Api\Extensions\Uploader\FileUploader;

/**
 * @author Xose & Edem Ahlijah
 * @version 4.0
 **/
session_start();

// require_once("_config.php");

// Load core library classes
spl_autoload_register(function ($class) {
    $prefix = 'Mapi\\Api\\Library\\';
    $baseDir = config('paths.api_library');
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

class API 
{
    private static function initializeRoutes(): void 
    {
        // Replace direct boolean with string constant name
        // if (!defined('REST_MODE')) {
        //     define('REST_MODE', true);
        // }

        // Initialize logger
        $logFile = config('app.api_log_file');
        $debugLogging = config('app.debug_logging');
        Logger::init(defined('API_DEBUG_LOGGING') ? $debugLogging : false, 
                    defined('API_LOG_FILE') ? $logFile : null);
        
        $router = Router::getInstance();
        
        // Public routes - using relative paths
        $router->addRoute('POST', 'auth/login', function($params) {
            Logger::log('REST Request - Login', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/login',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $postData = [];
            
            if (strpos($contentType, 'application/json') !== false) {
                $input = file_get_contents('php://input');
                $postData = json_decode($input, true) ?? [];
            } else {
                $postData = $_POST;
            }
            
            self::prepareDatabaseResource(null);
            $response = self::handleLogin('sessions', $postData);
            Logger::log('REST Response - Login', ['response' => $response]);
            return $response;
        }, true); // Mark as public

        // Protected routes (require token)
        $router->addRoute('GET', '{resource}', function($params) {
            Logger::log('REST Request - List', [
                'method' => 'GET',
                'resource' => $params['resource'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken(); // Add token validation
            $response = self::handleRESTRequest('list', $params['resource'], $_GET);
            Logger::log('REST Response - List', ['response' => $response]);
            return $response;
        });
        
        $router->addRoute('GET', '{resource}/{id}', function($params) {
            Logger::log('REST Request - List by ID', [
                'method' => 'GET',
                'resource' => $params['resource'],
                'id' => $params['id'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            $_GET['id'] = $params['id'];
            $response = self::handleRESTRequest('list', $params['resource'], $_GET);
            Logger::log('REST Response - List by ID', ['response' => $response]);
            return $response;
        });
        
        $router->addRoute('POST', '{resource}', function($params) {
            Logger::log('REST Request - Save', [
                'method' => 'POST',
                'resource' => $params['resource'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            $response = self::handleRESTRequest('save', $params['resource'], $_GET, $_POST);
            Logger::log('REST Response - Save', ['response' => $response]);
            return $response;
        });
        
        $router->addRoute('PUT', '{resource}/{id}', function($params) {
            Logger::log('REST Request - Replace', [
                'method' => 'PUT',
                'resource' => $params['resource'],
                'id' => $params['id'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            $_GET['id'] = $params['id'];
            $response = self::handleRESTRequest('replace', $params['resource'], $_GET, $_POST);
            Logger::log('REST Response - Replace', ['response' => $response]);
            return $response;
        });
        
        $router->addRoute('DELETE', '{resource}/{id}', function($params) {
            Logger::log('REST Request - Delete', [
                'method' => 'DELETE',
                'resource' => $params['resource'],
                'id' => $params['id'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            $_GET['id'] = $params['id'];
            $response = self::handleRESTRequest('delete', $params['resource'], $_GET);
            Logger::log('REST Response - Delete', ['response' => $response]);
            return $response;
        });
        
        $router->addRoute('POST', 'auth/logout', function($params) {
            Logger::log('REST Request - Logout', [
                'method' => 'POST',
                'path' => 'auth/logout',
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            $response = self::handleRESTRequest('logout', 'sessions', $_GET);
            Logger::log('REST Response - Logout', ['response' => $response]);
            return $response;
        });
    }

    private static function validateToken(): void 
    {
        $headers = getallheaders();
        $token = null;
        
        // Check Authorization header
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
            }
        }
        
        // Check query parameter if no header
        if (!$token && isset($_GET['token'])) {
            $token = $_GET['token'];
        }
        
        if (!$token) {
            echo json_encode(Response::unauthorized('Authentication required')->send());
            exit;
        }
        
        $_GET['token'] = $token; // Store token for downstream use
        
        // Validate token
        try {
            $result = APIEngine::validateSession('sessions', 'validate', ['token' => $token]);
            if (!isset($result['success']) || !$result['success']) {
                echo json_encode(Response::unauthorized('Invalid or expired token')->send());
                exit;
            }
        } catch (\Exception $e) {
            echo json_encode(Response::error('Token validation failed', Response::HTTP_INTERNAL_SERVER_ERROR)->send());
            exit;
        }
    }

    private static function handleRESTRequest(
        string $action, 
        string $resource, 
        array $getParams, 
        array $postParams = []
    ): array {
        if ($resource === 'sessions') {
            return self::handleSession($resource, $action, $getParams, $postParams);
        }
        
        $getParams['f'] = "$resource:$action";
        return self::processRequest($getParams, $postParams);
    }

    private static function prepareDatabaseResource(?string $dbres): void 
    {

        
        $databaseResource = config('database.primary');
        Utils::createPDOConnection($databaseResource);
    }

    public static function processRequest(array $getParams, array $postParams = [], array $fileParams = []): array 
    {
        // Set JSON response headers
        header('Content-Type: application/json');
        
        // Initialize routes
        self::initializeRoutes();

        try {
            // Check for legacy API pattern first (?f=resource:action)
            if (isset($getParams['f'])) {
                self::prepareDatabaseResource($getParams['dbres'] ?? null);
                $response = self::handleLegacyRequest($getParams, $postParams, $fileParams);
                // Keep legacy response logging since it doesn't have route handlers
                Logger::log('API Response', ['response' => $response]);
                return $response;
            }

            // If no legacy pattern found, try REST routes
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/api/') !== false) {
                $path = preg_replace('#^.*/api/#', '', $requestUri);
                $path = strtok($path, '?'); // Remove query parameters
                
                $router = Router::getInstance();
                $match = $router->match($_SERVER['REQUEST_METHOD'], $path);
                
                if ($match) {
                    $match['params'] = array_merge($match['params'], $getParams);
                    self::prepareDatabaseResource($getParams['dbres'] ?? null);
                    $response = ($match['handler'])($match['params']);
                    
                    // Remove duplicate response logging here since routes handle it
                    if (!headers_sent()) {
                        echo json_encode($response);
                        exit;
                    }
                    return $response;
                }
            }

            // Neither legacy nor REST route found
            $response = Response::error('Invalid request format. Use either ?f=resource:action or REST endpoint', Response::HTTP_BAD_REQUEST)->send();
            Logger::log('API Error Response', ['response' => $response]);
            return $response;
            
        } catch (\Exception $e) {
            $response = Response::error('Internal server error: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            Logger::log('API Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'response' => $response
            ]);
            return $response;
        }
    }

    private static function handleLegacyRequest(array $getParams, array $postParams, array $fileParams): array 
    {
        self::prepareDatabaseResource($getParams['dbres'] ?? null);

        if (!isset($getParams['f'])) {
            return Response::error('No function specified', Response::HTTP_BAD_REQUEST)->send();
        }

        [$function, $action] = self::parseFunction($getParams['f']);
        if (!$function || !$action) {
            return Response::error('Invalid API format', Response::HTTP_BAD_REQUEST)->send();
        }

        $model = "api.{$GLOBALS['databaseResource']}.$function";

        return match($function) {
            'sessions' => self::handleSession($function, $action, $getParams, $postParams),
            'blobs' => self::handleBlob($function, $action, $getParams, $postParams, $fileParams),
            default => self::handleStandardRequest($function, $action, $model, $getParams, $postParams)
        };
    }

    private static function parseFunction(string $functionString): array 
    {
        $parts = explode(':', $functionString);
        if (count($parts) !== 2) {
            return [null, null];
        }

        $db = Utils::getMySQLConnection($GLOBALS['databaseResource']);
        return [
            // Use PDO quote instead of mysqli_real_escape_string
            trim($db->quote($parts[0]), "'"),
            strtolower(trim($db->quote($parts[1]), "'"))
        ];
    }

    private static function handleSession(string $function, string $action, array $getParams, array $postParams): array 
    {
        return match($action) {
            'validate' => APIEngine::validateSession($function, $action, $getParams),
            'login' => self::handleLogin($function, $postParams),
            'logout' => APIEngine::killSession($getParams),
            default => Response::error('Unknown API', Response::HTTP_NOT_FOUND)->send()
        };
    }

    private static function validateLoginCredentials(string $function, array $params): bool 
    {
        return match($function) {
            'sessions' => (isset($params['username']) || isset($params['email'])) && isset($params['password']),
            default => false
        };
    }

    private static function handleLogin(string $function, array $postParams): array 
    {
        if (!self::validateLoginCredentials($function, $postParams)) {
            return Response::error('Username/Email and Password Required', Response::HTTP_BAD_REQUEST)->send();
        }

        try {
            // Handle email login by setting username if email is provided
            if ($function === 'sessions' && !isset($postParams['username']) && isset($postParams['email'])) {
                $postParams['username'] = $postParams['email'];
            }

            $postParams['status'] = config('app.active_status');
            $result = APIEngine::createSession($function, 'login', $postParams);
            
            // Convert API response to proper HTTP response
            if (isset($result['success']) && !$result['success']) {
                return Response::error(
                    $result['message'] ?? 'Login failed', 
                    $result['code'] ?? Response::HTTP_BAD_REQUEST
                )->send();
            }
            
            return Response::ok($result)->send();
            
        } catch (\Exception $e) {
            return Response::error(
                'Login failed: ' . $e->getMessage(), 
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    private static function handleStandardRequest(
        string $function, 
        string $action, 
        string $model, 
        array $getParams, 
        array $postParams
    ): array {
        if (!isset($getParams['token'])) {
            return Response::unauthorized('Authentication required')->send();
        }

        return match($action) {
            'count', 'list', 'sum' => self::handleReadOperation($function, $action, $model, $getParams),
            'save', 'replace' => self::handleWriteOperation($function, $action, $model, $getParams, $postParams),
            'delete' => self::handleDeleteOperation($function, $model, $getParams),
            default => self::handleExtension($function, $action, $model, $getParams, $postParams)
        };
    }

    private static function handleReadOperation(string $function, string $action, string $model, array $params): array 
    {
        if (!Permissions::hasPermission($model, Permission::VIEW, $params['token'])) {
            return Response::error(
                "Permission denied for $function:$action", 
                Response::HTTP_FORBIDDEN
            )->send();
        }

        $filter = isset($params['filter']) ? explode(':', $params['filter']) : null;
        return APIEngine::getData($function, $action, $params, $filter);
    }

    private static function handleWriteOperation(
        string $function, 
        string $action, 
        string $model, 
        array $getParams, 
        array $postParams
    ): array {
        if (!Permissions::hasPermission($model, Permission::SAVE, $getParams['token'])) {
            return Response::error(
                "Permission denied for $function:$action", 
                Response::HTTP_FORBIDDEN
            )->send();
        }

        if ($function === 'blobs') {
            return self::handleBlobUpload($getParams, $postParams);
        }

        $response = APIEngine::saveData($function, $action, $postParams);
        
        if (config('app.enable_audit') && config('app.enable_audit') === TRUE) {
            self::auditChanges($function, $getParams, $postParams, $response);
        }

        return Response::ok($response)->send();
    }

    private static function handleDeleteOperation(string $function, string $model, array $getParams): array 
    {
        if (!Permissions::hasPermission($model, Permission::DELETE, $getParams['token'])) {
            return Response::error(
                "Permission denied for $function:delete", 
                Response::HTTP_FORBIDDEN
            )->send();
        }

        $deleteParams = ['id' => $getParams['id'], 'status' => 'D'];

        return APIEngine::saveData($function, 'delete', $deleteParams);
    }

    private static function handleBlob(
        string $function, 
        string $action, 
        array $getParams, 
        array $postParams,
        array $fileParams
    ): array {
        if (!isset($getParams['token'])) {
            return Response::unauthorized('Authentication required')->send();
        }

        $model = "api.{$GLOBALS['databaseResource']}.$function";
        if (!Permissions::hasPermission($model, Permission::SAVE, $getParams['token'])) {
            return Response::error(
                "Permission denied for $function:$action", 
                Response::HTTP_FORBIDDEN
            )->send();
        }

        if ($action === 'retrieve') {
            return APIEngine::getBlob($function, $action, $getParams);
        }

        // Handle base64 image uploads
        if (isset($getParams['type']) && $getParams['type'] === 'base64') {
            return self::handleBase64Upload($getParams, $postParams);
        }

        // Handle regular file uploads
        return self::handleFileUpload($getParams, $fileParams);
    }

    private static function handleBase64Upload(array $getParams, array $postParams): array 
    {
        try {
            $uploader = new FileUploader();
            
            // Convert base64 to temp file
            $tmpFile = $uploader->handleBase64Upload($postParams['base64']);
            
            $fileParams = [
                'name' => $getParams['name'] ?? 'upload.jpg',
                'type' => $getParams['mime_type'] ?? 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => filesize($tmpFile)
            ];

            return $uploader->handleUpload(
                $getParams['token'],
                $getParams,
                ['file' => $fileParams]
            );

        } catch (\Exception $e) {
            return Response::error('Base64 upload failed: ' . $e->getMessage())->send();
        }
    }

    private static function handleFileUpload(array $getParams, array $fileParams): array 
    {
        try {
            $uploader = new FileUploader();
            return $uploader->handleUpload($getParams['token'], $getParams, $fileParams);
        } catch (\Exception $e) {
            return Response::error('File upload failed: ' . $e->getMessage())->send();
        }
    }

    private static function handleBlobUpload(array $getParams, array $postParams): array 
    {
        try {
            if (!isset($postParams['base64'])) {
                throw new \InvalidArgumentException('Invalid blob upload parameters');
            }

            $uploader = new FileUploader();
            
            // Convert base64 to temp file
            $tmpFile = $uploader->handleBase64Upload($postParams['base64']);
            
            $fileParams = [
                'name' => $getParams['name'] ?? 'b64conv.jpg',
                'type' => $getParams['mime_type'] ?? 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => 0,
                'size' => filesize($tmpFile)
            ];

            unset($getParams['type']);
            return $uploader->handleUpload($getParams['token'], $getParams, ['file' => $fileParams]);

        } catch (\Exception $e) {
            return Response::error('Blob upload failed: ' . $e->getMessage())->send();
        }
    }

    private static function auditChanges(
        string $function,
        array $getParams,
        array $postParams,
        array $response
    ): void {
        if ($function === 'sysaudit') {
            return;
        }

        $sessionData = APIEngine::validateSession(null, null, $getParams);
        $auditGet = [
            'fields' => implode(',', array_keys($postParams)),
            'id' => $postParams['id'] ?? null,
            'token' => $getParams['token'],
            'dbres' => $getParams['dbres']
        ];

        $existingData = APIEngine::getData($function, 'list', $auditGet, null);
        $changeset = self::detectChanges($existingData[0] ?? [], $postParams);

        if (empty($changeset)) {
            return;
        }

        self::recordAudit($function, $changeset, $sessionData, $response['id']);
    }

    private static function detectChanges(array $oldData, array $newData): array 
    {
        $changes = [];
        foreach ($newData as $key => $newValue) {
            $oldValue = $oldData[$key] ?? '';
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ];
            }
        }
        return $changes;
    }

    private static function recordAudit(
        string $entity,
        array $changeset,
        array $sessionData,
        string|int $recordId
    ): void {
        $auditPost = [
            'changeset' => json_encode($changeset),
            'session_user_id' => $sessionData['info'][0]['id'],
            'session_fullname' => sprintf(
                '%s %s',
                $sessionData['info'][0]['first_name'],
                $sessionData['info'][0]['last_name']
            ),
            'entity' => $entity,
            'record_id' => $recordId,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ];

        $currentDb = $GLOBALS['databaseResource'];
        self::prepareDatabaseResource('audit');
        APIEngine::saveData('sysaudit', 'save', $auditPost);
        self::prepareDatabaseResource($currentDb);
    }

    private static function handleExtension(
        string $function, 
        string $action, 
        string $model, 
        array $getParams, 
        array $postParams
    ): array {
        if (!Permissions::hasPermission($model, Permission::VIEW, $getParams['token'])) {
            return Response::error(
                "Permission denied for $function:$action", 
                Response::HTTP_FORBIDDEN
            )->send();
        }

        $extensionPath = self::resolveExtensionPath($action, $function);
        if (!$extensionPath) {
            return Response::error(
                "Unknown extension $function:$action", 
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        require_once $extensionPath;
        if (!in_array(IExtensions::class, class_implements($function))) {
            return Response::error(
                "Extension must implement IExtensions", 
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        $response = $function::process($getParams, $postParams);
        return is_array($response) ? 
            Response::ok($response)->send() : 
            Response::error(
                'Extension did not return a valid response', 
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
    }

    private static function resolveExtensionPath(string $action, string $function): ?string 
    {
        $paths = [
            config('paths.project_extensions') . "$action/$function.php", // Project extensions
            config('paths.api_extensions') . "$action/$function.php" // Core extensions
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
?>
