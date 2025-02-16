<?php
declare(strict_types=1);

namespace Mapi\Api;

use Mapi\Api\Library\{
    Permissions,
    Permission,
    Utils,
    APIEngine,
    Logger,
    TokenManager,
    Security\EmailVerification
};
use Mapi\Api\Http\{
    Response,
    Router
};
use Mapi\Api\Extensions\Uploader\FileUploader;

session_start();

class API 
{
    private static bool $routesInitialized = false;

    public static function init(): void
    {
        if (!self::$routesInitialized) {
             // Initialize logger first
        $logFile = config('app.api_log_file');
        $debugLogging = config('app.debug_logging');
        Logger::init(
            defined('API_DEBUG_LOGGING') ? $debugLogging : false, 
            defined('API_LOG_FILE') ? $logFile : null
        );
        
        // Load extensions before main routes
        // This allows extensions to register their routes first
        self::loadExtensions();
            self::initializeRoutes();
            self::$routesInitialized = true;
        }
    }

    private static function initializeRoutes(): void 
    {

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

        $router->addRoute('POST', 'auth/verify-email', function($params) {
            Logger::log('REST Request - Verify Email', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/verify-email',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $postData = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $input = file_get_contents('php://input');
                    $postData = json_decode($input, true) ?? [];
                } else {
                    $postData = $_POST;
                }

                if (!isset($postData['email'])) {
                    return Response::error('Email address is required', Response::HTTP_BAD_REQUEST)->send();
                }

                $verifier = new EmailVerification();
                $otp = $verifier->generateOTP();
                
                // Send verification email
                $result = $verifier->sendVerificationEmail($postData['email'], $otp);
                
                if (!$result) {
                    return Response::error('Failed to send verification email', Response::HTTP_BAD_REQUEST)->send();
                }

                return Response::ok([
                    'message' => 'Verification code has been sent to your email',
                    'success' => true,
                    'email' => $postData['email'],
                    'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
                ])->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to send verification email: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/verify-otp', function($params) {
            Logger::log('REST Request - Validate OTP', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/verify-otp',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $postData = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $input = file_get_contents('php://input');
                    $postData = json_decode($input, true) ?? [];
                } else {
                    $postData = $_POST;
                }

                if (!isset($postData['email']) || !isset($postData['otp'])) {
                    return Response::error('Email and OTP are required', Response::HTTP_BAD_REQUEST)->send();
                }

                $verifier = new EmailVerification();
                $isValid = $verifier->verifyOTP($postData['email'], $postData['otp']);
                
                if (!$isValid) {
                    return Response::error('Invalid or expired OTP', Response::HTTP_BAD_REQUEST)->send();
                }

                return Response::ok([
                    'success' => true,
                    'message' => 'OTP verified successfully',
                    'email' => $postData['email']
                ])->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to verify OTP: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/forgot-password', function($params) {
            Logger::log('REST Request - Forgot Password', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/forgot-password',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $postData = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $input = file_get_contents('php://input');
                    $postData = json_decode($input, true) ?? [];
                } else {
                    $postData = $_POST;
                }

                if (!isset($postData['email'])) {
                    return Response::error('Email address is required', Response::HTTP_BAD_REQUEST)->send();
                }

                $email = $postData['email'];
                $result = EmailVerification::sendPasswordResetEmail($email);
                
                if (!$result['success']) {
                    return Response::error($result['message'] ?? 'Failed to send reset email', Response::HTTP_BAD_REQUEST)->send();
                }

                return Response::ok([
                    'message' => 'Password reset instructions have been sent to your email',
                    'success' => true
                ])->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to process password reset request: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/reset-password', function($params) {
            Logger::log('REST Request - Reset Password', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/reset-password',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $postData = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $input = file_get_contents('php://input');
                    $postData = json_decode($input, true) ?? [];
                } else {
                    $postData = $_POST;
                }

                if (!isset($postData['email']) || !isset($postData['new_password'])) {
                    return Response::error('Email, new password are required', Response::HTTP_BAD_REQUEST)->send();
                }

                // Update password
                $updateParams = [
                    'password' => password_hash($postData['new_password'], PASSWORD_DEFAULT),
                    'email' => $postData['email']
                ];

                $result = APIEngine::saveData('users', 'update', $updateParams);
                
                if (!isset($result['affected']) || $result['affected'] === 0) {
                    throw new \RuntimeException('Failed to update password');
                }

                return Response::ok([
                    'success' => true,
                    'message' => 'Password has been reset successfully'
                ])->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to reset password: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/validate-token', function($params) {
            Logger::log('REST Request - Validate Session', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/validate-token',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                $result = self::validateToken();
                return Response::ok($result)->send();

            } catch (\Exception $e) {
                return Response::unauthorized('Invalid or expired token')->send();
            }
        }); // Not public, requires token

        $router->addRoute('POST', 'auth/refresh-token', function($params) {
            Logger::log('REST Request - Refresh Token', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/refresh-token',
                'params' => $params,
                'headers' => getallheaders()
            ]);

            try {
                // Check request body for refresh token
                $input = file_get_contents('php://input');
                $postData = json_decode($input, true) ?? [];
                if (isset($postData['refresh_token'])) {
                    $refreshToken = $postData['refresh_token'];
                }
                
                if (!$refreshToken) {
                    return Response::unauthorized('Refresh token required')->send();
                }

                $result = TokenManager::refreshTokens($refreshToken);
                
                if (!$result || !isset($result['token'])) {
                    return Response::unauthorized('Invalid or expired refresh token')->send();
                }
                
                return Response::ok($result)->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Token refresh failed: ' . $e->getMessage(), 
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true); // Public route since token is expired

        $router->addRoute('GET', 'files/{uuid}', function($params) {
            Logger::log('REST Request - Get Blob', [
                'method' => 'GET',
                'resource' => 'blobs',
                'uuid' => $params['uuid'],
                'params' => array_merge($params, $_GET),
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                $result = APIEngine::getBlob('blobs', 'retrieve', $params);
                
                if (!isset($result['content'])) {
                    return Response::error('Blob not found', Response::HTTP_NOT_FOUND)->send();
                }
                
                if (isset($result['mime_type'])) {
                    header('Content-Type: ' . $result['mime_type']);
                }
                if (isset($result['filename'])) {
                    header('Content-Disposition: inline; filename="' . $result['filename'] . '"');
                }
                
                echo base64_decode($result['content']);
                exit;
                
            } catch (\Exception $e) {
                return Response::error(
                    'Failed to retrieve blob: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });

        $router->addRoute('POST', 'files', function($params) {
            Logger::log('REST Request - Upload Blob', [
                'method' => 'POST',
                'resource' => 'blobs',
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                
                // Handle multipart form data (regular file upload)
                if (strpos($contentType, 'multipart/form-data') !== false) {
                    if (empty($_FILES)) {
                        return Response::error('No file uploaded', Response::HTTP_BAD_REQUEST)->send();
                    }
                    $response = self::handleFileUpload($_GET, $_FILES);
                } 
                // Handle JSON/base64 upload
                else {
                    $input = file_get_contents('php://input');
                    $postData = json_decode($input, true) ?? [];
                    
                    if (!isset($postData['base64'])) {
                        return Response::error('Base64 content required', Response::HTTP_BAD_REQUEST)->send();
                    }
                    
                    $response = self::handleBase64Upload($_GET, $postData);
                }
                
                Logger::log('REST Response - Upload Blob', ['response' => $response]);
                return $response;
                
            } catch (\Exception $e) {
                return Response::error(
                    'Upload failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });

        // Protected routes (require token)
        // List all resources
        $router->addRoute('GET', '{resource}', function($params) {
            Logger::log('REST Request - List', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'resource' => $params['resource'],
                'params' => array_merge($params, $_GET),
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                // Get token from request
                $token = $_GET['token'] ?? null;
                
                // Verify permissions
                if (!Permissions::hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }

                // Handle query parameters
                $queryParams = array_merge($_GET, [
                    'fields' => $_GET['fields'] ?? '*',
                    'sort' => $_GET['sort'] ?? null,
                    'filter' => $_GET['filter'] ?? null,
                    'page' => $_GET['page'] ?? 1,
                    'limit' => $_GET['limit'] ?? 20
                ]);
                
                // Direct call to getData without legacy conversion
                $result = APIEngine::getData(
                    $params['resource'],
                    'list',
                    $queryParams
                );
                
                Logger::log('REST Response - List', ['response' => $result]);
                return Response::ok($result)->send();
                
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        // Get single resource by UUID
        $router->addRoute('GET', '{resource}/{uuid}', function($params) {
            Logger::log('REST Request - Get Single', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'resource' => $params['resource'],
                'id' => $params['uuid'],
                'params' => array_merge($params, $_GET),
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                // Get token from request
                $token = $_GET['token'] ?? null;
                
                // Verify permissions
                if (!Permissions::hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }

                // Set up parameters for single record retrieval
                $queryParams = array_merge($_GET, [
                    'fields' => $_GET['fields'] ?? '*',
                    'id' => $params['uuid']
                ]);
                
                // Direct call to getData without legacy conversion
                $result = APIEngine::getData(
                    $params['resource'],
                    'list',
                    $queryParams
                );
                
                if (empty($result)) {
                    return Response::error('Resource not found', Response::HTTP_NOT_FOUND)->send();
                }
                
                Logger::log('REST Response - Get Single', ['response' => $result]);
                return Response::ok($result[0])->send();
                
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', '{resource}', function($params) {
            // Log request
            Logger::log('REST Request - Save', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'resource' => $params['resource'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            // Validate authentication
            self::validateToken();
            
            try {
                // Get content type and parse body
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $data = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $data = json_decode(file_get_contents('php://input'), true) ?? [];
                } else {
                    $data = $_POST;
                }

                // Get token from request
                $token = $_GET['token'] ?? null;
                
                // Verify permissions
                if (!Permissions::hasPermission("api.{$params['resource']}", Permission::SAVE, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
        
                // Direct API call without legacy conversion
                $response = APIEngine::saveData(
                    $params['resource'],  // resource name
                    'save',              // action
                    $data                // data to save
                );
                
                // Handle auditing if enabled
                if (config('app.enable_audit')) {
                    self::auditChanges($params['resource'], $_GET, $data, $response);
                }
        
                // Log and return response
                Logger::log('REST Response - Save', ['response' => $response]);
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Save failed: ' . $e->getMessage())->send();
            }
        });
        
        // PUT Route (Update)
        $router->addRoute('PUT', '{resource}/{uuid}', function($params) {
            Logger::log('REST Request - Replace', [
                'method' => 'PUT',
                'resource' => $params['resource'],
                'id' => $params['uuid'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                // Get request body
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $putData = [];
                
                if (strpos($contentType, 'application/json') !== false) {
                    $putData = json_decode(file_get_contents('php://input'), true) ?? [];
                } else {
                    parse_str(file_get_contents('php://input'), $putData);
                }
                
                // Add UUID to data
                $putData['id'] = $params['uuid'];
                
                // Get token from request
                $token = $_GET['token'] ?? null;
                
                // Verify permissions
                if (!Permissions::hasPermission("api.{$params['resource']}", Permission::SAVE, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
                
                $response = APIEngine::saveData(
                    $params['resource'],
                    'update',
                    $putData
                );
                
                // Handle audit if enabled
                if (config('app.enable_audit')) {
                    self::auditChanges($params['resource'], [], $putData, $response);
                }
                
                Logger::log('REST Response - Replace', ['response' => $response]);
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Update failed: ' . $e->getMessage())->send();
            }
        });
        
        // DELETE Route
        $router->addRoute('DELETE', '{resource}/{uuid}', function($params) {
            Logger::log('REST Request - Delete', [
                'method' => 'DELETE',
                'resource' => $params['resource'],
                'id' => $params['uuid'],
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            self::validateToken();
            
            try {
                // Get token from request
                $token = $_GET['token'] ?? null;
                
                // Verify permissions
                if (!Permissions::hasPermission("api.{$params['resource']}", Permission::SAVE, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
                
                // Direct delete without legacy conversion
                $response = APIEngine::saveData(
                    $params['resource'],
                    'delete',
                    ['id' => $params['uuid'], 'status' => 'D']
                );
                
                Logger::log('REST Response - Delete', ['response' => $response]);
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Delete failed: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', 'auth/logout', function($params) {
            // Log the request
            Logger::log('REST Request - Logout', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => 'auth/logout',
                'params' => $params,
                'headers' => getallheaders()
            ]);
            
            // Validate token
            self::validateToken();
            
            try {
                // Get token from request
                $token = $_GET['token'] ?? null;
                
                if (!$token) {
                    return Response::unauthorized('Token required')->send();
                }
                
                // Direct call to kill session without legacy conversion
                $result = APIEngine::killSession(['token' => $token]);
                
                Logger::log('REST Response - Logout', ['response' => $result]);
                return Response::ok(['message' => 'Logged out successfully'])->send();
                
            } catch (\Exception $e) {
                return Response::error(
                    'Logout failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });
    }

    private static function validateToken(): array 
    {
        $token = self::getAuthAuthorizationToken();
        
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
            return $result;
        } catch (\Exception $e) {
            echo json_encode(Response::error('Token validation failed', Response::HTTP_INTERNAL_SERVER_ERROR)->send());
            exit;
        }
    }

    

    private static function prepareDatabaseResource(): void 
    {

        $databaseResource = config('database.primary');
        Utils::createPDOConnection($databaseResource);
    }

    private static function validateLoginCredentials(string $function, array $params): bool 
    {
        return match($function) {
            'sessions' => isset($params['username']) && isset($params['password']),
            default => false
        };
    }

    private static function handleLogin(string $function, array $postParams): array 
    {
        if (!self::validateLoginCredentials($function, $postParams)) {
            return Response::error('Username/Email and Password Required', Response::HTTP_BAD_REQUEST)->send();
        }

        try {
            // Check if username value is an email address
            if ($function === 'sessions' && isset($postParams['username'])) {
                if (filter_var($postParams['username'], FILTER_VALIDATE_EMAIL)) {
                    // If username is actually an email, move it to email parameter
                    $postParams['email'] = $postParams['username'];
                    unset($postParams['username']);
                }
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


    private static function handleBase64Upload(array $getParams, array $postParams): array 
    {
        try {
            $uploader = new FileUploader();

            $token = self::getAuthAuthorizationToken();
            if (!$token) {
                echo json_encode(Response::unauthorized('Authentication required')->send());
                exit;
            }
            
            $_GET['token'] = $token; // Store token for downstream use
            
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

            $token = self::getAuthAuthorizationToken();
        
            if (!$token) {
                echo json_encode(Response::unauthorized('Authentication required')->send());
                exit;
            }
            
            $_GET['token'] = $token; // Store token for downstream use

            return $uploader->handleUpload($getParams['token'], $getParams, $fileParams);
        } catch (\Exception $e) {
            return Response::error('File upload failed: ' . $e->getMessage())->send();
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

     /**
     * Extract authorization token from request
     *
     * @return string|null The bearer token or null if not found
     */
    private static function getAuthAuthorizationToken(): ?string 
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

        return $token;
    }

    private static function loadExtensions(): void 
{
    $extensionsMap = [
        'api/api-extensions/' => 'Mapi\\Api\\Extensions\\',
        'extensions/' => 'Mapi\\Extensions\\'
    ];
    
    foreach ($extensionsMap as $directory => $namespace) {
        self::scanExtensionsDirectory(
            dirname(__DIR__) . '/' . $directory, 
            $namespace, 
            Router::getInstance()
        );
    }
}

private static function scanExtensionsDirectory(string $dir, string $namespace, Router $router): void 
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relativePath = substr($file->getPathname(), strlen($dir));
            $className = str_replace(
                ['/', '.php'],
                ['\\', ''],
                $relativePath
            );
            $fullClassName = $namespace . $className;

            // Check if class exists and extends Extensions
            if (class_exists($fullClassName)) {
                $reflection = new \ReflectionClass($fullClassName);
                if ($reflection->isSubclassOf(\Mapi\Api\Library\Extensions::class)) {
                    try {
                        // Check if class has initializeRoutes method
                        if ($reflection->hasMethod('initializeRoutes')) {
                            Logger::log('Loading Extension', [
                                'class' => $fullClassName,
                                'file' => $file->getPathname()
                            ]);
                            
                            // Initialize routes for this extension
                            $fullClassName::initializeRoutes($router);
                        }
                    } catch (\Exception $e) {
                        Logger::log('Extension Load Error', [
                            'class' => $fullClassName,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }
    }
}

    public static function processRequest(): array 
    {
        // Set JSON response headers
        header('Content-Type: application/json');
        
        // Initialize API
        self::init();
        
        // Get router instance
        $router = Router::getInstance();
        
        // Let router handle the request
        return $router->handleRequest();
    }
}
?>