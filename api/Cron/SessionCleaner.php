<?php

namespace Glueful\Cron;

use Glueful\Database\Connection;

// @schedule:  0 0 * * *
// This job runs Daily at midnight

class SessionCleaner
{
    private array $stats = [
        'expired_access' => 0,
        'expired_refresh' => 0,
        'old_revoked' => 0,
        'errors' => []
    ];

    private static Connection $connection;

    public function __construct()
    {
        self::$connection = new Connection();
    }

    public function cleanExpiredAccessTokens(): void
    {
        try {
            $affected = self::$connection->table('auth_sessions')
                ->where('status', 'active')
                ->where('access_expires_at', '<', date('Y-m-d H:i:s'))
                ->delete();

            $this->stats['expired_access'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired access tokens: " . $e->getMessage();
        }
    }

    public function cleanExpiredRefreshTokens(): void
    {
        try {
            $affected = self::$connection->table('auth_sessions')
                ->where('status', 'active')
                ->where('refresh_expires_at', '<', date('Y-m-d H:i:s'))
                ->delete();

            $this->stats['expired_refresh'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired refresh tokens: " . $e->getMessage();
        }
    }

    public function cleanOldRevokedSessions(): void
    {
        try {
            // Get configurable retention period, default to 30 days
            $retentionDays = config('session.cleanup.revoked_retention_days', 30);
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            $affected = self::$connection->table('auth_sessions')
                ->where('status', 'revoked')
                ->where('updated_at', '<', $cutoffDate)
                ->delete();

            $this->stats['old_revoked'] = $affected;
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

        $logFile = config('app.paths.logs') . 'session-cleanup.log';
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

    public function handle(array $parameters = []): mixed
    {
        $this->run();
        return $this->stats;
    }
}

// chmod +x /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# Run every hour
// 0 * * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run every 6 hours
// 0 */6 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php

# OR run once daily at midnight
// 0 0 * * * /usr/bin/php /Users/michaeltawiahsowah/Sites/localhost/glueful/cron/clean-sessions.php
