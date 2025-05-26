# GitHub-based Extensions Marketplace for Glueful

This guide outlines how to build a GitHub-based marketplace for Glueful extensions, covering both the distribution method and the monorepo setup for internally developed extensions.

## Table of Contents

- [Benefits of GitHub-based Marketplace](#benefits-of-github-based-marketplace)
- [Distribution Method](#distribution-method)
  - [GitHub Releases for Extensions](#github-releases-for-extensions)
  - [Extension Registry Repository](#extension-registry-repository)
  - [Integration with Glueful](#integration-with-glueful)
  - [Extension Installation Flow](#extension-installation-flow)
  - [Update Management](#update-management)
  - [Documentation](#documentation)
- [VS Code Marketplace Approach](#vs-code-marketplace-approach)
  - [Centralized Extension Registry](#centralized-extension-registry)
  - [Extension Package Structure](#extension-package-structure)
  - [Extension Manifest](#extension-manifest)
  - [Marketplace UI Integration](#marketplace-ui-integration)
  - [Client-Side Marketplace UI](#client-side-marketplace-ui)
  - [Authentication for Publishing](#authentication-for-publishing)
  - [Automatic Updates](#automatic-updates)
  - [Extension Ratings and Reviews](#extension-ratings-and-reviews)
- [Setting Up a PHP Extensions Monorepo](#setting-up-a-php-extensions-monorepo)
  - [Base Monorepo Structure](#base-monorepo-structure)
  - [Composer Configuration](#composer-configuration)
  - [Individual Extension Structure](#individual-extension-structure)
  - [Extension-Specific Composer File](#extension-specific-composer-file)
  - [Extension Manifest File](#extension-manifest-file)
  - [Build & Deploy Scripts](#build--deploy-scripts)
  - [GitHub Actions for CI/CD](#github-actions-for-cicd)
  - [Release Workflow for Marketplace](#release-workflow-for-marketplace)
  - [Extension Catalog](#extension-catalog)
  - [Integration with ExtensionsManager](#integration-with-extensionsmanager)
- [Implications for Internal Extensions and Marketplace](#implications-for-internal-extensions-and-marketplace)
  - [Contributions to Internal Extensions](#contributions-to-internal-extensions)
  - [Extensions Marketplace](#extensions-marketplace)
  - [Integration Between Both Systems](#integration-between-both-systems)
- [GitHub-based Marketplace Distribution Workflow](#github-based-marketplace-distribution-workflow)
  - [Extension Publishing Process](#extension-publishing-process)
  - [Catalog Structure](#catalog-structure)
  - [Marketplace Frontend](#marketplace-frontend)
  - [Installation Flow](#installation-flow)
  - [Update Automation](#update-automation)
  - [Third-Party Extension Contributions](#third-party-extension-contributions)
  - [ExtensionsManager Integration](#extensionsmanager-integration)
  - [Benefits of This Approach](#benefits-of-this-approach)

## Benefits of GitHub-based Marketplace

- **Open source ecosystem**: Leverages GitHub's established development ecosystem
- **Version control**: Built-in versioning for extensions
- **Discoverability**: Extensions can be found via GitHub search and tags
- **Contribution workflow**: Pull requests, issues, and forks support community involvement
- **Zero hosting costs**: Free hosting for open source projects
- **Trust**: GitHub provides a trusted platform for code distribution

## Distribution Method

### GitHub Releases for Extensions

GitHub Releases provide a perfect mechanism for versioned extension distribution:

- Each extension repository can publish releases with semantic versioning
- Release assets can include packaged extension files
- Release notes document changes and compatibility information

```bash
# Example structure for a release
my-glueful-extension-v1.0.0.zip
  ├── manifest.json       # Extension metadata
  ├── extension.js        # Core functionality
  ├── assets/             # Extension resources
  └── README.md           # Documentation
```

### Extension Registry Repository

Create a central registry repository that catalogs approved extensions:

```json
[
  {
    "name": "PDF Generator",
    "description": "Convert Glueful documents to PDF format",
    "author": "Glueful Team",
    "repository": "https://github.com/glueful/extension-pdf-generator",
    "version": "1.2.0",
    "compatibility": "1.0.0+",
    "tags": ["export", "pdf", "document"],
    "installUrl": "https://github.com/glueful/extension-pdf-generator/releases/download/v1.2.0/pdf-generator.zip"
  }
]
```

### Integration with Glueful

Add GitHub API integration within Glueful to:

- Fetch the registry.json from the extensions repository
- Display available extensions in an extension marketplace UI
- Handle installation by downloading release assets
- Check for updates by comparing version numbers

### Extension Installation Flow

1. User browses extensions in Glueful marketplace UI
2. Selects extension to install
3. Glueful downloads the extension package from GitHub release URL
4. Validates the package signature/integrity
5. Extracts and installs the extension
6. Registers the extension in the local Glueful configuration

### Update Management

- Implement periodic checks against the registry for newer versions
- Notify users when updates are available
- Provide one-click update functionality

### Documentation

Update your extensions README.md to include:

```markdown
# Glueful Extensions

This repository manages the Glueful extensions ecosystem.

## For Users

Extensions enhance Glueful with additional functionality. Install extensions directly from within Glueful's Extensions Marketplace.

## For Developers

### Creating an Extension

1. Fork the extension template repository
2. Implement your extension following our [development guidelines](./docs/development.md)
3. Test thoroughly with the [extension test suite](./docs/testing.md)
4. Create a GitHub release with your packaged extension
5. Submit a pull request to add your extension to our registry

### Distribution Requirements

- All extensions must include a valid manifest.json
- Extensions must be packaged as .zip files
- GitHub releases must use semantic versioning
- Documentation must include installation and usage instructions

[View the Extensions Marketplace](https://glueful.github.io/extensions/)
```

## VS Code Marketplace Approach

### Centralized Extension Registry Repository

```markdown
# Glueful Extensions Marketplace

This repository serves as the official registry for Glueful extensions, following a VS Code Marketplace-inspired approach.
```

### Extension Package Structure

Each extension should follow this standardized structure:

```
my-extension-1.0.0.vsix  (renamed to .gluex for Glueful extensions)
├── extension.json       # Extension manifest (similar to package.json in VS Code)
├── main.js              # Core functionality
├── assets/              # Extension resources
└── README.md            # Documentation
```

### Extension Manifest

```json
{
  "name": "pdf-generator",
  "displayName": "PDF Generator",
  "version": "1.2.0",
  "publisher": "glueful-team",
  "description": "Convert Glueful documents to PDF format",
  "categories": ["exporters", "document-processing"],
  "icon": "assets/icon.png",
  "galleryBanner": {
    "color": "#C80000",
    "theme": "dark"
  },
  "engines": {
    "glueful": "^1.0.0"
  },
  "main": "./extension.php",
  "dependencies": {
    "glueful-extensions": {
      "document-tools": "^2.0.0"
    },
    "php": ">=7.4.0"
  },
  "repository": {
    "type": "git",
    "url": "https://github.com/glueful/extension-pdf-generator"
  },
  "extensionDependencies": [
    "glueful-team.document-tools"
  ]
}
```

### Marketplace UI Integration

Modify your `api/Helpers/ExtensionsManager.php` to support this VS Code-style marketplace approach:

```php
/**
 * Get extensions from marketplace API
 * 
 * @param array $filters Optional filters for the marketplace query
 * @return array Extensions matching the query
 */
public static function getMarketplaceExtensions(array $filters = []): array
{
    // Define marketplace API URL
    $marketplaceUrl = config('extensions.marketplace_url', 'https://marketplace.glueful.dev/api/extensions');
    
    // Add query parameters for filtering
    if (!empty($filters)) {
        $marketplaceUrl .= '?' . http_build_query($filters);
    }
    
    // Fetch extensions from marketplace
    $ch = curl_init($marketplaceUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($statusCode !== 200 || empty($response)) {
        self::debug("Failed to fetch extensions from marketplace: $statusCode");
        return [];
    }
    
    // Parse response
    $extensions = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        self::debug("Failed to parse marketplace response: " . json_last_error_msg());
        return [];
    }
    
    return $extensions;
}

/**
 * Install extension from marketplace
 * 
 * @param string $extensionId The marketplace extension ID
 * @return array Result with success status and messages
 */
public static function installFromMarketplace(string $extensionId): array
{
    // Get extension details from marketplace
    $extensions = self::getMarketplaceExtensions(['id' => $extensionId]);
    
    if (empty($extensions)) {
        return [
            'success' => false,
            'message' => "Extension not found in marketplace: $extensionId"
        ];
    }
    
    $extension = $extensions[0];
    
    // Download the .gluex package
    $downloadUrl = $extension['downloadUrl'];
    $tempFile = self::downloadOrCopyArchive($downloadUrl);
    
    if (!$tempFile) {
        return [
            'success' => false,
            'message' => "Failed to download extension package from: $downloadUrl"
        ];
    }
    
    // Extract the extension name from the package
    $extensionName = $extension['name'];
    
    // Install the extension
    $result = self::installExtension($tempFile, $extensionName);
    
    // Clean up temporary file
    @unlink($tempFile);
    
    return $result;
}

/**
 * Search marketplace for extensions
 * 
 * @param string $query Search query
 * @param int $page Page number for pagination
 * @param int $perPage Results per page
 * @return array Search results
 */
public static function searchMarketplace(string $query, int $page = 1, int $perPage = 20): array
{
    return self::getMarketplaceExtensions([
        'q' => $query,
        'page' => $page,
        'perPage' => $perPage
    ]);
}
```

### Client-Side Marketplace UI

Create a marketplace browsing UI in your frontend:

```javascript
// Example marketplace component
function ExtensionMarketplace() {
  const [extensions, setExtensions] = useState([]);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(false);
  
  const searchExtensions = async () => {
    setLoading(true);
    const response = await fetch(`/api/extensions/marketplace?q=${query}`);
    const data = await response.json();
    setExtensions(data);
    setLoading(false);
  };
  
  const installExtension = async (extensionId) => {
    const response = await fetch(`/api/extensions/install`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ extensionId })
    });
    const result = await response.json();
    // Handle installation result
  };
  
  // Render marketplace UI similar to VS Code's
  return (
    <div className="marketplace">
      <header>
        <input 
          type="search" 
          placeholder="Search extensions..." 
          value={query}
          onChange={e => setQuery(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && searchExtensions()}
        />
        <button onClick={searchExtensions}>Search</button>
      </header>
      
      <div className="extension-list">
        {extensions.map(ext => (
          <div className="extension-card" key={ext.id}>
            <img src={ext.iconUrl} alt={ext.displayName} />
            <div className="details">
              <h3>{ext.displayName}</h3>
              <p className="publisher">{ext.publisher}</p>
              <p className="description">{ext.description}</p>
              <div className="stats">
                <span>{ext.downloadCount} downloads</span>
                <span>★ {ext.rating} ({ext.ratingCount})</span>
              </div>
            </div>
            <button onClick={() => installExtension(ext.id)}>Install</button>
          </div>
        ))}
      </div>
    </div>
  );
}
```

### Authentication for Publishing

Set up a publishing workflow similar to VS Code's:

1. Allow extension developers to register as publishers
2. Implement a verification process for publishers
3. Create a CLI tool for packaging and publishing extensions:

```bash
glueful extension publish --path=/path/to/extension
```

### Automatic Updates

Implement a VS Code-style update notification system:

```php
/**
 * Check for extension updates from marketplace
 * 
 * @return array Extension updates available
 */
public static function checkMarketplaceUpdates(): array
{
    $installedExtensions = self::getEnabledExtensions();
    $updates = [];
    
    foreach ($installedExtensions as $extensionName) {
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) continue;
        
        try {
            $metadata = $extensionClass::getMetadata();
            $currentVersion = $metadata['version'] ?? '1.0.0';
            
            // Query marketplace for this extension
            $marketplaceData = self::getMarketplaceExtensions(['name' => $extensionName]);
            
            if (!empty($marketplaceData)) {
                $latestVersion = $marketplaceData[0]['version'];
                
                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $updates[] = [
                        'name' => $extensionName,
                        'displayName' => $marketplaceData[0]['displayName'],
                        'currentVersion' => $currentVersion,
                        'latestVersion' => $latestVersion,
                        'downloadUrl' => $marketplaceData[0]['downloadUrl']
                    ];
                }
            }
        } catch (\Throwable $e) {
            self::debug("Error checking updates for $extensionName: " . $e->getMessage());
        }
    }
    
    return $updates;
}
```

### Extension Ratings and Reviews

Add a rating and review system for each extension:

```php
public static function submitExtensionReview(string $extensionId, int $rating, string $comment): array
{
    // Implement marketplace API call to submit review
}

public static function getExtensionReviews(string $extensionId): array
{
    // Fetch reviews from marketplace API
}
```

## Setting Up a PHP Extensions Monorepo

### Base Monorepo Structure

```
glueful-extensions/
├── composer.json
├── package.json (optional for JS assets)
├── extensions/
│   ├── pdf-generator/
│   ├── document-tools/
│   └── workflow-manager/
├── shared/
│   ├── core/
│   └── helpers/
├── tests/
├── scripts/
└── .github/
```

### Composer Configuration

```json
{
  "name": "glueful/extensions",
  "description": "Monorepo for official Glueful extensions",
  "type": "project",
  "license": "proprietary",
  "authors": [
    {
      "name": "Glueful Team",
      "email": "dev@glueful.dev"
    }
  ],
  "minimum-stability": "stable",
  "require": {
    "php": ">=7.4"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.0",
    "squizlabs/php_codesniffer": "^3.6"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Extensions\\": "extensions/",
      "Glueful\\Extensions\\Shared\\": "shared/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Glueful\\Extensions\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "lint": "phpcs",
    "fix": "phpcbf"
  }
}
```

### Individual Extension Structure

Each extension in the monorepo should follow a consistent structure:

```
extensions/pdf-generator/
├── composer.json  # Extension-specific dependencies (optional)
├── extension.json # Extension metadata for marketplace
├── src/
│   ├── PdfGeneratorExtension.php
│   ├── Models/
│   ├── Services/
│   └── Controllers/
├── resources/
│   ├── assets/
│   ├── views/
│   └── lang/
├── public/        # Assets that need to be deployed
├── tests/
└── README.md
```

Note: The `composer.json` file would only be necessary if the extension has specific dependencies that need to be managed through Composer. For simple extensions that don't require external dependencies, this file can be omitted.

### Extension-Specific Composer File

```json
{
  "name": "glueful/extension-pdf-generator",
  "description": "PDF Generator extension for Glueful",
  "type": "glueful-extension",
  "require": {
    "php": ">=7.4",
    "dompdf/dompdf": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Extensions\\PdfGenerator\\": "src/"
    }
  }
}
```

### Extension Manifest File

```json
{
  "name": "pdf-generator",
  "displayName": "PDF Generator",
  "version": "1.2.0",
  "publisher": "glueful-team",
  "description": "Convert Glueful documents to PDF format",
  "categories": ["exporters", "document-processing"],
  "icon": "resources/assets/icon.png",
  "engines": {
    "glueful": "^1.0.0"
  },
  "main": "src/PdfGeneratorExtension.php",
  "dependencies": {
    "php": ">=7.4"
  }
}
```

### Build & Deploy Scripts

Create automation scripts to handle building and deploying extensions:

```php
<?php

/**
 * Extension build script
 * 
 * Packages extensions into distributable format for the marketplace
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Extension to build, or 'all' for all extensions
$extensionName = $argv[1] ?? 'all';
$buildDir = __DIR__ . '/../build';

// Ensure build directory exists
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

/**
 * Build a single extension
 */
function buildExtension(string $name): void {
    global $buildDir;
    
    echo "Building extension: $name\n";
    
    $extensionDir = __DIR__ . "/../extensions/$name";
    $outputFile = "$buildDir/$name.gluex";
    
    if (!is_dir($extensionDir)) {
        echo "Error: Extension directory not found: $extensionDir\n";
        exit(1);
    }
    
    // Read extension metadata
    $metadataFile = "$extensionDir/extension.json";
    if (!file_exists($metadataFile)) {
        echo "Error: extension.json not found in $extensionDir\n";
        exit(1);
    }
    
    $metadata = json_decode(file_get_contents($metadataFile), true);
    $version = $metadata['version'] ?? '1.0.0';
    
    // Create a temporary directory for the build
    $tmpDir = sys_get_temp_dir() . '/glueful-extension-' . uniqid();
    mkdir($tmpDir, 0755, true);
    
    // Copy extension files
    $directories = ['src', 'resources', 'public'];
    foreach ($directories as $dir) {
        if (is_dir("$extensionDir/$dir")) {
            shell_exec("cp -r $extensionDir/$dir $tmpDir/");
        }
    }
    
    // Copy metadata files
    copy($metadataFile, "$tmpDir/extension.json");
    if (file_exists("$extensionDir/README.md")) {
        copy("$extensionDir/README.md", "$tmpDir/README.md");
    }
    
    // Create the archive
    $outputFile = "$buildDir/$name-$version.gluex";
    $currentDir = getcwd();
    chdir($tmpDir);
    shell_exec("zip -r $outputFile .");
    chdir($currentDir);
    
    // Clean up
    shell_exec("rm -rf $tmpDir");
    
    echo "Extension built: $outputFile\n";
}

// Build all extensions or just the specified one
if ($extensionName === 'all') {
    $extensions = array_filter(
        scandir(__DIR__ . '/../extensions'),
        fn($dir) => $dir !== '.' && $dir !== '..' && is_dir(__DIR__ . "/../extensions/$dir")
    );
    
    foreach ($extensions as $ext) {
        buildExtension($ext);
    }
} else {
    buildExtension($extensionName);
}

echo "Build process completed.\n";
```

### Deploy Script to Copy to Extensions Directory

```php
<?php

/**
 * Extension deployment script
 * 
 * Deploys built extensions to a local Glueful installation for testing
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Extension to deploy, or 'all' for all extensions
$extensionName = $argv[1] ?? null;
$targetDir = $argv[2] ?? null;

if (!$extensionName || !$targetDir) {
    echo "Usage: php deploy.php <extension-name> <target-directory>\n";
    echo "   or: php deploy.php all <target-directory>\n";
    exit(1);
}

if (!is_dir($targetDir)) {
    echo "Error: Target directory does not exist: $targetDir\n";
    exit(1);
}

/**
 * Deploy a single extension to the target directory
 */
function deployExtension(string $name, string $targetDir): void {
    echo "Deploying extension: $name to $targetDir\n";
    
    $extensionDir = __DIR__ . "/../extensions/$name";
    
    if (!is_dir($extensionDir)) {
        echo "Error: Extension directory not found: $extensionDir\n";
        return;
    }
    
    // Create target extension directory if it doesn't exist
    $extTargetDir = "$targetDir/$name";
    if (!is_dir($extTargetDir)) {
        mkdir($extTargetDir, 0755, true);
    }
    
    // Copy source files
    shell_exec("cp -r $extensionDir/src/* $extTargetDir/");
    
    // Copy resources if they exist
    if (is_dir("$extensionDir/resources")) {
        if (!is_dir("$extTargetDir/resources")) {
            mkdir("$extTargetDir/resources", 0755, true);
        }
        shell_exec("cp -r $extensionDir/resources/* $extTargetDir/resources/");
    }
    
    // Copy public assets if they exist
    if (is_dir("$extensionDir/public")) {
        $publicTargetDir = "$targetDir/../public/extensions/$name";
        if (!is_dir($publicTargetDir)) {
            mkdir($publicTargetDir, 0755, true);
        }
        shell_exec("cp -r $extensionDir/public/* $publicTargetDir/");
    }
    
    // Copy metadata
    copy("$extensionDir/extension.json", "$extTargetDir/extension.json");
    
    echo "Extension deployed: $name\n";
}

// Deploy all extensions or just the specified one
if ($extensionName === 'all') {
    $extensions = array_filter(
        scandir(__DIR__ . '/../extensions'),
        fn($dir) => $dir !== '.' && $dir !== '..' && is_dir(__DIR__ . "/../extensions/$dir")
    );
    
    foreach ($extensions as $ext) {
        deployExtension($ext, $targetDir);
    }
} else {
    deployExtension($extensionName, $targetDir);
}

echo "Deployment completed.\n";
```

### GitHub Actions for CI/CD

```yaml
name: Build Extensions

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  build:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'
          extensions: zip, mbstring, dom
          
      - name: Validate composer.json
        run: composer validate
        
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
        
      - name: Run tests
        run: composer run-script test
        
      - name: Build extensions
        run: php scripts/build.php all
        
      - name: Upload artifacts
        uses: actions/upload-artifact@v2
        with:
          name: extensions
          path: build/*.gluex
```

### Release Workflow for Marketplace

```yaml
name: Release Extensions

on:
  release:
    types: [created]

jobs:
  publish:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'
          extensions: zip, mbstring, dom
          
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
        
      - name: Build extensions
        run: php scripts/build.php all
        
      - name: Upload release assets
        uses: svenstaro/upload-release-action@v2
        with:
          repo_token: ${{ secrets.GITHUB_TOKEN }}
          file: build/*.gluex
          file_glob: true
          tag: ${{ github.ref }}
          
      - name: Notify marketplace
        run: |
          curl -X POST https://extensions.glueful.dev/api/v1/updates \
            -H "Authorization: Bearer ${{ secrets.MARKETPLACE_TOKEN }}" \
            -H "Content-Type: application/json" \
            --data '{"repository": "${{ github.repository }}", "tag": "${{ github.ref }}"}'
```

### Extension Catalog

Create a catalog file to track all official extensions:

```json
{
  "extensions": [
    {
      "name": "pdf-generator",
      "path": "extensions/pdf-generator",
      "repository": "https://github.com/glueful/extensions",
      "marketplace": true
    },
    {
      "name": "document-tools",
      "path": "extensions/document-tools",
      "repository": "https://github.com/glueful/extensions",
      "marketplace": true
    },
    {
      "name": "workflow-manager",
      "path": "extensions/workflow-manager",
      "repository": "https://github.com/glueful/extensions",
      "marketplace": false
    }
  ]
}
```

### Integration with ExtensionsManager

Update your existing ExtensionsManager.php to work with the monorepo structure:

```php
/**
 * Register extensions from a monorepo structure
 * 
 * @param string $monorepoPath Path to the monorepo
 * @return void
 */
public static function registerMonorepo(string $monorepoPath): void
{
    // Check if the catalog exists
    $catalogFile = $monorepoPath . '/catalog.json';
    if (!file_exists($catalogFile)) {
        self::debug("Monorepo catalog not found: $catalogFile");
        return;
    }
    
    $catalog = json_decode(file_get_contents($catalogFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        self::debug("Failed to parse monorepo catalog: " . json_last_error_msg());
        return;
    }
    
    // Register each extension in the catalog
    foreach ($catalog['extensions'] as $extension) {
        $extensionPath = $monorepoPath . '/' . $extension['path'];
        $extensionName = $extension['name'];
        
        if (!is_dir($extensionPath)) {
            self::debug("Extension directory not found: $extensionPath");
            continue;
        }
        
        // Register the namespace for this extension
        $classLoader = self::getClassLoader();
        if ($classLoader) {
            // Determine namespace based on extension structure
            $namespace = 'Glueful\\Extensions\\' . str_replace('-', '', ucwords($extensionName, '-')) . '\\';
            $srcPath = $extensionPath . '/src';
            
            if (is_dir($srcPath)) {
                $classLoader->addPsr4($namespace, [$srcPath]);
                self::debug("Registered monorepo extension namespace: $namespace -> $srcPath");
            }
        }
    }
}
```

## Implications for Internal Extensions and Marketplace

### Contributions to Internal Extensions

#### Controlled Development Environment
- **Streamlined Workflow**: Developers work within a single repository for all official extensions
- **Shared Code**: Common functionality lives in the `shared/` directory, reducing duplication
- **Unified Standards**: Consistent coding standards enforced across all official extensions
- **Centralized Testing**: Tests for all extensions in one place makes CI/CD simpler

#### Contribution Process
- **Pull Request Based**: Contributors submit PRs to the monorepo
- **Code Review**: Single review process for all official extensions
- **Dependency Management**: Clear visibility of cross-extension dependencies
- **Automated Testing**: Extensions are tested together to prevent integration issues

#### Development Automation
- Your build script generates packaged extensions for distribution
- The deploy script simplifies testing by copying extensions to a Glueful installation
- Your CI workflow automatically builds and tests all extensions together

### Extensions Marketplace

#### Clear Separation of Concerns
- **Official vs Community**: Clear distinction between official (monorepo) and third-party extensions
- **Reduced Overhead**: Marketplace only handles distribution, not development
- **Independent Release Cycles**: Marketplace can evolve separately from extension development

#### Distribution Workflow
1. Your GitHub Actions workflow builds extensions from the monorepo
2. Built extensions are uploaded as release assets
3. Marketplace is notified via the API endpoint
4. Extensions become available to users through the marketplace UI

#### Third-Party Extensions
- Community developers can publish directly to the marketplace
- Their extensions won't be in your monorepo, but follow the same packaging format
- Your validation system ensures third-party extensions meet quality standards

#### Marketplace Features
- The structure supports the VS Code-like marketplace features
- Extensions can be categorized, rated, and discovered regardless of source
- Update notifications work the same for internal and third-party extensions

### Integration Between Both Systems

The setup creates a clean pipeline where:

1. Internal extensions are developed in the monorepo
2. Automated builds package extensions in the proper format
3. The marketplace serves as the distribution channel for both internal and third-party extensions
4. ExtensionsManager in Glueful handles installation and management regardless of source

## GitHub-based Marketplace Distribution Workflow

### Extension Publishing Process

1. **Build Extensions in Monorepo**
   - Your GitHub Actions workflow builds extensions from the monorepo
   - Extensions are packaged into `.gluex` files with proper versioning

2. **Release to GitHub**
   - Extensions are attached as assets to GitHub releases
   - Each release includes metadata in a standardized format

3. **Update Extension Catalog**
   - A central catalog repository (or branch in your extensions repo) is updated
   - This catalog serves as the marketplace "database"

### Catalog Structure

```json
{
  "extensions": [
    {
      "name": "pdf-generator",
      "displayName": "PDF Generator",
      "version": "1.2.0",
      "publisher": "glueful-team",
      "description": "Convert Glueful documents to PDF format",
      "repository": "https://github.com/glueful/extensions",
      "downloadUrl": "https://github.com/glueful/extensions/releases/download/pdf-generator-1.2.0/pdf-generator-1.2.0.gluex",
      "tags": ["document", "export"],
      "rating": 4.8,
      "downloads": 1250,
      "lastUpdated": "2025-04-15T14:30:00Z"
    }
  ]
}
```

### Marketplace Frontend

- A GitHub Pages site serves as the marketplace frontend
- It fetches and displays data from the catalog JSON
- Users browse extensions through this interface

### Installation Flow

1. Users discover extensions through the marketplace UI
2. When they click "Install", Glueful:
   - Fetches the extension package from the GitHub release URL
   - Verifies package integrity
   - Extracts and installs the extension

### Update Automation

The release workflow in your monorepo:

```yaml
name: Release Extensions

on:
  release:
    types: [created]

jobs:
  publish:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      # Setup and build steps...
      
      - name: Upload release assets
        uses: svenstaro/upload-release-action@v2
        with:
          repo_token: ${{ secrets.GITHUB_TOKEN }}
          file: build/*.gluex
          file_glob: true
          tag: ${{ github.ref }}
          
      - name: Update catalog
        run: |
          # Clone the catalog repository
          git clone https://x-access-token:${{ secrets.CATALOG_TOKEN }}@github.com/glueful/extensions-catalog.git
          
          # Update catalog.json with new extension data
          cd extensions-catalog
          # Use a script to update the catalog
          php ../scripts/update-catalog.php "${{ github.repository }}" "${{ github.ref }}"
          
          # Commit and push changes
          git config user.name "GitHub Actions"
          git config user.email "actions@github.com"
          git add catalog.json
          git commit -m "Update catalog for ${{ github.repository }} ${{ github.ref }}"
          git push
```

### Third-Party Extension Contributions

1. Third-party developers fork your extension template repository
2. They develop their extension following your guidelines
3. They release it on their own GitHub repository
4. They submit a pull request to your catalog repository to be listed
5. After review, their extension appears in the marketplace

### ExtensionsManager Integration

Your ExtensionsManager needs these methods to work with the GitHub-based marketplace:

```php
/**
 * Fetch the extension catalog from GitHub
 */
public static function fetchMarketplaceCatalog(): array
{
    $catalogUrl = 'https://raw.githubusercontent.com/glueful/extensions-catalog/main/catalog.json';
    
    $ch = curl_init($catalogUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!$response) {
        return [];
    }
    
    return json_decode($response, true)['extensions'] ?? [];
}

/**
 * Install extension from GitHub release URL
 */
public static function installFromGitHub(string $extensionName, string $downloadUrl): array
{
    // Download the extension package
    $tempFile = tempnam(sys_get_temp_dir(), 'glueful-extension-');
    
    $ch = curl_init($downloadUrl);
    $fp = fopen($tempFile, 'w');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    // Install the extension
    $result = self::installExtension($tempFile, $extensionName);
    
    // Clean up
    unlink($tempFile);
    
    return $result;
}
```

### Benefits of This Approach

1. **No Custom Backend Required**: Everything runs on GitHub infrastructure
2. **Transparent Process**: All extensions and their code are publicly visible
3. **Familiar for Developers**: Uses standard GitHub workflows
4. **Reduced Maintenance**: No need to maintain a separate marketplace service
5. **Version Control**: Extension history and changes are tracked via Git
