/**
 * Glueful Setup Wizard - Enhanced JavaScript Interactions
 * Provides advanced form validation, persistence, and user experience enhancements
 */

class SetupWizard {
    constructor() {
        this.currentStep = window.setupConfig?.currentStep || this.getCurrentStep();
        this.validSteps = window.setupConfig?.validSteps || ['welcome', 'database', 'admin', 'complete'];
        this.formData = this.loadFormData();
        this.validationRules = this.initValidationRules();
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeStep();
        this.loadStoredData();
        this.initRealTimeValidation();
        this.checkSystemRequirements();
    }

    getCurrentStep() {
        const stepElement = document.querySelector('.step.active');
        if (stepElement) {
            const stepId = stepElement.id;
            return stepId.replace('step-', '');
        }
        return 'welcome';
    }

    bindEvents() {
        // Database driver change
        const dbDriver = document.getElementById('db-driver');
        if (dbDriver) {
            dbDriver.addEventListener('change', (e) => this.handleDriverChange(e));
        }

        // Form submission
        const setupForm = document.getElementById('setup-form');
        if (setupForm) {
            setupForm.addEventListener('submit', (e) => this.handleFormSubmission(e));
        }

        // Input changes for real-time validation
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            input.addEventListener('input', (e) => this.handleInputChange(e));
            input.addEventListener('blur', (e) => this.validateField(e.target));
        });

        // Navigation buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[onclick*="navigateToStep"]')) {
                e.preventDefault();
                const step = e.target.getAttribute('onclick').match(/navigateToStep\('([^']+)'\)/)[1];
                this.navigateToStep(step);
            }
        });

        // Auto-save form data
        setInterval(() => this.saveFormData(), 5000); // Save every 5 seconds

        // Window beforeunload to save data
        window.addEventListener('beforeunload', () => this.saveFormData());
    }

    initializeStep() {
        if (this.currentStep === 'database') {
            this.initDatabaseStep();
        } else if (this.currentStep === 'admin') {
            this.initAdminStep();
        }
    }

    initDatabaseStep() {
        // Pre-populate smart defaults
        this.setSmartDefaults();
        
        // Initialize connection testing
        this.initConnectionTesting();
    }

    initAdminStep() {
        // Populate hidden fields with database data
        this.populateHiddenFields();
        
        // Focus first input
        const firstInput = document.querySelector('#step-admin input[type="text"]');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }

    setSmartDefaults() {
        const defaults = {
            'db-host': '127.0.0.1',
            'db-database': 'glueful',
            'db-username': '',
            'db-password': ''
        };

        Object.entries(defaults).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element && !element.value) {
                element.value = value;
            }
        });
    }

    handleDriverChange(event) {
        const driver = event.target.value;
        const connectionFields = document.getElementById('connection-fields');
        
        if (driver === 'sqlite') {
            connectionFields.style.display = 'none';
            this.setSQLiteDefaults();
        } else {
            connectionFields.style.display = 'block';
            this.setDatabaseDefaults(driver);
        }

        this.saveFormData();
        this.updateContinueButton();
    }

    setSQLiteDefaults() {
        const defaults = {
            'db-host': '',
            'db-username': '',
            'db-password': '',
            'db-database': 'storage/database.sqlite'
        };

        Object.entries(defaults).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.value = value;
            }
        });
    }

    setDatabaseDefaults(driver) {
        const defaults = {
            mysql: {
                'db-host': '127.0.0.1',
                'db-database': 'glueful',
                'db-username': 'root',
                'db-password': ''
            },
            pgsql: {
                'db-host': '127.0.0.1',
                'db-database': 'glueful',
                'db-username': 'postgres',
                'db-password': ''
            }
        };

        if (defaults[driver]) {
            Object.entries(defaults[driver]).forEach(([id, value]) => {
                const element = document.getElementById(id);
                if (element && !element.value) {
                    element.value = value;
                }
            });
        }
    }

    initValidationRules() {
        return {
            'admin-username': {
                required: true,
                minLength: 3,
                pattern: /^[a-zA-Z0-9_]+$/,
                message: 'Username must be 3+ characters, alphanumeric and underscores only'
            },
            'admin-email': {
                required: true,
                pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                message: 'Please enter a valid email address'
            },
            'admin-password': {
                required: true,
                minLength: 8,
                pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/,
                message: 'Password must be 8+ characters with uppercase, lowercase, and number'
            },
            'confirm-password': {
                required: true,
                match: 'admin-password',
                message: 'Passwords do not match'
            },
            'db-driver': {
                required: true,
                message: 'Please select a database driver'
            }
        };
    }

    initRealTimeValidation() {
        Object.keys(this.validationRules).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                this.createValidationIndicator(field);
            }
        });
    }

    createValidationIndicator(field) {
        const container = field.closest('.form-group');
        if (!container) return;

        // Create validation message element
        const messageEl = document.createElement('div');
        messageEl.className = 'validation-message';
        messageEl.id = `${field.id}-message`;
        container.appendChild(messageEl);

        // Create input indicator
        const indicator = document.createElement('span');
        indicator.className = 'input-indicator';
        indicator.id = `${field.id}-indicator`;
        container.style.position = 'relative';
        container.appendChild(indicator);
    }

    handleInputChange(event) {
        const field = event.target;
        
        // Save to localStorage
        this.saveFormData();
        
        // Real-time validation for certain fields
        if (field.id === 'confirm-password' || field.id === 'admin-password') {
            this.validatePasswordMatch();
        }
        
        if (field.id === 'admin-email') {
            this.validateField(field);
        }

        // Update continue button state
        this.updateContinueButton();
    }

    validateField(field) {
        const rules = this.validationRules[field.id];
        if (!rules) return true;

        const value = field.value.trim();
        const messageEl = document.getElementById(`${field.id}-message`);
        const indicator = document.getElementById(`${field.id}-indicator`);

        // Clear previous state
        field.classList.remove('error', 'success');
        if (messageEl) {
            messageEl.classList.remove('error', 'success');
            messageEl.style.display = 'none';
        }
        if (indicator) {
            indicator.textContent = '';
        }

        // Required validation
        if (rules.required && !value) {
            this.showFieldError(field, messageEl, indicator, 'This field is required');
            return false;
        }

        if (!value) return true; // Skip other validations if field is empty and not required

        // Min length validation
        if (rules.minLength && value.length < rules.minLength) {
            this.showFieldError(field, messageEl, indicator, rules.message);
            return false;
        }

        // Pattern validation
        if (rules.pattern && !rules.pattern.test(value)) {
            this.showFieldError(field, messageEl, indicator, rules.message);
            return false;
        }

        // Match validation (for password confirmation)
        if (rules.match) {
            const matchField = document.getElementById(rules.match);
            if (matchField && value !== matchField.value) {
                this.showFieldError(field, messageEl, indicator, rules.message);
                return false;
            }
        }

        // Field is valid
        this.showFieldSuccess(field, messageEl, indicator);
        return true;
    }

    showFieldError(field, messageEl, indicator, message) {
        field.classList.add('error');
        if (messageEl) {
            messageEl.textContent = message;
            messageEl.classList.add('error');
            messageEl.style.display = 'block';
        }
        // if (indicator) {
        //     indicator.textContent = '❌';
        // }
    }

    showFieldSuccess(field, messageEl, indicator) {
        field.classList.add('success');
        if (messageEl) {
            messageEl.classList.add('success');
            messageEl.textContent = 'Looks good!';
            messageEl.style.display = 'block';
        }
        // if (indicator) {
        //     indicator.textContent = '✅';
        // }
    }

    validatePasswordMatch() {
        const password = document.getElementById('admin-password');
        const confirmPassword = document.getElementById('confirm-password');
        
        if (password && confirmPassword && confirmPassword.value) {
            this.validateField(confirmPassword);
        }
    }

    updateContinueButton() {
        const continueBtn = document.getElementById('continue-admin');
        if (!continueBtn) return;

        const driver = document.getElementById('db-driver')?.value;
        const requiredFields = driver === 'sqlite' 
            ? ['db-driver'] 
            : ['db-driver', 'db-host', 'db-database', 'db-username'];

        const allValid = requiredFields.every(fieldId => {
            const field = document.getElementById(fieldId);
            return field && field.value.trim();
        });

        continueBtn.disabled = !allValid;
        continueBtn.textContent = allValid ? 'Continue' : 'Please complete database configuration';
    }

    initConnectionTesting() {
        // Add test connection button
        const form = document.getElementById('database-form');
        if (!form) return;

        const testBtn = document.createElement('button');
        testBtn.type = 'button';
        testBtn.className = 'btn btn-secondary';
        testBtn.textContent = 'Test Connection';
        testBtn.style.marginRight = '10px';
        testBtn.style.marginLeft = '20px';
        testBtn.style.marginBottom = '20px';
        testBtn.addEventListener('click', () => this.testDatabaseConnection());

        const continueBtn = document.getElementById('continue-admin');
        if (continueBtn && continueBtn.parentNode) {
            continueBtn.parentNode.insertBefore(testBtn, continueBtn);
        }
    }

    testDatabaseConnection() {
        const driver = document.getElementById('db-driver')?.value;
        const host = document.getElementById('db-host')?.value;
        const database = document.getElementById('db-database')?.value;
        const username = document.getElementById('db-username')?.value;
        const password = document.getElementById('db-password')?.value;

        if (!driver) {
            this.showConnectionResult(false, 'Please select a database driver first');
            return;
        }

        // Show testing indicator
        this.showConnectionResult('testing', 'Testing connection...');

        // Simulate connection test (in real implementation, this would make an AJAX call)
        setTimeout(() => {
            // Basic validation
            if (driver !== 'sqlite' && (!host || !database)) {
                this.showConnectionResult(false, 'Host and database name are required');
                return;
            }

            if (driver === 'sqlite' && !database) {
                this.showConnectionResult(false, 'Database file path is required');
                return;
            }

            // Simulate successful connection
            this.showConnectionResult(true, 'Connection successful!');
        }, 2000);
    }

    showConnectionResult(status, message) {
        let resultEl = document.getElementById('connection-result');
        
        if (!resultEl) {
            resultEl = document.createElement('div');
            resultEl.id = 'connection-result';
            const form = document.getElementById('database-form');
            if (form) {
                form.appendChild(resultEl);
            }
        }

        resultEl.className = 'validation-message';
        resultEl.style.display = 'block';
        resultEl.textContent = message;

        if (status === 'testing') {
            resultEl.classList.add('info-message');
            resultEl.classList.remove('error', 'success');
        } else if (status === true) {
            resultEl.classList.add('success');
            resultEl.classList.remove('error', 'info-message');
        } else {
            resultEl.classList.add('error');
            resultEl.classList.remove('success', 'info-message');
        }
    }

    navigateToStep(step) {
        if (!this.validSteps.includes(step)) return;

        // Save current form data
        this.saveFormData();

        // Special handling for admin step
        if (step === 'admin') {
            if (!this.validateDatabaseStep()) {
                return;
            }
            this.populateHiddenFields();
        }

        // Get the current base path (handles both /setup and /api/v1/setup)
        const currentPath = window.location.pathname;
        const basePath = currentPath.includes('/api/v1/setup') ? '/api/v1/setup' : '/setup';
        
        // Navigate to the step
        window.location.href = `${basePath}/${step}`;
    }

    validateDatabaseStep() {
        const driver = document.getElementById('db-driver')?.value;
        if (!driver) {
            alert('Please select a database driver');
            return false;
        }

        if (driver !== 'sqlite') {
            const host = document.getElementById('db-host')?.value;
            const database = document.getElementById('db-database')?.value;
            
            if (!host || !database) {
                alert('Please fill in the database host and name');
                return false;
            }
        }

        return true;
    }

    populateHiddenFields() {
        const mappings = {
            'hidden-db-driver': 'db-driver',
            'hidden-db-host': 'db-host',
            'hidden-db-database': 'db-database',
            'hidden-db-username': 'db-username',
            'hidden-db-password': 'db-password'
        };

        Object.entries(mappings).forEach(([hiddenId, sourceId]) => {
            const hiddenField = document.getElementById(hiddenId);
            const sourceField = document.getElementById(sourceId);
            
            if (hiddenField && sourceField) {
                hiddenField.value = sourceField.value;
            }
        });
    }

    handleFormSubmission(event) {
        // Validate all required fields
        const requiredFields = ['admin-username', 'admin-email', 'admin-password', 'confirm-password'];
        let allValid = true;

        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field && !this.validateField(field)) {
                allValid = false;
            }
        });

        if (!allValid) {
            event.preventDefault();
            this.showFormError('Please fix the validation errors before continuing');
            return false;
        }

        // Show loading screen
        this.showLoadingScreen();
        
        // Clear saved form data on successful submission
        this.clearFormData();
        
        // Form will submit normally
        return true;
    }

    showFormError(message) {
        let errorEl = document.getElementById('form-error');
        
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.id = 'form-error';
            errorEl.className = 'error-message';
            
            const form = document.getElementById('setup-form');
            if (form) {
                form.insertBefore(errorEl, form.firstChild);
            }
        }

        errorEl.textContent = message;
        errorEl.style.display = 'block';
        errorEl.scrollIntoView({ behavior: 'smooth' });
    }

    showLoadingScreen() {
        document.body.innerHTML = `
            <div class="setup-progress">
                <div class="loading-spinner"></div>
                <h2>🚀 Setting up Glueful</h2>
                <p>Setting up your application... This may take 30-60 seconds.</p>
                <p><small>Please don't close this window.</small></p>
                
                <div class="progress-steps">
                    <div class="step">📋 Validating environment</div>
                    <div class="step">🔐 Generating security keys</div>
                    <div class="step">🗄️ Setting up database</div>
                    <div class="step">👤 Creating admin user</div>
                    <div class="step">✅ Finalizing setup</div>
                </div>
            </div>
        `;
    }

    checkSystemRequirements() {
        if (this.currentStep !== 'welcome') return;

        // Additional client-side checks
        this.checkBrowserCompatibility();
    }

    checkBrowserCompatibility() {
        const isModernBrowser = window.fetch && window.Promise && window.Set;
        
        if (!isModernBrowser) {
            const warningEl = document.createElement('div');
            warningEl.className = 'warning-message';
            warningEl.innerHTML = `
                <strong>Browser Compatibility Warning:</strong>
                Your browser may not support all features. Please consider updating to a modern browser.
            `;
            
            const systemCheck = document.querySelector('.system-check');
            if (systemCheck) {
                systemCheck.appendChild(warningEl);
            }
        }
    }


    // Form data persistence methods
    saveFormData() {
        const data = {};
        
        // Save all form inputs
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (input.type !== 'password' && input.id) {
                data[input.id] = input.value;
            }
        });

        localStorage.setItem('glueful-setup-data', JSON.stringify(data));
    }

    loadFormData() {
        try {
            const data = localStorage.getItem('glueful-setup-data');
            return data ? JSON.parse(data) : {};
        } catch (e) {
            console.warn('Failed to load form data from localStorage:', e);
            return {};
        }
    }

    loadStoredData() {
        // Restore form data from localStorage
        Object.entries(this.formData).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element && !element.value) {
                element.value = value;
            }
        });

        // Trigger driver change if database driver is restored
        const dbDriver = document.getElementById('db-driver');
        if (dbDriver && dbDriver.value) {
            dbDriver.dispatchEvent(new Event('change'));
        }
    }

    clearFormData() {
        localStorage.removeItem('glueful-setup-data');
    }
}

// Initialize the setup wizard when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if we're on a setup page
    if (document.querySelector('.setup-container')) {
        window.setupWizard = new SetupWizard();
    }
});

// Legacy function for inline onclick handlers
function navigateToStep(step) {
    if (window.setupWizard) {
        window.setupWizard.navigateToStep(step);
    } else {
        // Fallback for when class isn't initialized
        const currentPath = window.location.pathname;
        const basePath = currentPath.includes('/api/v1/setup') ? '/api/v1/setup' : '/setup';
        window.location.href = `${basePath}/${step}`;
    }
}