<?php
declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\SocialLogin\Providers\AbstractSocialProvider;

/**
 * Google Authentication Provider
 * 
 * Handles Google OAuth authentication flow and user management.
 * 
 * @package Glueful\Extensions\SocialLogin\Providers
 */
class GoogleAuthProvider extends AbstractSocialProvider
{
    /** @var string Client ID from Google API Console */
    private string $clientId;
    
    /** @var string Client secret from Google API Console */
    private string $clientSecret;
    
    /** @var string Redirect URI for Google OAuth callback */
    private string $redirectUri;
    
    /** @var array OAuth scopes requested */
    private array $scopes = [
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/userinfo.email'
    ];
    
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        
        // Set provider name
        $this->providerName = 'google';
        
        // Load configuration
        $this->loadConfig();
    }
    
    /**
     * Load configuration from environment
     * 
     * @return void
     */
    private function loadConfig(): void
    {
        // Get config from extension settings or environment
        $config = \Glueful\Extensions\SocialLogin\SocialLogin::getConfig();
        
        $this->clientId = $config['google']['client_id'] ?? 
                          getenv('GOOGLE_CLIENT_ID') ?? '';
        
        $this->clientSecret = $config['google']['client_secret'] ?? 
                              getenv('GOOGLE_CLIENT_SECRET') ?? '';
        
        $this->redirectUri = $config['google']['redirect_uri'] ?? 
                             getenv('GOOGLE_REDIRECT_URI') ?? 
                             $this->getDefaultRedirectUri();
    }
    
    /**
     * Generate default redirect URI based on current host
     * 
     * @return string Default redirect URI
     */
    private function getDefaultRedirectUri(): string
    {
        // Get base URL from config
        $baseUrl = config('app.url', '');
        
        if (empty($baseUrl) && isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || 
                         $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $baseUrl = $protocol . $_SERVER['HTTP_HOST'];
        }
        
        return $baseUrl . '/auth/social/google/callback';
    }
    
    /**
     * Check if request is a Google OAuth callback
     * 
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/google/callback') !== false && 
               $request->query->has('code');
    }
    
    /**
     * Check if request is to initialize Google OAuth flow
     * 
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/google') !== false && 
               !strpos($path, '/callback');
    }
    
    /**
     * Handle Google OAuth callback
     * 
     * Process callback from Google, validate token/code,
     * and retrieve user information.
     * 
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            $this->lastError = "Google OAuth configuration is missing";
            return null;
        }
        
        // Get authorization code from request
        $code = $request->query->get('code');
        
        if (empty($code)) {
            $this->lastError = "Authorization code missing from request";
            return null;
        }
        
        try {
            // Exchange code for access token
            $tokenData = $this->exchangeCodeForToken($code);
            
            if (!isset($tokenData['access_token'])) {
                $this->lastError = "Failed to get access token: " . 
                                  ($tokenData['error'] ?? 'Unknown error');
                return null;
            }
            
            // Get user profile with the access token
            $userProfile = $this->getUserProfile($tokenData['access_token']);
            
            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }
            
            // Find or create user from Google data
            return $this->findOrCreateUser($userProfile);
            
        } catch (\Exception $e) {
            $this->lastError = "Google OAuth error: " . $e->getMessage();
            return null;
        }
    }
    
    /**
     * Initiate Google OAuth flow
     * 
     * Generate authorization URL and redirect user to Google.
     * 
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException("Google OAuth configuration is missing");
        }
        
        // Generate state token to prevent CSRF
        $state = bin2hex(random_bytes(16));
        
        // Store state in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['google_oauth_state'] = $state;
        
        // Build authorization URL
        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(' ', $this->scopes),
            'access_type' => 'offline',
            'prompt' => 'select_account'
        ];
        
        $authUrl .= '?' . http_build_query($params);
        
        // Redirect to Google
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }
    
    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from Google
     * @return array Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Token endpoint
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        
        // Request parameters
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ];
        
        // Make POST request to token endpoint
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL error: $error");
        }
        
        // Parse JSON response
        $tokenData = json_decode($response, true);
        
        if (!is_array($tokenData)) {
            throw new \Exception("Invalid token response: $response");
        }
        
        return $tokenData;
    }
    
    /**
     * Get user profile from Google
     * 
     * @param string $accessToken Access token from Google
     * @return array User profile data
     */
    private function getUserProfile(string $accessToken): array
    {
        // Google user info endpoint
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
        
        // Make GET request with access token
        $ch = curl_init($userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL error: $error");
        }
        
        // Parse JSON response
        $userProfile = json_decode($response, true);
        
        if (!is_array($userProfile)) {
            throw new \Exception("Invalid profile response: $response");
        }
        
        // Format profile data to our standard format
        return [
            'id' => $userProfile['sub'] ?? null,
            'email' => $userProfile['email'] ?? null,
            'name' => $userProfile['name'] ?? null,
            'first_name' => $userProfile['given_name'] ?? null,
            'last_name' => $userProfile['family_name'] ?? null,
            'picture' => $userProfile['picture'] ?? null,
            'locale' => $userProfile['locale'] ?? null,
            'verified_email' => $userProfile['email_verified'] ?? false,
            'raw' => $userProfile
        ];
    }
}