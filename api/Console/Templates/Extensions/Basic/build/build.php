<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

/**
 * Basic Extension Template Build Script
 *
 * This script builds the Basic extension template into a .gluex package for distribution.
 */

// Configuration
$templateDir = dirname(__DIR__);
$outputDir = $templateDir . '/dist';

// Get extension name from command line or use default
$templateName = isset($argv[1]) ? $argv[1] : 'BasicExtension';
$templateType = 'Basic';

// Files to exclude from template packaging
$excludes = [
    '.', '..', '.git', '.github', '.vscode', 'node_modules',
    'vendor', 'tests', 'composer.lock', '.DS_Store', '.gitignore',
    'build.php', 'build', 'dist'
];

/**
 * Main build function
 */
function buildTemplate(): void
{
    global $templateDir, $outputDir, $templateName, $templateType;

    echo "Building $templateName ($templateType Template)...\n";

    // Create output directory
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
        echo "Created output directory: $outputDir\n";
    }

    $tempDir = sys_get_temp_dir() . '/glueful_template_' . strtolower($templateName) . '_' . time();
    $outputFile = $outputDir . '/' . $templateName . '.gluex';

    try {
        // Create temporary directory
        mkdir($tempDir, 0755, true);

        // Copy template files
        copyTemplateFiles($templateDir, $tempDir);

        // Create the .gluex package
        createGluexPackage($tempDir, $outputFile);

        // Cleanup
        removeDirectory($tempDir);

        echo "Created: " . basename($outputFile) . "\n";
        echo "Build complete!\n";
    } catch (Exception $e) {
        echo "Error building $templateName: " . $e->getMessage() . "\n";

        // Cleanup on error
        if (is_dir($tempDir)) {
            removeDirectory($tempDir);
        }
        exit(1);
    }
}

/**
 * Copy template files excluding build files
 */
function copyTemplateFiles(string $sourceDir, string $destDir): void
{
    global $excludes;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $item->getPathname());

        // Skip excluded files
        if (shouldExclude($item->getFilename(), $relativePath)) {
            continue;
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            $destDirPath = dirname($destPath);
            if (!is_dir($destDirPath)) {
                mkdir($destDirPath, 0755, true);
            }
            copy($item->getPathname(), $destPath);
        }
    }
}

/**
 * Check if a file should be excluded
 */
function shouldExclude(string $filename, string $relativePath): bool
{
    global $excludes;

    // Check direct filename matches
    if (in_array($filename, $excludes)) {
        return true;
    }

    // Check if any part of the path contains excluded items
    $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
    foreach ($pathParts as $part) {
        if (in_array($part, $excludes)) {
            return true;
        }
    }

    return false;
}

/**
 * Create .gluex package (ZIP file)
 */
function createGluexPackage(string $sourceDir, string $outputFile): void
{
    $zip = new ZipArchive();

    if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception("Cannot create zip file: $outputFile");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $item->getPathname());

        if ($item->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($item->getPathname(), $relativePath);
        }
    }

    $zip->close();
}

/**
 * Remove directory and all contents
 */
function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

/**
 * Show usage information
 */
function showUsage(): void
{
    echo "Basic Extension Template Build Script\n";
    echo "Usage: php build.php [ExtensionName] [options]\n\n";
    echo "Arguments:\n";
    echo "  ExtensionName Name for the extension (default: BasicExtension)\n\n";
    echo "Options:\n";
    echo "  --help, -h    Show this help message\n";
    echo "  --clean, -c   Clean output directory before building\n\n";
    echo "This script builds the Basic extension template into a .gluex package.\n";
    echo "Example: php build.php MyExtension\n";
}

/**
 * Clean output directory
 */
function cleanOutput(): void
{
    global $outputDir;

    if (is_dir($outputDir)) {
        echo "Cleaning output directory...\n";
        removeDirectory($outputDir);
    }
}

// Handle command line arguments
if (isset($argv)) {
    foreach ($argv as $arg) {
        switch ($arg) {
            case '--help':
            case '-h':
                showUsage();
                exit(0);
            case '--clean':
            case '-c':
                cleanOutput();
                break;
        }
    }
}

// Run the build
try {
    buildTemplate();
} catch (Exception $e) {
    echo "Build failed: " . $e->getMessage() . "\n";
    exit(1);
}
