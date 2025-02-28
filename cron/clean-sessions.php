<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

// @schedule:  0 0 * * * 
// This job runs every 6 hours

class SessionCleaner
{
    private \PDO $db;
    private array $stats = [
        'expired_access' => 0,
        'expired_refresh' => 0,
        'old_revoked' => 0,
        'errors' => []
    ];

    private static Connection $connection;
    private static QueryBuilder $queryBuilder;

    public function __construct() {
        self::$connection = new Connection();
        self::$queryBuilder = new QueryBuilder(self::$connection->getPDO(), self::$connection->getDriver());
    }

    public function cleanExpiredAccessTokens(): void
    {
        try {

            $affected = self::$queryBuilder->delete(
                'auth_sessions',
                [
                    'status' => 'active',
                    'access_expires_at < NOW()' => null
                ],
                false // Use soft delete if enabled
            );
            
            $this->stats['expired_access'] = $affected ? 1 : 0;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired access tokens: " . $e->getMessage();
        }
    }

    public function cleanExpiredRefreshTokens(): void
    {
        try {
            
            $affected = self::$queryBuilder->delete(
                'auth_sessions',
                [
                    'status' => 'active',
                    'refresh_expires_at < NOW()' => null
                ],
                false // Use real delete instead of soft delete for cleanup
            );
            
            $this->stats['expired_refresh'] = $affected ? 1 : 0;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired refresh tokens: " . $e->getMessage();
        }
    }

    public function cleanOldRevokedSessions(): void
    {
        try {
            $affected = self::$queryBuilder->delete(
                'auth_sessions',
                [
                   'status' => 'revoked',
                    'updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)' => null
                ],
                false // Use real delete instead of soft delete for cleanup
            );
            
            $this->stats['expired_refresh'] = $affected ? 1 : 0;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean old revoked sessions: " . $e->getMessage();
        }
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Session cleanup completed:\n" .
            "- Expired access tokens removed: %d\n" .
            "- Expired refresh tokens removed: %d\n" .
            "- Old revoked sessions removed: %d\n",
            $timestamp,
            $this->stats['expired_access'],
            $this->stats['expired_refresh'],
            $this->stats['old_revoked']
        );

        if (!empty($this->stats['errors'])) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = __DIR__ . '/../storage/logs/session-cleanup.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    public function run(): void
    {
        $this->cleanExpiredAccessTokens();
        $this->cleanExpiredRefreshTokens();
        $this->cleanOldRevokedSessions();
        $this->logResults();
    }
}

// Run the cleaner
$cleaner = new SessionCleaner();
$cleaner->run();

// chmod +x /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# Run every hour
// 0 * * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run every 6 hours
// 0 */6 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run once daily at midnight
// 0 0 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php