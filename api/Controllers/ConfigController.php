<?php

declare(strict_types=1);

namespace Glueful\Controllers;

class ConfigController
{
    public function getConfigs(): array
    {
        $configPath = __DIR__ . '/../../config';
        $configFiles = array_diff(scandir($configPath), ['.', '..', 'schedule.php']);

        $groupedConfig = [];

        foreach ($configFiles as $file) {
            $filePath = $configPath . '/' . $file;

            if (pathinfo($file, PATHINFO_EXTENSION) === 'php' && file_exists($filePath)) {
                $config = require $filePath; // Load the config file

                // Only add if it's an array
                if (is_array($config)) {
                    $groupedConfig[] = [
                        'name' => pathinfo($file, PATHINFO_FILENAME),
                        'config' => $config,
                    ];
                } else {
                    error_log("Skipping invalid config file: $file");
                }
            }
        }

        return $groupedConfig;
    }

    public function getConfigByFile(string $filename): ?array
    {
        $configPath = __DIR__ . '/../../config';
        $filePath = $configPath . '/' . $filename;

        if (!file_exists($filePath)) {
            return null;
        }

        $config = require $filePath;
        return is_array($config) ? $config : null;
    }

    public function updateConfig(string $filename, array $data): bool
    {
        $configPath = __DIR__ . '/../../config';
        $filePath = $configPath . '/' . $filename;

        if (!file_exists($filePath)) {
            return false;
        }

        // Update config file
        $existingConfig = require $filePath;
        $newConfig = array_merge($existingConfig, $data);
        $configContent = "<?php\n\nreturn " . var_export($newConfig, true) . ";\n";
        file_put_contents($filePath, $configContent);

        // Update corresponding .env variables if they exist
        $this->updateEnvVariables($data);

        return true;
    }

    public function createConfig(string $filename, array $data): bool
    {
        $configPath = __DIR__ . '/../../config';
        $filePath = $configPath . '/' . $filename;

        // Don't overwrite existing config files
        if (file_exists($filePath)) {
            return false;
        }

        // Ensure filename has .php extension
        if (!str_ends_with($filename, '.php')) {
            $filename .= '.php';
            $filePath = $configPath . '/' . $filename;
        }

        // Create config content
        $configContent = "<?php\n\nreturn " . var_export($data, true) . ";\n";

        // Create config file
        if (!file_put_contents($filePath, $configContent)) {
            return false;
        }

        // Update corresponding .env variables if needed
        $this->updateEnvVariables($data);

        return true;
    }

    private function updateEnvVariables(array $data): void
    {
        $envPath = __DIR__ . '/../../.env';
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $updated = false;

        foreach ($data as $key => $value) {
            $envKey = $this->findEnvKeyForConfigValue($key);
            if ($envKey) {
                $lines = $this->updateEnvLine($lines, $envKey, $value);
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($envPath, implode("\n", $lines));
        }
    }

    private function findEnvKeyForConfigValue(string $key): string
    {
        // Map config keys to potential ENV keys
        // You might want to customize this mapping based on your needs
        return strtoupper(str_replace('.', '_', $key));
    }

    private function updateEnvLine(array $lines, string $key, $value): array
    {
        $newLine = $key . '=' . (is_string($value) ? '"' . $value . '"' : $value);

        foreach ($lines as $i => $line) {
            if (strpos($line, $key . '=') === 0) {
                $lines[$i] = $newLine;
                return $lines;
            }
        }

        $lines[] = $newLine;
        return $lines;
    }
}
