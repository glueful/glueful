<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use glueful\Api\Library\{MySQLQueryBuilder, QueryAction, Utils};

class SessionCleaner
{
    private \PDO $db;
    private array $stats = [
        'expired_access' => 0,
        'expired_refresh' => 0,
        'old_revoked' => 0,
        'errors' => []
    ];

    public function __construct()
    {
        $this->db = Utils::getMySQLConnection();
    }

    public function cleanExpiredAccessTokens(): void
    {
        try {
            $definition = [
                'table' => ['name' => 'auth_sessions'],
                'conditions' => [
                    'access_expires_at < NOW()' => null,
                    'status' => 'active'
                ]
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::DELETE, $definition);
            $stmt = $this->db->prepare($query['sql']);
            $stmt->execute($query['params'] ?? []);
            
            $this->stats['expired_access'] = $stmt->rowCount();
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired access tokens: " . $e->getMessage();
        }
    }

    public function cleanExpiredRefreshTokens(): void
    {
        try {
            $definition = [
                'table' => ['name' => 'auth_sessions'],
                'conditions' => [
                    'refresh_expires_at < NOW()' => null,
                    'status' => 'active'
                ]
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::DELETE, $definition);
            $stmt = $this->db->prepare($query['sql']);
            $stmt->execute($query['params'] ?? []);
            
            $this->stats['expired_refresh'] = $stmt->rowCount();
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean expired refresh tokens: " . $e->getMessage();
        }
    }

    public function cleanOldRevokedSessions(): void
    {
        try {
            $definition = [
                'table' => ['name' => 'auth_sessions'],
                'conditions' => [
                    'status' => 'revoked',
                    'updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)' => null
                ]
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::DELETE, $definition);
            $stmt = $this->db->prepare($query['sql']);
            $stmt->execute($query['params'] ?? []);
            
            $this->stats['old_revoked'] = $stmt->rowCount();
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