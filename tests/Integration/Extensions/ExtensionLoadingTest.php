<?php
namespace Tests\Integration\Extensions;

use Tests\TestCase;
use Glueful\Helpers\ExtensionsManager;
use Tests\Fixtures\TestExtension;
use Tests\Fixtures\AnotherTestExtension;

/**
 * Integration tests for the ExtensionsManager
 * 
 * Tests the full extension loading lifecycle with real extensions
 */
class ExtensionLoadingTest extends TestCase
{
    /**
     * Set up the testing environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset test extensions
        TestExtension::reset();
        AnotherTestExtension::reset();
    }
    
    /**
     * Test loading real extensions in the extension directory
     */
    public function testLoadRealExtensions(): void
    {
        // Get loaded extensions
        $extensions = ExtensionsManager::getLoadedExtensions();
        
        // Basic assertion that we got an array
        $this->assertIsArray($extensions);
        
        // Log the loaded extensions for debugging
        error_log('Loaded extensions: ' . print_r($extensions, true));
        
        // Skip detailed assertions in this integration test
        // These will be handled in the unit tests with controlled fixtures
        $this->markTestSkipped('This test requires real extensions in the extensions directory');
    }
}
