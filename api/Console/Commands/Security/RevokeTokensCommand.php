<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Glueful\Cache\CacheStore;
use Glueful\Helpers\DatabaseConnectionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security Revoke Tokens Command
 * - Mass authentication token revocation
 * - Selective token revocation by user or criteria
 * - Session invalidation and cleanup
 * - Security event logging and notifications
 * - Emergency access control management
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:revoke-tokens',
    description: 'Revoke authentication tokens'
)]
class RevokeTokensCommand extends BaseSecurityCommand
{
    use DatabaseConnectionTrait;

    protected function configure(): void
    {
        $this->setDescription('Revoke authentication tokens')
             ->setHelp('This command revokes authentication tokens to immediately ' .
                      'terminate user sessions and require re-authentication.')
             ->addOption(
                 'user',
                 'u',
                 InputOption::VALUE_REQUIRED,
                 'Revoke tokens for specific user only'
             )
             ->addOption(
                 'all',
                 'a',
                 InputOption::VALUE_NONE,
                 'Revoke all tokens for all users (emergency use)'
             )
             ->addOption(
                 'type',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Token type to revoke (access, refresh, all)',
                 'all'
             )
             ->addOption(
                 'older-than',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Revoke tokens older than specified time (e.g., "7 days", "1 hour")'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force revocation without confirmation'
             )
             ->addOption(
                 'reason',
                 'r',
                 InputOption::VALUE_REQUIRED,
                 'Reason for token revocation (for audit log)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $input->getOption('user');
        $all = $input->getOption('all');
        $type = $input->getOption('type');
        $olderThan = $input->getOption('older-than');
        $force = $input->getOption('force');
        $reason = $input->getOption('reason');

        // Validate options
        if (!$user && !$all && !$olderThan) {
            $this->error('Please specify --user, --all, or --older-than option');
            return self::FAILURE;
        }

        if ($user && $all) {
            $this->error('Cannot specify both --user and --all options');
            return self::FAILURE;
        }

        $validTypes = ['access', 'refresh', 'all'];
        if (!in_array($type, $validTypes)) {
            $this->error("Invalid token type: {$type}");
            $this->info('Valid types: ' . implode(', ', $validTypes));
            return self::FAILURE;
        }

        $this->info('🔒 Revoking Authentication Tokens');
        $this->line('');

        try {
            // Import required classes (following original pattern)
            $queryBuilder = $this->getQueryBuilder();
            $cacheStore = $this->getService(CacheStore::class);
            $tokenManager = \Glueful\Auth\TokenManager::class;

            // Initialize cache engine if not already initialized
            $tokenManager::initialize();

            // Step 1: Get all active sessions from database
            $this->info('📊 Retrieving all active sessions...');
            $sessionsQuery = $queryBuilder->select(
                'auth_sessions',
                ['access_token', 'refresh_token', 'user_uuid', 'uuid']
            )->where(['status' => 'active']);

            if ($user) {
                // Find user UUID first
                $userRepo = new \Glueful\Repository\UserRepository();
                $userData = $userRepo->findByEmail($user) ?? $userRepo->findByUsername($user);
                if (!$userData) {
                    $this->error("User not found: {$user}");
                    return self::FAILURE;
                }
                $sessionsQuery->where(['user_uuid' => $userData['uuid']]);
            }

            if ($olderThan) {
                $timestamp = strtotime("-{$olderThan}");
                $sessionsQuery->where(['created_at' => ['<', date('Y-m-d H:i:s', $timestamp)]]);
            }

            $activeSessions = $sessionsQuery->get();
            $totalSessions = count($activeSessions);
            $this->info("Found {$totalSessions} active sessions to revoke");

            if ($totalSessions === 0) {
                $this->warning('No active sessions found to revoke');
                return self::SUCCESS;
            }

            // Confirmation if not forced
            if (!$force) {
                $this->line('');
                if ($all) {
                    $this->warning('⚠️ This will LOG OUT ALL USERS and require them to re-authenticate!');
                } else {
                    $this->warning('⚠️ This will invalidate active sessions and require re-authentication!');
                }

                if (!$this->confirm('Are you sure you want to proceed with token revocation?', false)) {
                    $this->info('Token revocation cancelled.');
                    return self::SUCCESS;
                }
            }

            // Get reason if not provided
            if (!$reason && !$force) {
                $reason = $this->ask('Please provide a reason for token revocation');
            }

            // Step 2: Revoke each session individually (following original pattern)
            $revokedCount = 0;
            $failedCount = 0;

            $this->info('🔄 Revoking individual sessions...');

            foreach ($activeSessions as $session) {
                try {
                    // Revoke the session (this handles database update and audit logging)
                    $result = $tokenManager::revokeSession($session['access_token']);

                    if ($result > 0) {
                        // Remove token mapping from cache
                        $tokenManager::removeTokenMapping($session['access_token']);
                        $revokedCount++;
                    } else {
                        $failedCount++;
                        $this->warning("Failed to revoke session: {$session['uuid']}");
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->warning("Error revoking session {$session['uuid']}: {$e->getMessage()}");
                }
            }

            // Step 3: Clear all token mappings from cache (bulk operation)
            $this->info('🧹 Clearing token cache mappings...');
            try {
                // Clear all token-related cache entries
                $cacheStore->flush();
                $this->info('✅ Token cache cleared successfully');
            } catch (\Exception $e) {
                $this->warning("Cache clearing failed: {$e->getMessage()}");
            }

            // Step 4: Log the bulk revocation event
            $logMessage = "Security: Bulk token revocation - Total: {$totalSessions}, " .
                         "Revoked: {$revokedCount}, Failed: {$failedCount}, " .
                         "Reason: " . ($reason ?? 'emergency_security_procedure');
            error_log($logMessage);

            // Step 5: Display results
            $this->line('');
            $this->info('📊 Token Revocation Summary');
            $this->line('══════════════════════════════');
            $this->info("Total sessions found: {$totalSessions}");
            $this->success("Successfully revoked: {$revokedCount}");

            if ($failedCount > 0) {
                $this->warning("Failed to revoke: {$failedCount}");
            }

            $this->line('');

            if ($revokedCount === $totalSessions) {
                $this->success('🎉 All authentication tokens have been successfully revoked');
                $this->info('All users will need to re-authenticate on their next request');
                return self::SUCCESS;
            } else {
                $this->warning('⚠️ Some token revocations failed - check logs for details');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Token revocation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
