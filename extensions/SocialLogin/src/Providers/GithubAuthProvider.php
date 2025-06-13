<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Glueful\Extensions\SocialLogin\Providers\AbstractSocialProvider;

/**
 * GitHub Authentication Provider
 *
 * Handles GitHub OAuth authentication flow and user management.
 *
 * @package Glueful\Extensions\SocialLogin\Providers
 */
class GithubAuthProvider extends AbstractSocialProvider
{
    /** @var string Client ID from GitHub OAuth Apps */
    private string $clientId;

    /** @var string Client secret from GitHub OAuth Apps */
    private string $clientSecret;

    /** @var string Redirect URI for GitHub OAuth callback */
    private string $redirectUri;

    /** @var array OAuth scopes requested */
    private array $scopes = ['user:email', 'read:user'];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Set provider name
        $this->providerName = 'github';

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
        if (!is_array($config) || !isset($config['github']) || !is_array($config['github'])) {
            $config = [
                'github' => []
            ];
        }

        $this->clientId = !empty($config['github']['client_id']) ?
                          $config['github']['client_id'] :
                          (getenv('GITHUB_CLIENT_ID') ?: '');

        $this->clientSecret = !empty($config['github']['client_secret']) ?
                              $config['github']['client_secret'] :
                              (getenv('GITHUB_CLIENT_SECRET') ?: '');

        $this->redirectUri = !empty($config['github']['redirect_uri']) ?
                             $config['github']['redirect_uri'] :
                             (getenv('GITHUB_REDIRECT_URI') ?: $this->getDefaultRedirectUri());
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

