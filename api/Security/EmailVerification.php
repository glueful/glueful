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
        
        // Register the email provider with the dispatcher (not the service)
        $dispatcher->registerExtension($this->emailProvider);
        
        // Initialize NotificationService with required dispatcher
        $this->notificationService = new NotificationService($dispatcher);
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
     * @return bool True if email sent successfully
     */
    public function sendVerificationEmail(string $email, string $otp): bool
    {
        try {
            if ($this->isRateLimited($email)) {
                error_log("Rate limit exceeded for email: $email");
                return false;
            }

            // Increment daily request counter
            if (!$this->incrementDailyRequests($email)) {
                error_log("Daily limit exceeded for email: $email");
                return false;
            }

            // Store OTP in Redis before sending email
            $hashedOTP = OTP::hashOTP($otp);
            $stored = $this->storeOTP($email, $hashedOTP);

            if (!$stored) {
                error_log("Failed to store OTP in cache for email: $email");
                return false;
            }

            // Create a temporary notifiable for this email
            $notifiable = new class($email) implements Notifiable {
                private string $email;
                
                public function __construct(string $email) {
                    $this->email = $email;
                }
                
                public function routeNotificationFor(string $channel): ?string {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }
                
                public function getNotifiableId(): string {
                    return md5($this->email);
                }
                
                public function getNotifiableType(): string {
                    return 'verification_recipient';
                }
                
                public function shouldReceiveNotification(string $notificationType, string $channel): bool {
                    return $channel === 'email';
                }
                
                public function getNotificationPreferences(): array {
                    return ['email' => true];
                }
            };
            
            // Send via notification system using the verification template
            $result = $this->notificationService->send(
                'email_verification',
                $notifiable,
                'Email Verification Code',
                [
                    'otp' => $otp,
                    'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                    'app_name' => config('app.name', 'Glueful'),
                    'current_year' => date('Y')
                ],
                [
                    'channels' => ['email'],
                    'template_name' => 'verification'
                ]
            );
            
            // Check if the notification was sent successfully
            return is_array($result) && isset($result['success']) ? (bool)$result['success'] : false;
            
        } catch (\Exception $e) {
            error_log("Email verification failed: " . $e->getMessage());
            return false;
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
        $key = self::OTP_PREFIX . $email;
        return CacheEngine::set($key, [
            'otp' => $hashedOTP,
            'timestamp' => time()
        ], self::OTP_EXPIRY_MINUTES * 60);
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
            return false;
        }

        $isValid = OTP::verifyHashedOTP($providedOTP, $stored['otp']);
        
        if ($isValid) {
            // Clear rate limiting on success
            $this->clearAttempts($email);
            CacheEngine::delete($key);
            return true;
        }

        // Increment failed attempts
        $this->incrementAttempts($email);
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
            
            if (!$verifier->isValidEmail($email)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format'
                ];
            }

            // Check if email exists in users table
            $userData = APIEngine::getData('users', 'list', ['email' => $email]);
            if (empty($userData)) {
                return [
                    'success' => false,
                    'message' => 'Email address not found'
                ];
            }

            // Check rate limiting
            if ($verifier->isRateLimited($email)) {
                return [
                    'success' => false,
                    'message' => 'Too many attempts. Please try again later.'
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
            $notifiable = new class($email) implements Notifiable {
                private string $email;
                
                public function __construct(string $email) {
                    $this->email = $email;
                }
                
                public function routeNotificationFor(string $channel): ?string {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }
                
                public function getNotifiableId(): string {
                    return md5($this->email);
                }
                
                public function getNotifiableType(): string {
                    return 'password_reset_recipient';
                }
                
                public function shouldReceiveNotification(string $notificationType, string $channel): bool {
                    return $channel === 'email';
                }
                
                public function getNotificationPreferences(): array {
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
                    'current_year' => date('Y')
                ],
                [
                    'channels' => ['email'],
                    'template_name' => 'password-reset'
                ]
            );
            
            if (!$result) {
                throw new \RuntimeException('Failed to send password reset notification');
            }

            return [
                'success' => true,
                'message' => 'Password reset code sent to your email',
                'email' => $email,
                'expires_in' => self::OTP_EXPIRY_MINUTES * 60
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
