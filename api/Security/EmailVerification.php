<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Cache\CacheEngine;
use Glueful\Extensions\EmailNotification\EmailNotificationProvider;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use Glueful\{APIEngine};

/**
 * Email Verification System
 *
 * Handles OTP generation, verification, and rate limiting for email verification.
 * Includes protection against brute force and spam attempts.
 */
class EmailVerification
{
    /** @var int Length of generated OTP codes */
    public const OTP_LENGTH = 6;

    /** @var int OTP validity period in minutes */
    public const OTP_EXPIRY_MINUTES = 15;

    /** @var string Cache prefix for OTP storage */
    private const OTP_PREFIX = 'email_verification:';

    /** @var string Cache prefix for failed attempts */
    private const ATTEMPTS_PREFIX = 'email_verification_attempts:';

    /** @var int Maximum failed attempts before blocking */
    private const MAX_ATTEMPTS = 3;

    /** @var int Cooldown period in minutes after max attempts */
    private const COOLDOWN_MINUTES = 30;

    /** @var int Maximum verification requests per day */
    private const MAX_DAILY_REQUESTS = 10;

    /** @var string Cache prefix for daily request tracking */
    private const REQUESTS_PREFIX = 'email_verification_requests:';

    /** @var NotificationService Notification service instance */
    private NotificationService $notificationService;

    /** @var EmailNotificationProvider Email notification provider */
    private EmailNotificationProvider $emailProvider;

    /**
     * Constructor
     *
     * Initializes cache engine for OTP storage and notification service.
     */
    public function __construct()
    {
        // Initialize CacheEngine with Redis driver
        CacheEngine::initialize('Glueful:', config('cache.default'));

        // Create the channel manager
        $channelManager = new \Glueful\Notifications\Services\ChannelManager();

        // Create the notification dispatcher
        $dispatcher = new \Glueful\Notifications\Services\NotificationDispatcher($channelManager);

        // Initialize EmailNotificationProvider
        $this->emailProvider = new EmailNotificationProvider();
        $this->emailProvider->initialize();

        // Register the email provider with the channel manager directly
        $this->emailProvider->register($channelManager);

        // Initialize NotificationService with required dispatcher and repository
        $notificationRepository = new \Glueful\Repository\NotificationRepository();
        $this->notificationService = new NotificationService($dispatcher, $notificationRepository);
    }

    /**
     * Generate OTP code
     *
     * @return string Numeric OTP code
     */
    public function generateOTP(): string
    {
        return OTP::generateNumeric(self::OTP_LENGTH);
    }

