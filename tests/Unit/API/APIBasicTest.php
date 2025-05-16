<?php
namespace Tests\Unit\API;

use Tests\TestCase;
use Glueful\API;
use Glueful\Logging\LogManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Basic tests for API class that don't require complex setup or mocks
 *
 * These tests verify simple aspects of the API class:
 * - Logger instance creation
 * - Basic initialization without full dependency chain
 */
class APIBasicTest extends TestCase
{
    /**
     * @var LogManager|MockObject
     */
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock logger
        $this->mockLogger = $this->createMock(LogManager::class);

        // Replace the global logger instance
        $GLOBALS['logger'] = $this->mockLogger;
    }

    protected function tearDown(): void
    {
        // Clean up globals
        unset($GLOBALS['logger']);

        parent::tearDown();
    }

    /**
     * Test that the logger is properly created and returned
     */
    public function testGetLoggerReturnsLoggerInstance(): void
    {
        // The global mock logger should be returned
        $logger = API::getLogger();

        $this->assertSame($this->mockLogger, $logger);

        // If global logger is not set, it should create a new one
        unset($GLOBALS['logger']);
        $newLogger = API::getLogger();

        $this->assertInstanceOf(LogManager::class, $newLogger);
    }
}