        return $baseUrl . '/auth/social/github/callback';
    }

    /**
     * Check if request is a GitHub OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    protected function isOAuthCallback(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/github/callback') !== false &&
               $request->query->has('code');
    }

    /**
     * Check if request is to initialize GitHub OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    protected function isOAuthInitRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        return strpos($path, '/auth/social/github') !== false &&
               !strpos($path, '/callback');
    }

    /**
     * Handle GitHub OAuth callback
     *
     * Process callback from GitHub, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    protected function handleCallback(Request $request): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            $this->lastError = "GitHub OAuth configuration is missing";
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

            // If email is not public, fetch email separately
            if (empty($userProfile['email'])) {
                $emails = $this->getUserEmails($tokenData['access_token']);
                if (!empty($emails)) {
                    // Find primary and verified email
                    foreach ($emails as $email) {
                        if ($email['primary'] && $email['verified']) {
                            $userProfile['email'] = $email['email'];
                            $userProfile['verified_email'] = true;
                            break;
                        }
                    }

                    // If no primary+verified found, use the first verified
                    if (empty($userProfile['email'])) {
                        foreach ($emails as $email) {
                            if ($email['verified']) {
                                $userProfile['email'] = $email['email'];
                                $userProfile['verified_email'] = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Find or create user from GitHub data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "GitHub OAuth error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Initiate GitHub OAuth flow
     *
     * Generate authorization URL and redirect user to GitHub.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    protected function initiateOAuthFlow(Request $request): void
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException("GitHub OAuth configuration is missing");
        }

        // Generate state token to prevent CSRF
        $state = bin2hex(random_bytes(16));

        // Store state in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['github_oauth_state'] = $state;

        // Build authorization URL
        $authUrl = 'https://github.com/login/oauth/authorize';
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => implode(' ', $this->scopes)
        ];

        $authUrl .= '?' . http_build_query($params);

        // Redirect to GitHub
        $response = new RedirectResponse($authUrl);
        $response->send();
        exit;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code from GitHub
     * @return array Token data
     */
    private function exchangeCodeForToken(string $code): array
    {
        // Token endpoint
        $tokenUrl = 'https://github.com/login/oauth/access_token';

        // Request parameters
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ];

        // Make POST request to token endpoint
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]);

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
     * Get user profile from GitHub
     *
     * @param string $accessToken Access token from GitHub
     * @return array User profile data
     */
    private function getUserProfile(string $accessToken): array
    {
        // GitHub user info endpoint
        $userInfoUrl = 'https://api.github.com/user';

        // Make GET request with access token
        $ch = curl_init($userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $accessToken,
            'User-Agent: Glueful/SocialLogin',
            'Accept: application/json'
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
            'id' => (string)$userProfile['id'],
            'email' => $userProfile['email'] ?? null,
            'name' => $userProfile['name'] ?? null,
            'first_name' => null, // GitHub doesn't provide first/last name separately
            'last_name' => null,
            'picture' => $userProfile['avatar_url'] ?? null,
            'login' => $userProfile['login'] ?? null, // GitHub username
            'bio' => $userProfile['bio'] ?? null,
            'location' => $userProfile['location'] ?? null,
            'company' => $userProfile['company'] ?? null,
            'blog' => $userProfile['blog'] ?? null,
            'verified_email' => !empty($userProfile['email']), // Public email is verified
            'raw' => $userProfile
        ];
    }

    /**
     * Get user emails from GitHub
     *
     * GitHub may not provide email in the user profile if it's private,
     * so we need to fetch it separately with the user:email scope.
     *
     * @param string $accessToken Access token from GitHub
     * @return array List of user emails
     */
    private function getUserEmails(string $accessToken): array
    {
        // GitHub emails endpoint
        $emailsUrl = 'https://api.github.com/user/emails';

        // Make GET request with access token
        $ch = curl_init($emailsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $accessToken,
            'User-Agent: Glueful/SocialLogin',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: $error");
        }

        // Parse JSON response
        $emails = json_decode($response, true);

        if (!is_array($emails)) {
            throw new \Exception("Invalid emails response: $response");
        }

        return $emails;
    }

    /**
     * Generate username from GitHub data
     *
     * Overrides the parent method to use GitHub-specific fields.
     *
     * @param array $socialData Social provider data
     * @return string Generated username
     */
    protected function generateUsername(array $socialData): string
    {
        // Use GitHub login if available
        if (!empty($socialData['login'])) {
            $base = strtolower($socialData['login']);

            // Check if username exists
            $existing = $this->userRepository->findByUsername($base);

            if (!$existing) {
                return $base;
            }

            // Username exists, add a random suffix
            return $base . rand(100, 999);
        }

        // Fall back to parent implementation
        return parent::generateUsername($socialData);
    }

    /**
     * Verify a token from a native mobile SDK
     *
     * @param string $accessToken Access token from GitHub OAuth
     * @return array|null User data if verified, null otherwise
     */
    public function verifyNativeToken(string $accessToken): ?array
    {
        // Validate configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            $this->lastError = "GitHub OAuth configuration is missing";
            return null;
        }

        try {
            // Verify the access token by attempting to get user data
            $userProfile = $this->getUserProfile($accessToken);

            if (!isset($userProfile['id'])) {
                $this->lastError = "Failed to get user profile with provided token";
                return null;
            }

            // If email is not public, fetch email separately
            if (empty($userProfile['email'])) {
                $emails = $this->getUserEmails($accessToken);
                if (!empty($emails)) {
                    // Find primary and verified email
                    foreach ($emails as $email) {
                        if ($email['primary'] && $email['verified']) {
                            $userProfile['email'] = $email['email'];
                            $userProfile['verified_email'] = true;
                            break;
                        }
                    }

                    // If no primary+verified found, use the first verified
                    if (empty($userProfile['email'])) {
                        foreach ($emails as $email) {
                            if ($email['verified']) {
                                $userProfile['email'] = $email['email'];
                                $userProfile['verified_email'] = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Find or create user from GitHub data
            return $this->findOrCreateUser($userProfile);
        } catch (\Exception $e) {
            $this->lastError = "GitHub token verification error: " . $e->getMessage();
            return null;
        }
    }
}
