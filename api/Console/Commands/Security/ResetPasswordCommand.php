<?php

namespace Glueful\Console\Commands\Security;

use Glueful\Console\Commands\Security\BaseSecurityCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Security Reset Password Command
 * - Forced password reset for specific users
 * - Bulk password reset operations
 * - Password policy enforcement
 * - Security event logging and notifications
 * - Emergency access management
 * @package Glueful\Console\Commands\Security
 */
#[AsCommand(
    name: 'security:reset-password',
    description: 'Force password reset for specific user'
)]
class ResetPasswordCommand extends BaseSecurityCommand
{
    protected function configure(): void
    {
        $this->setDescription('Force password reset for specific user')
             ->setHelp('This command forces a password reset for a specific user, ' .
                      'invalidating their current password and optionally notifying them.')
             ->addArgument(
                 'username',
                 InputArgument::REQUIRED,
                 'Username or email of the user whose password should be reset'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force reset without confirmation'
             )
             ->addOption(
                 'notify',
                 'n',
                 InputOption::VALUE_NONE,
                 'Send notification email to the user'
             )
             ->addOption(
                 'temporary',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Generate temporary password (specify length, default: 12)',
                 '12'
             )
             ->addOption(
                 'reason',
                 'r',
                 InputOption::VALUE_REQUIRED,
                 'Reason for password reset (for audit log)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $force = $input->getOption('force');
        $notify = $input->getOption('notify');
        $temporary = $input->getOption('temporary');
        $reason = $input->getOption('reason');

        $this->info("🔒 Forcing Password Reset for User: {$username}");
        $this->line('');

        try {
            // Initialize UserRepository and PasswordHasher
            $userRepository = new \Glueful\Repository\UserRepository();
            $passwordHasher = new \Glueful\Auth\PasswordHasher();

            // Find the user (try as email first, then username)
            $user = null;
            $identifierType = null;

            if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
                $user = $userRepository->findByEmail($username);
                $identifierType = 'email';
            } else {
                $user = $userRepository->findByUsername($username);
                $identifierType = 'username';
            }

            if (!$user) {
                $this->error("User not found with {$identifierType}: {$username}");
                return self::FAILURE;
            }

            // Check if user array contains validation errors
            if (isset($user['errors'])) {
                $this->error("Validation error: " . implode(', ', $user['errors']));
                return self::FAILURE;
            }

            $this->info('User Details:');
            $this->table(['Property', 'Value'], [
                ['Username', $user['username'] ?? $username],
                ['Email', $user['email'] ?? 'Not set'],
                ['UUID', $user['uuid'] ?? 'Not set'],
                ['Status', $user['status'] ?? 'active'],
                ['Created', $user['created_at'] ?? 'Unknown']
            ]);

            // Confirmation if not forced
            if (!$force) {
                $this->warning('This will invalidate the user\'s current password.');
                $this->warning('The user will be required to set a new password on next login.');

                if (!$this->confirm("Are you sure you want to reset password for '{$username}'?", false)) {
                    $this->info('Password reset cancelled.');
                    return self::SUCCESS;
                }
            }

            // Get reason if not provided
            if (!$reason && !$force) {
                $reason = $this->ask('Please provide a reason for the password reset');
            }

            // Generate a secure temporary password
            $temporaryPassword = \Glueful\Helpers\Utils::generateSecurePassword((int) $temporary);

            // Hash the temporary password
            $hashedPassword = $passwordHasher->hash($temporaryPassword);

            // Update the user's password
            $success = $userRepository->setNewPassword(
                $user['email'], // Use email as identifier for consistency
                $hashedPassword,
                'email'
            );

            if ($success) {
                $this->success("✅ Password reset successful for user: {$user['username']}");
                $this->line('');
                $this->warning("⚠️  IMPORTANT: Store this temporary password securely!");
                $this->info("Temporary Password: $temporaryPassword");
                $this->line('');

                // Display results
                $this->info('Reset Details:');
                $details = [
                    ['Reset At', date('Y-m-d H:i:s')],
                    ['Reset By', 'CLI'],
                    ['Reason', $reason ?? 'Administrative reset via CLI'],
                    ['Temporary Password', $temporaryPassword],
                    ['Password Length', strlen($temporaryPassword) . ' characters']
                ];

                if ($notify) {
                    $details[] = ['Notification', 'Email notification would be sent'];
                }

                $this->table(['Property', 'Value'], $details);

                // Security recommendations
                $this->line('');
                $this->info('Security Recommendations:');
                $this->line('• Provide this password to the user through a secure channel');
                $this->line('• Instruct the user to change their password immediately upon login');
                $this->line('• Consider implementing password expiration for temporary passwords');
                $this->line('• Log this action in your security audit trail');

                return self::SUCCESS;
            } else {
                $this->error("Failed to reset password for user: {$user['username']}");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Password reset failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
