<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Security\RandomStringGenerator;

/**
 * Key Generation Command
 *
 * Generates secure encryption keys for the framework
 */

class KeyGenerateCommand extends Command
{
    public function getName(): string
    {
        return 'key:generate';
    }

    public function getDescription(): string
    {
        return 'Generate secure encryption keys for the framework';
    }

    public function getHelp(): string
    {
            return <<<HELP
    Generate secure encryption keys:

    Usage:
    php glueful key:generate [options]

    Options:
    --jwt        Generate JWT secret only
    --force      Overwrite existing keys
    --show       Display generated keys (insecure)

    Examples:
    php glueful key:generate
    php glueful key:generate --jwt
    php glueful key:generate --force
    HELP;
    }

    public function execute(array $args = []): int
    {
        if (isset($args[0]) && in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $jwtOnly = in_array('--jwt', $args);
        $force = in_array('--force', $args);
        $show = in_array('--show', $args);

        $envPath = dirname(__DIR__, 3) . '/.env';
        if (!file_exists($envPath)) {
            $this->error('.env file not found. Copy .env.example first.');
            return 1;
        }

        $envContent = file_get_contents($envPath);
        $updated = false;

        // Generate JWT Key
        if (!$jwtOnly || in_array('--jwt', $args)) {
            $jwtKey = RandomStringGenerator::generateHex(64); // 64 characters for JWT

            if (preg_match('/^JWT_KEY=(.*)$/m', $envContent, $matches)) {
                if ($matches[1] && $matches[1] !== 'your-secure-jwt-key-here' && !$force) {
                    $this->warning('JWT_KEY already exists. Use --force to overwrite.');
                } else {
                    $envContent = preg_replace('/^JWT_KEY=.*$/m', "JWT_KEY=$jwtKey", $envContent);
                    $updated = true;
                    $this->success('JWT_KEY generated');
                    if ($show) {
                        $this->line("JWT_KEY=$jwtKey");
                    }
                }
            } else {
                $envContent .= "\nJWT_KEY=$jwtKey\n";
                $updated = true;
                $this->success('JWT_KEY added');
                if ($show) {
                    $this->line("JWT_KEY=$jwtKey");
                }
            }
        }

        // Generate App Key (if not JWT only)
        if (!$jwtOnly) {
            $appKey = RandomStringGenerator::generateHex(32); // 32 characters for app key

            if (preg_match('/^APP_KEY=(.*)$/m', $envContent, $matches)) {
                if ($matches[1] && $matches[1] !== 'generate-secure-32-char-key-here' && !$force) {
                    $this->warning('APP_KEY already exists. Use --force to overwrite.');
                } else {
                    $envContent = preg_replace('/^APP_KEY=.*$/m', "APP_KEY=$appKey", $envContent);
                    $updated = true;
                    $this->success('APP_KEY generated');
                    if ($show) {
                        $this->line("APP_KEY=$appKey");
                    }
                }
            } else {
                $envContent .= "\nAPP_KEY=$appKey\n";
                $updated = true;
                $this->success('APP_KEY added');
                if ($show) {
                    $this->line("APP_KEY=$appKey");
                }
            }
        }

        if ($updated) {
            file_put_contents($envPath, $envContent);
            $this->success('Keys written to .env file');
            if (!$show) {
                $this->info('Keys have been generated and saved securely.');
                $this->warning('Keep your .env file secure and never commit it to version control.');
            }
            return 0;
        } else {
            $this->info('No keys were generated. Use --force to overwrite existing keys.');
            return 0;
        }
    }
}