    /**
     * Send verification email
     *
     * Handles rate limiting and sends OTP via notification system.
     *
     * @param string $email Recipient email address
     * @param string $otp Generated OTP code
     * @return array Operation result with status and message
     */
    public function sendVerificationEmail(string $email, string $otp): array
    {
        try {
            // Check if EmailNotification extension is enabled
            $extensionManager = new \Glueful\Helpers\ExtensionsManager();
            if (!$extensionManager->isExtensionEnabled('EmailNotification')) {
                error_log("EmailNotification extension is not enabled");
                return [
                    'success' => false,
                    'message' => 'Email notifications are not configured in the system. ' .
                        'Please contact the administrator.',
                    'error_code' => 'email_extension_disabled'
                ];
            }

            if ($this->isRateLimited($email)) {
                error_log("Rate limit exceeded for email: $email");
                return [
                    'success' => false,
                    'message' => 'Too many failed attempts. Please try again later.',
                    'error_code' => 'rate_limited'
                ];
            }

            // Increment daily request counter
            if (!$this->incrementDailyRequests($email)) {
                error_log("Daily limit exceeded for email: $email");
                return [
                    'success' => false,
                    'message' => 'Daily verification limit reached. Please try again tomorrow.',
                    'error_code' => 'daily_limit_exceeded'
                ];
            }

            // Store OTP in Redis before sending email
            $hashedOTP = OTP::hashOTP($otp);
            $stored = $this->storeOTP($email, $hashedOTP);

            if (!$stored) {
                error_log("Failed to store OTP in cache for email: $email");
                return [
                    'success' => false,
                    'message' => 'Failed to initialize verification process. Please try again.',
                    'error_code' => 'cache_failure'
                ];
            }

            // Create a temporary notifiable for this email
            $notifiable = new class ($email) implements Notifiable {
                private string $email;

                public function __construct(string $email)
                {
                    $this->email = $email;
                }

                public function routeNotificationFor(string $channel): ?string
                {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }

                public function getNotifiableId(): string
                {
                    return md5($this->email);
                }

                public function getNotifiableType(): string
                {
                    return 'verification_recipient';
                }

                public function shouldReceiveNotification(string $notificationType, string $channel): bool
                {
                    return $channel === 'email';
                }

                public function getNotificationPreferences(): array
                {
                    return ['email' => true];
                }
            };

            // Send via notification system using the verification template
            $result = $this->notificationService->send(
                'email_verification',
                $notifiable,
                'Verify your email address',
                [
                    'otp' => $otp,
                    'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                    'app_name' => config('app.name', 'Glueful'),
                    'current_year' => date('Y'),
                    'message' => 'Your verification code is: ' . $otp, // Add a message for templates that use it
                    'subject' => 'Verify your email address', // Update subject to match recent edits
                    'title' => 'Email Verification', // Fixed title explicitly for header
                    'template_data' => [  // Explicitly provide template data in the correct format
                        'otp' => $otp,
                        'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                        'app_name' => config('app.name', 'Glueful'),
                        'current_year' => date('Y'),
                        'subject' => 'Verify your email address', // Update subject to match recent edits
                        'title' => 'Email Verification'    // Add title to template data
                    ],
                    'type' => 'email_verification', // Explicitly set the notification type
                    'template_name' => 'verification' // Set template name directly in data as well
                ],
                [
                    'channels' => ['email'],
                    'template_name' => 'verification'
                ]
            );

            // Parse the result using the NotificationResultParser
            $parsedResult = \Glueful\Notifications\Utils\NotificationResultParser::parseEmailResult(
                $result,
                [
                    'email' => $email,
                    'expires_in' => self::OTP_EXPIRY_MINUTES * 60
                ],
                'Verification code sent successfully'
            );
            // Log the verification email attempt to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $severity = $parsedResult['success']
                        ? \Glueful\Logging\AuditEvent::SEVERITY_INFO
                        : \Glueful\Logging\AuditEvent::SEVERITY_WARNING;

                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_code_sent',
                        $severity,
                        [
                            'email' => $email,
                            'success' => $parsedResult['success'],
                            'error_code' => $parsedResult['error_code'] ?? null,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails - don't disrupt main flow
                    error_log("Failed to log email verification to audit system: " . $e->getMessage());
                }
            }

            return $parsedResult;
        } catch (\Exception $e) {
            $errorResult = [
                'success' => false,
                'message' => 'An error occurred during email verification: ' . $e->getMessage(),
                'error_code' => 'system_error'
            ];

            // Log the error to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_error',
                        \Glueful\Logging\AuditEvent::SEVERITY_ERROR,
                        [
                            'email' => $email,
                            'error' => $e->getMessage(),
                            'error_code' => 'system_error',
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $auditError) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log email verification error to audit system: " . $auditError->getMessage());
                }
            }

            return $errorResult;
        }
    }

    /**
     * Store OTP in cache
     *
     * @param string $email User email
     * @param string $hashedOTP Hashed OTP value
     * @return bool True if stored successfully
     */
    private function storeOTP(string $email, string $hashedOTP): bool
    {
        try {
            // Ensure cache engine is initialized
            if (!CacheEngine::isInitialized() || !CacheEngine::isEnabled()) {
                error_log("Cache not ready. Reinitializing...");
                CacheEngine::initialize('Glueful:', config('cache.default'));

                // Double-check if initialization worked
                if (!CacheEngine::isEnabled()) {
                    error_log("Failed to initialize cache system after retry");
                    return false;
                }
            }

            $key = self::OTP_PREFIX . $email;
            $data = [
                'otp' => $hashedOTP,
                'timestamp' => time()
            ];

            // Try to store the data
            $result = CacheEngine::set($key, $data, self::OTP_EXPIRY_MINUTES * 60);

            // Debug what's happening with the cache operation
            if (!$result) {
                error_log("Failed to store OTP in cache. Key: $key, Driver: " . config('cache.default'));

                // Try with a shorter expiry as fallback
                $fallbackResult = CacheEngine::set($key, $data, 900); // 15 minutes in seconds
                if ($fallbackResult) {
                    error_log("Successfully stored OTP using fallback method for email: $email");
                    return true;
                }

                // If still failing, try one last approach with direct cache driver access
                if (defined('CACHE_ENGINE')) {
                    error_log("Attempting alternative storage method for OTP");
                    return $this->storeOTPAlternative($key, $data);
                }
            }

            return $result;
        } catch (\Exception $e) {
            error_log("Exception storing OTP in cache: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Alternative OTP storage method
     *
     * Used as a fallback when primary cache storage fails
     *
     * @param string $key Cache key
     * @param array $data OTP data to store
     * @return bool True if stored successfully
     */
    private function storeOTPAlternative(string $key, array $data): bool
    {
        try {
            // Try to use file-based storage as last resort
            $storagePath = config('paths.storage_path', __DIR__ . '/../../storage') . '/cache/';
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filePath = $storagePath . md5($key) . '.tmp';
            $data['expiry'] = time() + self::OTP_EXPIRY_MINUTES * 60;
            $success = file_put_contents($filePath, json_encode($data)) !== false;

            if ($success) {
                error_log("Successfully stored OTP using file-based fallback");
            }

            return $success;
        } catch (\Exception $e) {
            error_log("Alternative OTP storage failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify provided OTP
     *
     * Validates OTP and handles failed attempts.
     *
     * @param string $email User email
     * @param string $providedOTP OTP to verify
     * @return bool True if OTP is valid
     */
    public function verifyOTP(string $email, string $providedOTP): bool
    {
        $key = self::OTP_PREFIX . $email;
        $stored = CacheEngine::get($key);

        if (!$stored || empty($stored['otp']) || empty($stored['timestamp'])) {
            $this->incrementAttempts($email);

            // Log the failed verification due to missing/expired OTP to audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_failed',
                        \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                        [
                            'email' => $email,
                            'reason' => 'expired_or_missing_otp',
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log OTP verification failure to audit system: " . $e->getMessage());
                }
            }

            return false;
        }

        $isValid = OTP::verifyHashedOTP($providedOTP, $stored['otp']);

        if ($isValid) {
            // Clear rate limiting on success
            $this->clearAttempts($email);
            CacheEngine::delete($key);

            // Log the successful verification to audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_success',
                        \Glueful\Logging\AuditEvent::SEVERITY_INFO,
                        [
                            'email' => $email,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log OTP verification success to audit system: " . $e->getMessage());
                }
            }

            return true;
        }

        // Increment failed attempts
        $this->incrementAttempts($email);

        // Log the failed verification due to invalid OTP to audit system
        if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
            try {
                $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                $auditLogger->audit(
                    \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                    'email_verification_failed',
                    \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                    [
                        'email' => $email,
                        'reason' => 'invalid_otp',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]
                );
            } catch (\Throwable $e) {
                // Silently fail if audit logging fails
                error_log("Failed to log OTP verification failure to audit system: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Check rate limiting status
     *
     * @param string $email User email
     * @return bool True if rate limited
     */
    private function isRateLimited(string $email): bool
    {
        $attempts = CacheEngine::get(self::ATTEMPTS_PREFIX . $email) ?? 0;
        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Track failed verification attempts
     *
     * @param string $email User email
     */
    private function incrementAttempts(string $email): void
    {
        $key = self::ATTEMPTS_PREFIX . $email;
        $attempts = (int)(CacheEngine::get($key) ?? 0) + 1;
        CacheEngine::set($key, $attempts, self::COOLDOWN_MINUTES * 60);

        if ($attempts >= self::MAX_ATTEMPTS) {
            error_log("Account temporary locked for email: $email");

            // Log the account lockout to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_account_locked',
                        \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                        [
                            'email' => $email,
                            'attempts' => $attempts,
                            'cooldown_minutes' => self::COOLDOWN_MINUTES,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log verification account lockout to audit system: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Reset failed attempts counter
     *
     * @param string $email User email
     */
    private function clearAttempts(string $email): void
    {
        CacheEngine::delete(self::ATTEMPTS_PREFIX . $email);
    }

    /**
     * Track daily verification requests
     *
     * @param string $email User email
     * @return bool False if daily limit exceeded
     */
    private function incrementDailyRequests(string $email): bool
    {
        $key = self::REQUESTS_PREFIX . $email . ':' . date('Y-m-d');
        $requests = (int)(CacheEngine::get($key) ?? 0) + 1;

        if ($requests > self::MAX_DAILY_REQUESTS) {
            // Log the daily limit exceeded to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'email_verification_daily_limit_exceeded',
                        \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                        [
                            'email' => $email,
                            'requests' => $requests,
                            'max_daily_requests' => self::MAX_DAILY_REQUESTS,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'date' => date('Y-m-d')
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log verification daily limit exceeded to audit system: " . $e->getMessage());
                }
            }

            return false;
        }

        // Set expiry to end of day
        $endOfDay = strtotime('tomorrow') - time();
        CacheEngine::set($key, $requests, $endOfDay);
        return true;
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool True if email format is valid
     */
    public function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Send password reset email
     *
     * Handles password reset flow with verification using notification system.
     *
     * @param string $email User email address
     * @return array Operation result with status
     */
    public static function sendPasswordResetEmail(string $email): array
    {
        try {
            $verifier = new self();

            // Check if EmailNotification extension is enabled
            $extensionManager = new \Glueful\Helpers\ExtensionsManager();
            if (!$extensionManager->isExtensionEnabled('EmailNotification')) {
                error_log("EmailNotification extension is not enabled for password reset");
                return [
                    'success' => false,
                    'message' => 'Password reset via email is not available. Please contact the administrator.',
                    'error_code' => 'email_extension_disabled'
                ];
            }

            if (!$verifier->isValidEmail($email)) {
                // Log the invalid email format to the audit system
                if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                    try {
                        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                        $auditLogger->audit(
                            \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                            'password_reset_invalid_email',
                            \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                            [
                                'email' => $email,
                                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]
                        );
                    } catch (\Throwable $e) {
                        // Silently fail if audit logging fails
                        error_log("Failed to log invalid email format to audit system: " . $e->getMessage());
                    }
                }

                return [
                    'success' => false,
                    'message' => 'Invalid email format',
                    'error_code' => 'invalid_email_format'
                ];
            }

            // Check if email exists in users table
            $userData = APIEngine::getData('users', 'list', ['email' => $email]);
            if (empty($userData)) {
                // Log the email not found to the audit system
                if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                    try {
                        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                        $auditLogger->audit(
                            \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                            'password_reset_email_not_found',
                            \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                            [
                                'email' => $email,
                                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]
                        );
                    } catch (\Throwable $e) {
                        // Silently fail if audit logging fails
                        error_log("Failed to log email not found to audit system: " . $e->getMessage());
                    }
                }

                return [
                    'success' => false,
                    'message' => 'Email address not found',
                    'error_code' => 'email_not_found'
                ];
            }

            // Check rate limiting
            if ($verifier->isRateLimited($email)) {
                // Log the rate limiting to the audit system
                if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                    try {
                        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                        $auditLogger->audit(
                            \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                            'password_reset_rate_limited',
                            \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                            [
                                'email' => $email,
                                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]
                        );
                    } catch (\Throwable $e) {
                        // Silently fail if audit logging fails
                        error_log("Failed to log password reset rate limiting to audit system: " . $e->getMessage());
                    }
                }

                return [
                    'success' => false,
                    'message' => 'Too many attempts. Please try again later.',
                    'error_code' => 'rate_limited'
                ];
            }

            // Generate OTP
            $otp = $verifier->generateOTP();

            // Store OTP
            $hashedOTP = OTP::hashOTP($otp);
            if (!$verifier->storeOTP($email, $hashedOTP)) {
                throw new \RuntimeException('Failed to initialize password reset');
            }

            // Create a temporary notifiable for this email
            $notifiable = new class ($email) implements Notifiable {
                private string $email;

                public function __construct(string $email)
                {
                    $this->email = $email;
                }

                public function routeNotificationFor(string $channel): ?string
                {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }

                public function getNotifiableId(): string
                {
                    return md5($this->email);
                }

                public function getNotifiableType(): string
                {
                    return 'password_reset_recipient';
                }

                public function shouldReceiveNotification(string $notificationType, string $channel): bool
                {
                    return $channel === 'email';
                }

                public function getNotificationPreferences(): array
                {
                    return ['email' => true];
                }
            };

            // Send via notification system using the password-reset template
            $result = $verifier->notificationService->send(
                'password_reset',
                $notifiable,
                'Password Reset Code',
                [
                    'name' => $userData[0]['first_name'] ?? 'User',
                    'otp' => $otp,
                    'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                    'app_name' => config('app.name', 'Glueful'),
                    'current_year' => date('Y'),
                    'message' => 'Your password reset code is: ' . $otp,
                    'template_data' => [  // Explicitly provide template data in the correct format
                        'name' => $userData[0]['first_name'] ?? 'User',
                        'otp' => $otp,
                        'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                        'app_name' => config('app.name', 'Glueful'),
                        'current_year' => date('Y'),
                    ]
                ],
                [
                    'channels' => ['email'],
                    'template_name' => 'password-reset'
                ]
            );

            // Use the NotificationResultParser to handle the result
            $parsedResult = \Glueful\Notifications\Utils\NotificationResultParser::parseEmailResult(
                $result,
                [
                    'email' => $email,
                    'expires_in' => self::OTP_EXPIRY_MINUTES * 60
                ],
                'Password reset code sent to your email'
            );

            // Log the password reset email attempt to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $severity = $parsedResult['success']
                        ? \Glueful\Logging\AuditEvent::SEVERITY_INFO
                        : \Glueful\Logging\AuditEvent::SEVERITY_WARNING;

                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'password_reset_code_sent',
                        $severity,
                        [
                            'email' => $email,
                            'success' => $parsedResult['success'],
                            'error_code' => $parsedResult['error_code'] ?? null,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'user_data' => isset($userData[0]['uuid']) ? ['uuid' => $userData[0]['uuid']] : null
                        ]
                    );
                } catch (\Throwable $e) {
                    // Silently fail if audit logging fails - don't disrupt main flow
                    error_log("Failed to log password reset to audit system: " . $e->getMessage());
                }
            }

            return $parsedResult;
        } catch (\Exception $e) {
            $errorResult = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'system_error'
            ];

            // Log the error to the audit system
            if (class_exists('\\Glueful\\Logging\\AuditLogger')) {
                try {
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
                    $auditLogger->audit(
                        \Glueful\Logging\AuditEvent::CATEGORY_SYSTEM,
                        'password_reset_error',
                        \Glueful\Logging\AuditEvent::SEVERITY_ERROR,
                        [
                            'email' => $email,
                            'error' => $e->getMessage(),
                            'error_code' => 'system_error',
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]
                    );
                } catch (\Throwable $auditError) {
                    // Silently fail if audit logging fails
                    error_log("Failed to log password reset error to audit system: " . $auditError->getMessage());
                }
            }

            return $errorResult;
        }
    }

    /**
     * Check if email provider is properly configured
     *
     * @return bool True if email provider is properly configured
     */
    public function isEmailProviderConfigured(): bool
    {
        try {
            // Delegate to EmailNotificationProvider's implementation
            return $this->emailProvider->isEmailProviderConfigured();
        } catch (\Exception $e) {
            error_log("Error checking email provider configuration: " . $e->getMessage());
            return false;
        }
    }
}
