<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use Tests\TestCase;

/**
 * Base test case for Repository tests
 * 
 * Ensures proper isolation between repository tests by clearing
 * any shared connection state that might persist between tests.
 */
abstract class RepositoryTestCase extends TestCase
{
    /**
     * Setup before each test - ensures clean state
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear any shared connection from BaseRepository to ensure test isolation
        $this->clearSharedConnection();
    }
    
    /**
     * Teardown after each test - ensures clean state for next test
     */
    protected function tearDown(): void
    {
        // Clear shared connection again to prevent pollution
        $this->clearSharedConnection();
        
        parent::tearDown();
    }
    
    /**
     * Clear the shared connection in BaseRepository
     */
    private function clearSharedConnection(): void
    {
        try {
            $reflection = new \ReflectionClass(\Glueful\Repository\BaseRepository::class);
            $sharedConnectionProperty = $reflection->getProperty('sharedConnection');
            $sharedConnectionProperty->setAccessible(true);
            $sharedConnectionProperty->setValue(null, null);
            
            // Also clear any other static properties that might exist
            // Force garbage collection to ensure no lingering connections
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        } catch (\ReflectionException $e) {
            // If we can't clear it, that's OK - the property might not exist
        }
    }
}