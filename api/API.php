<?php
declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Response,Router};
use Glueful\Helpers\{FileHandler, Request, ExtensionsManager};
use Glueful\Controllers\{ResourceController, AuthController};

/**
 * Main API Router and Request Handler
 * 
 * Provides centralized routing and request handling for the API:
 * - Route registration and management
 * - Authentication and authorization
 * - Request validation and processing
 * - Response formatting
 * - File upload handling
 * - Audit logging
 * 
 * @package Glueful\Api
 */
class API 
{
    private static bool $routesInitialized = false;
    
    /**
     * Initialize API Routes and Extensions
     * 
     * Sets up the routing system and loads API extensions:
     * - Loads API extensions first to allow route registration
     * - Initializes core API routes
     * - Sets up authentication routes
     * - Configures CRUD endpoints
     * - Registers file handling routes
     * 
     * @return void
     */
    public static function init(): void
    {
        if (!self::$routesInitialized) {
            // Load extensions before main routes
            // This allows extensions to register their routes first
            ExtensionsManager::loadExtensions();
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
        $resourceController = new ResourceController();
        $request = new Request();
        $authController = new AuthController();
        $fileHandler = new FileHandler();
        
        // Public routes - using relative paths
        $router->addRoute('POST', 'auth/login', function($params)use ($authController) {
            try {
                
                // Get the Response object
                $response = $authController->login();
                return $response->send();
                
            } catch (\Exception $e) {
                return Response::error(
                    'Login failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true); // Mark as public

        $router->addRoute('POST', 'auth/verify-email', function($params) use ($authController) {

            try {
                $response = $authController->verifyEmail();
                return $response->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to send verification email: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/verify-otp', function($params) use ($authController) {
            try {

               $response = $authController->verifyOtp();
               return $response->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to verify OTP: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/forgot-password', function($params)use ($authController) {

            try {
                $response = $authController->forgotPassword();
                return $response->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to process password reset request: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/reset-password', function($params)use ($authController) {
            try {
                $response = $authController->resetPassword();
                return $response->send();

            } catch (\Exception $e) {
                return Response::error(
                    'Failed to reset password: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('POST', 'auth/validate-token', function($params)use ($authController) {

            try {
                $result = $authController->validateToken();
                return $result->send();

            } catch (\Exception $e) {
                return Response::unauthorized('Invalid or expired token')->send();
            }
        }); // Not public, requires token

        $router->addRoute('POST', 'auth/refresh-token', function($params)use ($authController) {
            try {
               $response = $authController->refreshToken();
               return $response->send();
        
            } catch (\Exception $e) {
                return Response::error(
                    'Token refresh failed: ' . $e->getMessage(), 
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        }, true);

        $router->addRoute('GET', 'files/{uuid}', function($params)use ($authController, $request, $fileHandler) {
            $authController->validateToken();
            $requestData = $request->getQueryParams();

             // Get parameters from request
            $uuid = $requestData['uuid'] ?? null;
            $type = $requestData['type'] ?? 'info';

            try {
                // $result = APIEngine::getBlob('blobs', 'retrieve', $params);
                if (!isset($fileData['uuid'])) {
                    return Response::error('File UUID is required', Response::HTTP_BAD_REQUEST);
                }
                // Process image parameters if needed
                $params = [];
                if ($type === 'image') {
                    $params = [
                        'w' => $requestData['w'] ?? null,
                        'h' => $requestData['h'] ?? null,
                        'q' => $requestData['q'] ?? 80,
                        'z' => $requestData['z'] ?? null
                    ];
                }
                 // Process image parameters if needed
                 $result = $fileHandler->getBlob($uuid, $type, $params);
               
                // Note: For download and inline types, the method will automatically
                // set headers and stream the file, so this return is only reached for
                // info and image types, or if there was an error
                return Response::ok($result, 'File retrieved successfully');
                // echo base64_decode($result['content']);
                exit;
                
            } catch (\Exception $e) {
                return Response::error(
                    'Failed to retrieve blob: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });

        $router->addRoute('POST', 'files', function($params) use ($request, $authController, $fileHandler) {
            $authController->validateToken();
            try {
                $contentType = $request->getContentType();
                // Handle multipart form data (regular file upload)
                if (strpos($contentType, 'multipart/form-data') !== false) {
                    if (empty($request->getFiles())) {
                        return Response::error('No file uploaded', Response::HTTP_BAD_REQUEST)->send();
                    }
                    
                    return  $fileHandler->handleFileUpload($request->getQueryParams(), $request->getFiles());      
                } 
        
                // Handle JSON/base64 upload
                else {
                    $postData = Request::getPostData() ?? [];
                    if (!isset($postData['base64'])) {
                        return Response::error('Base64 content required', Response::HTTP_BAD_REQUEST)->send();
                    }
                    return $fileHandler->handleBase64Upload($request->getQueryParams(), $postData);
                }
                
            } catch (\Exception $e) {
                return Response::error(
                    'Upload failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });

        // Protected routes (require token)
        // List all resources
        $router->addRoute('GET', '{resource}', function($params) use ($resourceController, $request) {
            try {
                $queryParams = $request->getQueryParams();
                return $resourceController->get($params, $queryParams);
            
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        // Get single resource by UUID
        $router->addRoute('GET', '{resource}/{uuid}', function($params) use ($resourceController, $request){
            
            try {
                $queryParams = $request->getQueryParams();
                return $resourceController->getSingle($params, $queryParams);
                
            } catch (\Exception $e) {
                return Response::error('Failed to retrieve data: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', '{resource}', function($params) use ($resourceController){
            try {
                // Get content type and parse body
                $postData = Request::getPostData();
                return $resourceController->post($params, $postData);
                
            } catch (\Exception $e) {
                return Response::error('Save failed: ' . $e->getMessage())->send();
            }
        });
        
        // PUT Route (Update)
        $router->addRoute('PUT', '{resource}/{uuid}', function($params) use ($resourceController){
            try {
                // Get request body
                $putData = Request::getPostData();
                // Add UUID to data
                $putData['id'] = $params['uuid'];
                return $resourceController->put($params, $putData);
                
            } catch (\Exception $e) {
                return Response::error('Update failed: ' . $e->getMessage())->send();
            }
        });
        
        // DELETE Route
        $router->addRoute('DELETE', '{resource}/{uuid}', function($params) use ($resourceController, $authController){
            $authController->validateToken();
            
            try {

                return $resourceController->delete($params);

            } catch (\Exception $e) {
                return Response::error('Delete failed: ' . $e->getMessage())->send();
            }
        });
        
        $router->addRoute('POST', 'auth/logout', function($params)use ($authController) {
            try {
               
                return $authController->logout();
                
            } catch (\Exception $e) {
                return Response::error(
                    'Logout failed: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        });
    }

    /**
     * Process API Request
     * 
     * Main entry point for handling API requests:
     * - Sets response headers
     * - Initializes API system
     * - Routes request to appropriate handler
     * - Handles errors and exceptions
     * - Returns formatted response
     * 
     * @return array API response with status and data
     * @throws \RuntimeException If request processing fails
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