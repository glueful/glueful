<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\SocialLogin\Providers\AbstractSocialProvider;

/**
 * Facebook Authentication Provider
 *
 * Handles Facebook OAuth authentication flow and user management.
 *
 * @package Glueful\Extensions\SocialLogin\Providers
 */
class FacebookAuthProvider extends AbstractSocialProvider
{
    /** @var string App ID from Facebook Developers */
    private string $appId;

    /** @var string App secret from Facebook Developers */
    private string $appSecret;

    /** @var string Redirect URI for Facebook OAuth callback */
    private string $redirectUri;

    /** @var array OAuth scopes requested */
    private array $scopes = ['email', 'public_profile'];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Set provider name
        $this->providerName = 'facebook';

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
        $config = \Glueful\Extensions\SocialLogin::getConfig();

        // Make sure the config has the expected structure
        if (!is_array($config) || !isset($config['facebook']) || !is_array($config['facebook'])) {
            $config = [
                'facebook' => []
            ];
        }

        $this->appId = !empty($config['facebook']['app_id']) ?
                       $config['facebook']['app_id'] :
                       (getenv('FACEBOOK_APP_ID') ?: '');

        $this->appSecret = !empty($config['facebook']['app_secret']) ?
                           $config['facebook']['app_secret'] :
                           (getenv('FACEBOOK_APP_SECRET') ?: '');

        $this->redirectUri = !empty($config['facebook']['redirect_uri']) ?
                             $config['facebook']['redirect_uri'] :
                             (getenv('FACEBOOK_REDIRECT_URI') ?: $this->getDefaultRedirectUri());
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

        return $baseUrl . '/auth/social/facebook/callback';
    }

    /**
     * Check if request is a Facebook OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/facebook/callback') !== false &&
               $request->query->has('code');
    }

    /**
     * Check if request is to initialize Facebook OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/facebook') !== false &&
               !strpos($path, '/callback');
    }

    /**
     * Handle Facebook OAuth callback
     *
     * Process callback from Facebook, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            $this->lastError = "Facebook OAuth configuration is missing";
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
                                  ($tokenData['error']['message'] ?? 'Unknown error');
                return null;
            }

            // Get user profile with the access token
            $userProfile = $this->getUserProfile($tokenData['access_token']);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Find or create user from Facebook data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Facebook OAuth error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Initiate Facebook OAuth flow
     *
     * Generate authorization URL and redirect user to Facebook.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            throw new \RuntimeException("Facebook OAuth configuration is missing");
        }

        // Generate state token to prevent CSRF
        $state = bin2hex(random_bytes(16));

        // Store state in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['facebook_oauth_state'] = $state;

        // Build authorization URL
        $authUrl = 'https://www.facebook.com/v15.0/dialog/oauth';
        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => implode(',', $this->scopes),
            'response_type' => 'code',
            'auth_type' => 'rerequest'
        ];

        $authUrl .= '?' . http_build_query($params);

        // Redirect to Facebook
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from Facebook
     * @return array Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Token endpoint
        $tokenUrl = 'https://graph.facebook.com/v15.0/oauth/access_token';

        // Request parameters
        $params = [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ];

        // Make request to token endpoint
        $ch = curl_init($tokenUrl . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

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
     * Get user profile from Facebook
     *
     * @param string $accessToken Access token from Facebook
     * @return array User profile data
     */
    private function getUserProfile(string $accessToken): array
    {
        // Facebook Graph API endpoint
        $fields = 'id,name,email,first_name,last_name,picture.type(large),gender,birthday,location';
        $userInfoUrl = "https://graph.facebook.com/v15.0/me?fields={$fields}&access_token={$accessToken}";

        // Make GET request
        $ch = curl_init($userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

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

        // Extract picture URL from nested structure
        $pictureUrl = null;
        if (isset($userProfile['picture']['data']['url'])) {
            $pictureUrl = $userProfile['picture']['data']['url'];
        }

        // Format profile data to our standard format
        return [
            'id' => $userProfile['id'],
            'email' => $userProfile['email'] ?? null,
            'name' => $userProfile['name'] ?? null,
            'first_name' => $userProfile['first_name'] ?? null,
            'last_name' => $userProfile['last_name'] ?? null,
            'picture' => $pictureUrl,
            'gender' => $userProfile['gender'] ?? null,
            'birthday' => $userProfile['birthday'] ?? null,
            'location' => isset($userProfile['location']['name']) ?
                          $userProfile['location']['name'] : null,
            'verified_email' => !empty($userProfile['email']), // Facebook verifies emails
            'raw' => $userProfile
        ];
    }

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     * For OAuth providers like Facebook, we typically can't refresh tokens directly,
     * but instead need to generate new ones through the normal flow.
     *
     * @param string $refreshToken Current refresh token
     * @param array $sessionData Session data associated with the refresh token
     * @return array|null New token pair or null if invalid
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        try {
            // For Facebook, we don't have a direct way to refresh tokens without user interaction
            // Instead, we'll just generate new tokens based on the session data

            // If the session contains the necessary user data
            if (isset($sessionData['user']) && !empty($sessionData['user']['uuid'])) {
                $userData = $sessionData['user'];

                // Generate new tokens using standard method from parent
                $accessTokenLifetime = (int)config('session.access_token_lifetime', 3600);
                $refreshTokenLifetime = (int)config('session.refresh_token_lifetime', 604800);

                return $this->generateTokens(
                    $userData,
                    $accessTokenLifetime,
                    $refreshTokenLifetime
                );
            }

            $this->lastError = "Insufficient session data for token refresh";
            return null;
        } catch (\Exception $e) {
            $this->lastError = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Verify a token from a native mobile SDK
     *
     * @param string $accessToken Access token from Facebook Login SDK
     * @return array|null User data if verified, null otherwise
     */
    public function verifyNativeToken(string $accessToken): ?array
    {
        // Validate configuration
        if (empty($this->appId) || empty($this->appSecret)) {
            $this->lastError = "Facebook OAuth configuration is missing";
            return null;
        }

        try {
            // Verify the access token with Facebook's API
            $tokenData = $this->verifyFacebookAccessToken($accessToken);

            if (!$tokenData || !isset($tokenData['is_valid']) || !$tokenData['is_valid']) {
                $this->lastError = "Invalid Facebook access token";
                return null;
            }

            // Check if the token was issued for our app
            if (isset($tokenData['app_id']) && $tokenData['app_id'] !== $this->appId) {
                $this->lastError = "Token was not issued for this application";
                return null;
            }

            // Get user profile with the access token
            $userProfile = $this->getUserProfile($accessToken);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile";
                return null;
            }

            // Find or create user from Facebook data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "Facebook token verification error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Verify Facebook access token with Facebook's API
     *
     * @param string $accessToken Access token from Facebook
     * @return array|null Token data if verified, null otherwise
     */
    private function verifyFacebookAccessToken(string $accessToken): ?array
    {
        // Facebook's debug token endpoint
        $debugTokenUrl = "https://graph.facebook.com/debug_token";
        $params = [
            'input_token' => $accessToken,
            'access_token' => $this->appId . '|' . $this->appSecret // App access token
        ];

        // Make the request to Facebook
        $ch = curl_init($debugTokenUrl . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: $error");
        }

        // Parse JSON response
        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['data'])) {
            throw new \Exception("Invalid debug token response: $response");
        }

        return $data['data'];
    }
}
