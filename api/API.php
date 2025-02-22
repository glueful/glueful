<?php
declare(strict_types=1);

namespace Glueful\Api;

use Glueful\Api\Library\{
    Permissions,
    Permission,
    Utils,
    APIEngine,
    TokenManager,
    Security\EmailVerification
};
use Glueful\Api\Http\{
    Response,
    Router
};
use Glueful\Api\Extensions\Uploader\FileUploader;
use Glueful\Api\Library\Logging\LogManager;
use Monolog\Level;

/**
 * Main API handler class
 * 
 * Manages API routing, authentication, request handling, and response generation.
 * Provides RESTful endpoints for user authentication, CRUD operations, and file handling.
 */
class API 
{
    private static bool $routesInitialized = false;
    private static ?LogManager $logger = null;
    
    /**
     * Initialize the API system
     * 
     * Sets up logging, loads extensions, and initializes routes if not already done.
     */
    public static function init(): void
    {
        if (self::$logger === null) {
            self::$logger = new LogManager();
        }
        if (!self::$routesInitialized) {
        // Load extensions before main routes
        // This allows extensions to register their routes first
        self::loadExtensions();
            self::initializeRoutes();
            self::$routesInitialized = true;
        }
    }

    /**
     * Set up all API routes
     * 
     * Configures public and protected routes for authentication, CRUD operations,
     * file handling, and other API functionalities.
     */
    private static function initializeRoutes(): void 
    {
        
        $router = Router::getInstance();
        
        // Public routes - using relative paths
        $router->addRoute('POST', 'auth/login', function($params) {
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
            return $response;
        }, true); // Mark as public

        $router->addRoute('POST', 'auth/verify-email', function($params) {

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
                    'data' => [
                        'email' => $postData['email'],
                        'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
                    ]
                ], 'Verification code has been sent to your email')->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to send verification email: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/verify-otp', function($params) {

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
                    'data' => [
                        'email' => $postData['email'],
                        'verified' => true,
                        'verified_at' => date('Y-m-d\TH:i:s\Z')
                    ]
                ], 'OTP verified successfully')->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to verify OTP: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/forgot-password', function($params) {

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
                    'data' => [
                        'email' => $postData['email'],
                        'expires_in' =>  EmailVerification::OTP_EXPIRY_MINUTES * 60
                    ]
                ], 'Password reset instructions have been sent to your email')->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to process password reset request: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/reset-password', function($params) {
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
                    'data' => [
                        'email' => $postData['email'],
                        'updated_at' => date('Y-m-d\TH:i:s\Z')
                    ]
                ], 'Password has been reset successfully')->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to reset password: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/validate-token', function($params) {

            try {
                $result = self::validateToken();
                return Response::ok($result)->send();

            } catch (\Exception $e) {
                return Response::unauthorized('Invalid or expired token')->send();
            }
        }); // Not public, requires token

        $router->addRoute('POST', 'auth/refresh-token', function($params) {
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
        
                $tokens = TokenManager::refreshTokens($refreshToken);
                
                if (!$tokens || !isset($tokens['access_token'])) {
                    return Response::unauthorized('Invalid or expired refresh token')->send();
                }
                
                return Response::ok([
                    'tokens' => [
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'],
                        'token_type' => 'Bearer',
                        'expires_in' => config('session.access_token_lifetime')
                    ]
                ], 'Token refreshed successfully')->send();
        
            } catch (\Exception $e) {
                return Response::error(
                    'Token refresh failed: ' . $e->getMessage(), 
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('GET', 'files/{uuid}', function($params) {
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
                    'sort' => $_GET['sort'] ?? 'created_at',
                    'page' => $_GET['page'] ?? 1,
                    'per_page' => $_GET['per_page'] ?? 25,
                    'order' => $_GET['order'] ?? 'desc'
                ]);
                
                // Direct call to getData without legacy conversion
                $result = APIEngine::getData(
                    $params['resource'],
                    'list',
                    $queryParams
                );
                
                return Response::ok($result)->send();
                
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        // Get single resource by UUID
        $router->addRoute('GET', '{resource}/{uuid}', function($params) {
            
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
                    'uuid' => $params['id'],
                    'paginate' => false
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

                return Response::ok($result[0])->send();
                
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', '{resource}', function($params) {
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
        
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Save failed: ' . $e->getMessage())->send();
            }
        });
        
        // PUT Route (Update)
        $router->addRoute('PUT', '{resource}/{uuid}', function($params) {
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
                
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Update failed: ' . $e->getMessage())->send();
            }
        });
        
        // DELETE Route
        $router->addRoute('DELETE', '{resource}/{uuid}', function($params) {
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
                    ['uuid' => $params['uuid'], 'status' => 'D']
                );
                
                return Response::ok($response)->send();
                
            } catch (\Exception $e) {
                return Response::error('Delete failed: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', 'auth/logout', function($params) {
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
                
                return Response::ok(['message' => 'Logged out successfully'])->send();
                
            } catch (\Exception $e) {
                return Response::error(
                    'Logout failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });
    }

    /**
     * Validate authentication token
     * 
     * Checks if the provided token is valid and not expired.
     * Exits with appropriate error response if validation fails.
     * 
     * @return array Session data if token is valid
     */
    private static function validateToken(): array 
    {
        $token = self::getAuthAuthorizationToken();
        
        if (!$token) {
            self::$logger->log(
                "Authentication failed - No token provided",
                ['ip' => $_SERVER['REMOTE_ADDR']],
                Level::Warning,
                'auth'
            );
            echo json_encode(Response::unauthorized('Authentication required')->send());
            exit;
        }
        
        $_GET['token'] = $token;
        
        try {
            $result = APIEngine::validateSession('sessions', 'validate', ['token' => $token]);
            
            if (!isset($result['success']) || !$result['success']) {
                self::$logger?->log(
                    "Token validation failed",
                    [
                        'token_prefix' => substr($token, 0, 8) . '...',
                        'ip' => $_SERVER['REMOTE_ADDR'],
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ],
                    Level::Warning,
                    'auth'
                );
                echo json_encode(Response::unauthorized('Invalid or expired token')->send());
                exit;
            }

            // Log successful validation
            self::$logger?->log(
                "Token validated successfully",
                [
                    'user_uuid' => $result['uuid'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR']
                ],
                Level::Info,
                'auth'
            );
            
            return $result;
        } catch (\Exception $e) {
            self::$logger?->log(
                "Token validation error",
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'ip' => $_SERVER['REMOTE_ADDR']
                ],
                Level::Error,
                'auth'
            );
            echo json_encode(Response::error('Token validation failed', Response::HTTP_INTERNAL_SERVER_ERROR)->send());
            exit;
        }
    }

    /**
     * Set up database connection for the current request
     * 
     * Creates PDO connection using configuration settings
     */
    private static function prepareDatabaseResource(): void 
    {

        $databaseResource = config('database.primary');
        Utils::createPDOConnection($databaseResource);
    }

    /**
     * Validate login credentials based on function type
     * 
     * @param string $function The login function type
     * @param array $params Login parameters
     * @return bool True if credentials are valid
     */
    private static function validateLoginCredentials(string $function, array $params): bool 
    {
        return match($function) {
            'sessions' => isset($params['username']) && isset($params['password']),
            default => false
        };
    }

    /**
     * Handle user login process
     * 
     * Validates credentials and creates user session if valid.
     * 
     * @param string $function Login function type
     * @param array $postParams Login data
     * @return array Response with session data or error
     */
    private static function handleLogin(string $function, array $postParams): array 
    {
        if (!self::validateLoginCredentials($function, $postParams)) {
            self::$logger?->log(
                "Login failed - Missing credentials",
                ['ip' => $_SERVER['REMOTE_ADDR']],
                Level::Warning,
                'auth'
            );
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
                self::$logger?->log(
                    "Login failed - Invalid credentials",
                    [
                        'username' => $postParams['username'] ?? $postParams['email'] ?? 'unknown',
                        'ip' => $_SERVER['REMOTE_ADDR']
                    ],
                    Level::Warning,
                    'auth'
                );
                return Response::error(
                    $result['message'] ?? 'Login failed', 
                    $result['code'] ?? Response::HTTP_BAD_REQUEST
                )->send();
            }

            self::$logger?->log(
                "Login successful",
                [
                    'user_uuid' => $result['user']['uuid'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR']
                ],
                Level::Info,
                'auth'
            );
            
            return Response::ok($result)->send();
            
        } catch (\Exception $e) {
            self::$logger?->log(
                "Login error",
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'ip' => $_SERVER['REMOTE_ADDR']
                ],
                Level::Error,
                'auth'
            );
            return Response::error(
                'Login failed: ' . $e->getMessage(), 
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Process base64 encoded file upload
     * 
     * @param array $getParams Query parameters
     * @param array $postParams Post data with base64 content
     * @return array Upload response
     */
    private static function handleBase64Upload(array $getParams, array $postParams): array 
    {
        try {
            $uploader = new FileUploader();

            $token = self::getAuthAuthorizationToken();
            if (!$token) {
                self::$logger?->log(
                    "Upload failed - No authentication",
                    ['ip' => $_SERVER['REMOTE_ADDR']],
                    Level::Warning,
                    'upload'
                );
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

            self::$logger?->log(
                "Processing base64 upload",
                [
                    'filename' => $fileParams['name'],
                    'size' => $fileParams['size'],
                    'mime_type' => $fileParams['type']
                ],
                Level::Info,
                'upload'
            );

            $response = $uploader->handleUpload(
                $getParams['token'],
                $getParams,
                ['file' => $fileParams]
            );

            self::$logger?->log(
                "Base64 upload completed",
                [
                    'filename' => $fileParams['name'],
                    'uuid' => $response['uuid'] ?? null
                ],
                Level::Info,
                'upload'
            );

            return $response;

        } catch (\Exception $e) {
            self::$logger?->log(
                "Base64 upload failed",
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                Level::Error,
                'upload'
            );
            return Response::error('Base64 upload failed: ' . $e->getMessage())->send();
        }
    }

    /**
     * Process regular file upload
     * 
     * @param array $getParams Query parameters
     * @param array $fileParams File upload data
     * @return array Upload response
     */
    private static function handleFileUpload(array $getParams, array $fileParams): array 
    {
        try {
            $uploader = new FileUploader();

            $token = self::getAuthAuthorizationToken();
        
            if (!$token) {
                self::$logger?->log(
                    "Upload failed - No authentication",
                    ['ip' => $_SERVER['REMOTE_ADDR']],
                    Level::Warning,
                    'upload'
                );
                echo json_encode(Response::unauthorized('Authentication required')->send());
                exit;
            }
            
            $_GET['token'] = $token; // Store token for downstream use

            self::$logger?->log(
                "Processing file upload",
                [
                    'filename' => $fileParams['file']['name'] ?? 'unknown',
                    'size' => $fileParams['file']['size'] ?? 0,
                    'type' => $fileParams['file']['type'] ?? 'unknown'
                ],
                Level::Info,
                'upload'
            );

            $response = $uploader->handleUpload($getParams['token'], $getParams, $fileParams);

            self::$logger?->log(
                "File upload completed",
                [
                    'filename' => $fileParams['file']['name'] ?? 'unknown',
                    'uuid' => $response['uuid'] ?? null
                ],
                Level::Info,
                'upload'
            );

            return $response;

        } catch (\Exception $e) {
            self::$logger?->log(
                "File upload failed",
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'filename' => $fileParams['file']['name'] ?? 'unknown'
                ],
                Level::Error,
                'upload'
            );
            return Response::error('File upload failed: ' . $e->getMessage())->send();
        }
    }

    /**
     * Record changes for auditing
     * 
     * Tracks data modifications for audit logging.
     * 
     * @param string $function Modified entity
     * @param array $getParams Query parameters
     * @param array $postParams Modified data
     * @param array $response Operation response
     */
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

    /**
     * Load API extensions
     * 
     * Scans directories for extension classes and initializes them.
     */
    private static function loadExtensions(): void 
{
    $extensionsMap = [
        'api/api-extensions/' => 'Glueful\\Api\\Extensions\\',
        'extensions/' => 'Glueful\\Extensions\\'
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
                if ($reflection->isSubclassOf(\Glueful\Api\Library\Extensions::class)) {
                    try {
                        // Check if class has initializeRoutes method
                        if ($reflection->hasMethod('initializeRoutes')) {
                            // Initialize routes for this extension
                            $fullClassName::initializeRoutes($router);
                        }
                    } catch (\Exception $e) {
                    }
                }
            }
        }
    }
}

    /**
     * Process incoming API request
     * 
     * Main entry point for handling API requests.
     * Sets up environment and routes request to appropriate handler.
     * 
     * @return array API response
     */
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