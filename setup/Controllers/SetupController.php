<?php

declare(strict_types=1);

namespace Glueful\Setup\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SetupController
{
    public function index(Request $request, ?string $step = null): Response
    {
        // Check if already setup
        if (file_exists(dirname(__DIR__, 2) . '/storage/setup.lock')) {
            return $this->redirect('/'); // Redirect to main app
        }

        // Handle form submission (POST request)
        if ($request->getMethod() === 'POST') {
            return $this->handleSetup($request);
        }

        // Handle GET request - show setup page
        $validSteps = ['welcome', 'database', 'admin', 'complete'];
        $currentStep = $step && in_array($step, $validSteps) ? $step : 'welcome';

        // Perform system check
        $systemCheck = $this->performSystemCheck();

        // Return single-page setup view with current step
        return $this->view('setup/index', [
            'systemCheck' => $systemCheck,
            'currentStep' => $currentStep,
            'validSteps' => $validSteps
        ]);
    }

    private function handleSetup(Request $request): Response
    {
        // Get form data from request
        $dbConfig = $request->request->get('database', []);
        $adminConfig = $request->request->get('admin', []);

        // Set SQLite as default if no driver specified (zero-configuration)
        if (empty($dbConfig['driver'])) {
            $dbConfig['driver'] = 'sqlite';
        }

        // Validate required fields
        if (empty($adminConfig['username'])) {
            return $this->redirect('/setup/admin?error=missing_fields');
        }

        try {
            // Set environment variables for CLI command
            $_ENV['DB_DRIVER'] = $dbConfig['driver'];
            $_ENV['DB_HOST'] = $dbConfig['host'] ?? '127.0.0.1';
            $_ENV['DB_DATABASE'] = $dbConfig['database'] ?? 'glueful';
            $_ENV['DB_USERNAME'] = $dbConfig['username'] ?? '';
            $_ENV['DB_PASSWORD'] = $dbConfig['password'] ?? '';
            $_ENV['ADMIN_USERNAME'] = $adminConfig['username'];
            $_ENV['ADMIN_EMAIL'] = $adminConfig['email'];
            $_ENV['ADMIN_PASSWORD'] = $adminConfig['password'];

            // Execute CLI install command in quiet mode
            $output = [];
            $returnCode = 0;

            // Change to project root directory for CLI command
            $projectRoot = dirname(__DIR__, 2);
            $command = "cd {$projectRoot} && php glueful install --quiet 2>&1";
            exec($command, $output, $returnCode);

            // Parse results
            $results = $this->parseSetupResults($output, $returnCode);
            $nextSteps = [
                'server_command' => 'php glueful serve',
                'api_docs_url' => 'http://localhost:8000/docs',
                'admin_credentials' => [
                    'username' => $adminConfig['username'],
                    'email' => $adminConfig['email']
                ]
            ];

            // Create setup.lock on success
            if ($returnCode === 0) {
                file_put_contents(dirname(__DIR__, 2) . '/storage/setup.lock', date('Y-m-d H:i:s'));
            }

            // Store results in session and redirect to complete page
            $this->setSession('setup_results', $results);
            $this->setSession('setup_next_steps', $nextSteps);
            return $this->redirect('/setup/complete');
        } catch (\Exception $e) {
            return $this->redirect('/setup/admin?error=' . urlencode($e->getMessage()));
        }
    }

    private function performSystemCheck(): array
    {
        return [
            'php_version' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'extensions' => $this->checkRequiredExtensions(),
            'permissions' => $this->checkDirectoryPermissions()
        ];
    }

    private function checkRequiredExtensions(): array
    {
        $required = ['pdo', 'json', 'mbstring', 'openssl', 'curl'];
        $results = [];

        foreach ($required as $extension) {
            $results[$extension] = extension_loaded($extension);
        }

        return $results;
    }

    private function checkDirectoryPermissions(): array
    {
        $directories = [
            'storage' => dirname(__DIR__, 2) . '/storage',
            'config' => dirname(__DIR__, 2) . '/config'
        ];

        $results = [];
        foreach ($directories as $name => $path) {
            $results[$name] = is_writable($path);
        }

        return $results;
    }

    private function parseSetupResults(array $output, int $returnCode): array
    {
        return [
            'overall_success' => $returnCode === 0,
            'steps' => [
                'environment' => $this->checkOutputFor($output, 'Environment validation completed'),
                'security_keys' => $this->checkOutputFor($output, 'Generated TOKEN_SALT'),
                'database' => $this->checkOutputFor($output, 'Database migrations completed'),
                'cache' => $this->checkOutputFor($output, 'Cache system initialized'),
                'api_definitions' => $this->checkOutputFor($output, 'API definitions generated'),
                'admin_user' => $this->checkOutputFor($output, 'Admin user created successfully'),
            ],
            'raw_output' => implode("\n", $output)
        ];
    }

    private function checkOutputFor(array $output, string $searchString): bool
    {
        return str_contains(implode('\n', $output), $searchString);
    }

    private function view(string $template, array $data = []): Response
    {
        // Extract variables for template
        extract($data);

        // Start output buffering
        ob_start();

        // Include template file (now at setup/index.php)
        $templatePath = dirname(__DIR__) . '/index.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo "Template not found: {$templatePath}";
        }

        // Get content and clean buffer
        $content = ob_get_clean();

        return new Response($content);
    }

    private function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url);
    }

    private function setSession(string $key, $value): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$key] = $value;
    }
}
