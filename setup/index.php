<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glueful Setup</title>
    <link rel="stylesheet" href="/api/v1/setup/setup.css">
</head>
<body>
    <div class="setup-container">
        
        <!-- Progress indicator -->
        <!-- <div class="progress-steps">
            <div class="step-indicator <?= $currentStep === 'welcome' ? 'active' : '' ?>">1. Welcome</div>
            <div class="step-indicator <?= $currentStep === 'database' ? 'active' : '' ?>">2. Database</div>
            <div class="step-indicator <?= $currentStep === 'admin' ? 'active' : '' ?>">3. Admin</div>
            <div class="step-indicator <?= $currentStep === 'complete' ? 'active' : '' ?>">4. Complete</div>
        </div> -->
        <div class="step-wrapper">
             <!-- Glueful Logo -->
            <div class="pl-20">
                <img src="/api/v1/docs/logo_full.svg" alt="Glueful" class="setup-logo">
            </div>
            <!-- Step 1: Welcome & System Check -->
            <div id="step-welcome" class="step <?= $currentStep === 'welcome' ? 'active' : '' ?>">
                <!-- <h1>🚀 Welcome!</h1> -->
                <p class="pl-20">Let's get your Glueful API framework up and running. This setup wizard will guide you 
                through the configuration process.</p>
                
                <div class="system-check">
                    <h3>System Requirements Check</h3>
                    
                    <div class="check-item <?= $systemCheck['php_version'] ? 'pass' : 'fail' ?>">
                        PHP 8.2+ (Current: <?= PHP_VERSION ?>)
                    </div>
                    
                    <?php foreach ($systemCheck['extensions'] as $ext => $loaded) : ?>
                        <div class="check-item <?= $loaded ? 'pass' : 'fail' ?>">
                            <?= ucfirst($ext) ?> Extension
                        </div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($systemCheck['permissions'] as $dir => $writable) : ?>
                        <div class="check-item <?= $writable ? 'pass' : 'fail' ?>">
                            <?= ucfirst($dir) ?> Directory Writable
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php
                $allChecks = $systemCheck['php_version'] &&
                            !in_array(false, $systemCheck['extensions']) &&
                            !in_array(false, $systemCheck['permissions']);
                ?>
                
                <?php if ($allChecks) : ?>
                    <button class="btn ml-20" onclick="navigateToStep('database')">Continue Setup</button>
                <?php else : ?>
                    <div class="error-message">
                        Please fix the system requirements above before continuing.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Step 2: Database Configuration -->
            <div id="step-database" class="step <?= $currentStep === 'database' ? 'active' : '' ?>">
                <div class="pl-20">
                    <h3>🗄️ Database Ready</h3>
                    <p>Glueful uses SQLite by default - no database configuration needed!</p>
                    
                    <div class="db-status" style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <div class="check-item pass">
                            ✅ <strong>SQLite database configured</strong> - works immediately
                        </div>
                        <div class="check-item pass">
                            ✅ <strong>Zero setup required</strong> - no external database needed
                        </div>
                        <div class="check-item pass">
                            ✅ <strong>Production ready</strong> - scales to millions of records
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                        <h4>💡 Want to use MySQL or PostgreSQL instead?</h4>
                        <p>No problem! You can easily switch databases after installation:</p>
                        <ol style="margin: 10px 0 10px 20px;">
                            <li>Complete the installation with SQLite</li>
                            <li>Update your <code>.env</code> file with your database credentials</li>
                            <li>Run <code>php glueful migrate:run</code> to migrate to your new database</li>
                        </ol>
                        <p><small><em>All migrations are database-agnostic and work seamlessly across SQLite, MySQL, and PostgreSQL.</em></small></p>
                    </div>
                </div>
                
                <button class="btn ml-20" onclick="navigateToStep('admin')" id="continue-admin">Continue with SQLite</button>
            </div>
            
            <!-- Step 3: Admin User -->
            <div id="step-admin" class="step <?= $currentStep === 'admin' ? 'active' : '' ?>">
                <!-- <h2>Create Admin User</h2> -->
                <p class="pl-20">Create your administrator account to manage the system.</p>

                <?php if (isset($_GET['error'])) : ?>
                    <div class="error-message">
                        Error: <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="/setup" id="setup-form" class="form">
                    <!-- Database fields (hidden, SQLite defaults) -->
                    <input type="hidden" name="database[driver]" id="hidden-db-driver" value="sqlite">
                    <input type="hidden" name="database[host]" id="hidden-db-host" value="">
                    <input type="hidden" name="database[database]" id="hidden-db-database" value="storage/database/glueful.sqlite">
                    <input type="hidden" name="database[username]" id="hidden-db-username" value="">
                    <input type="hidden" name="database[password]" id="hidden-db-password" value="">
                    
                    <!-- Admin user fields -->
                    <div class="form-group">
                        <label for="admin-username">Admin Username</label>
                        <input type="text" name="admin[username]" id="admin-username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-email">Admin Email</label>
                        <input type="email" name="admin[email]" id="admin-email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-password">Password</label>
                        <input type="password" name="admin[password]" id="admin-password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm-password">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm-password" required>
                    </div>
                    
                    <button type="submit" class="btn">Setup</button>
                </form>
            </div>
            
            <!-- Step 4: Complete -->
            <div id="step-complete" class="step <?= $currentStep === 'complete' ? 'active' : '' ?>">
                <h2>🎉 Setup Complete!</h2>
                
                <?php if (isset($_SESSION['setup_results'])) : ?>
                    <div class="results-display">
                        <?php $results = $_SESSION['setup_results']; ?>
                        
                        <?php if ($results['overall_success']) : ?>
                            <div class="check-item pass">
                                ✅ Setup completed successfully!
                            </div>
                        <?php else : ?>
                            <div class="check-item fail">
                                ❌ Setup encountered issues
                            </div>
                        <?php endif; ?>
                        
                        <h3>Setup Steps:</h3>
                        <?php foreach ($results['steps'] as $step => $success) : ?>
                            <div class="check-item <?= $success ? 'pass' : 'fail' ?>">
                                <?= $success ? '✅' : '❌' ?> <?= ucfirst(str_replace('_', ' ', $step)) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (isset($_SESSION['setup_next_steps'])) : ?>
                        <?php $nextSteps = $_SESSION['setup_next_steps']; ?>
                        <div class="next-steps" 
                            style="margin-top: 30px; padding: 20px; background: #d4edda; border-radius: 8px;">
                            <h3>🚀 Next Steps:</h3>
                            <ol style="margin: 15px 0;">
                                <li>Start your development server: 
                                    <code style="background: #fff; padding: 4px 8px; border-radius: 4px;">
                                        <?= $nextSteps['server_command'] ?>
                                    </code>
                                </li>
                                <li>Visit your application in a web browser</li>
                                <li>Review API docs: 
                                    <a href="<?= $nextSteps['api_docs_url'] ?>" target="_blank">
                                        <?= $nextSteps['api_docs_url'] ?>
                                    </a>
                                </li>
                            </ol>
                            
                            <div style="background: #fff; padding: 15px; border-radius: 8px; margin-top: 15px;">
                                <h4>👤 Admin Login Details:</h4>
                                <p><strong>Username:</strong> <?= $nextSteps['admin_credentials']['username'] ?></p>
                                <p><strong>Email:</strong> <?= $nextSteps['admin_credentials']['email'] ?></p>
                                <p><em>(Use the password you created during setup)</em></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($_GET['error'])) : ?>
                    <div class="error-message">
                        Setup failed: <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

   
    
    <!-- Pass server data to JavaScript -->
    <script>
        window.setupConfig = {
            currentStep: '<?= $currentStep ?>',
            validSteps: <?= json_encode($validSteps) ?>
        };
        // console.log('Setup Config:', window.setupConfig);
    </script>
    <script src="/api/v1/setup/setup.js"></script>
</body>
</html>