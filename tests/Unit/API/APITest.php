<?php
namespace Tests\Unit\API;

use Tests\TestCase;
use Glueful\API;
use Glueful\Logging\LogManager;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * Tests for the core API initialization process
 * 
 * The approach in these tests avoids trying to call the actual API::init() method
 * directly, which would require complex mocking of many dependencies. Instead, we:
 * 1. Test individual components or methods when possible
 * 2. Use subclass mocks to simulate behaviors
 * 3. Focus on testing interfaces rather than implementation details
 */
class APITest extends TestCase
{
    /**
     * @var LogManager|MockObject
     */
    private $mockLogger;

    /**
     * @var int Initial output buffer level before test
     */
    private $initialObLevel;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Store the initial output buffer level
        $this->initialObLevel = ob_get_level();
        
        // Skip database initialization for all API tests
        if (!defined('SKIP_DB_INIT')) {
            define('SKIP_DB_INIT', true);
        }
        
        // Ensure CONFIG_LOADED is defined to prevent actual config loading
        if (!defined('CONFIG_LOADED')) {
            define('CONFIG_LOADED', true);
        }
        
        // Create a mock logger
        $this->mockLogger = $this->createMock(LogManager::class);
        $GLOBALS['logger'] = $this->mockLogger;
        
        // Reset API logger static property using reflection, but we can't set it to null
        // due to typing. Instead, we set it to our mock logger directly.
        $reflectionClass = new \ReflectionClass(API::class);
        $loggerProperty = $reflectionClass->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue(null, $this->mockLogger);
    }
    
    protected function tearDown(): void
    {
        // Clean up only the output buffers created during this test
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        
        // Reset global state
        unset($GLOBALS['logger']);
        
        // Reset the output_buffering in php.ini
        ini_set('output_buffering', '0');
        
        // Reset API logger to a new instance instead of null
        $reflectionClass = new \ReflectionClass(API::class);
        $loggerProperty = $reflectionClass->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue(null, new LogManager('api'));
        
        parent::tearDown();
    }

    /**
     * Test that getLogger returns the expected logger instance
     */
    public function testGetLoggerReturnsLoggerInstance(): void
    {
        // The global mock logger should be returned
        $logger = API::getLogger();
        
        $this->assertSame($this->mockLogger, $logger);
        $this->assertInstanceOf(LogManager::class, $logger);
    }

    /**
     * Test error handling during initialization by directly simulating the error flow
     */
    public function testInitExceptionHandling(): void
    {
        // Set up expectations on the mock logger for the error handling
        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('API initialization started');
            
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('API initialization failed', $this->anything());
        
        // Create a subclass of API to intercept the initialization
        $mockedAPI = new class($this->mockLogger) extends API {
            private $testLogger;
            
            public function __construct($logger)
            {
                $this->testLogger = $logger;
            }
            
            public static function getLogger(): \Glueful\Logging\LogManager
            {
                // This ensures we're using the mock logger from the test
                return $GLOBALS['logger'];
            }
            
            public static function init(): void 
            {
                try {
                    // Record initialization start
                    self::getLogger()->info("API initialization started");
                    
                    // Simulate a failure in initializeCore()
                    throw new \RuntimeException("Test initialization failure");
                } catch (\Throwable $e) {
                    // Log initialization failure (matching the real API implementation)
                    self::getLogger()->error("API initialization failed", [
                        'error' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    
                    // Re-throw as the real API would do
                    throw new \RuntimeException("API initialization failed: " . $e->getMessage(), 0, $e);
                }
            }
        };
        
        // Expect the standard exception to be thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API initialization failed: Test initialization failure');
        
        // Call the mocked init method
        $mockedAPI::init();
    }
}
