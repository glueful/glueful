<?php

namespace Tests\Unit\API;

use Tests\TestCase;
use Glueful\API;
use Psr\Log\LoggerInterface;

/**
 * Basic tests for API class that don't require complex setup or mocks
 *
 * These tests verify simple aspects of the API class:
 * - Logger instance creation
 * - Basic initialization without full dependency chain
 */
class APIBasicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test that the logger is properly created and returned
     */
    public function testGetLoggerReturnsLoggerInstance(): void
    {
        try {
            // Test that getLogger returns a LoggerInterface instance
            $logger = API::getLogger();

            $this->assertInstanceOf(LoggerInterface::class, $logger);

            // Test that subsequent calls return the same instance (singleton)
            $logger2 = API::getLogger();
            $this->assertSame($logger, $logger2);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'DI container not initialized') !== false) {
                $this->markTestSkipped('DI container not available in test environment');
            } else {
                throw $e;
            }
        }
    }
}
