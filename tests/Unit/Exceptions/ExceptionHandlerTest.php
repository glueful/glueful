<?php

namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Exceptions\ValidationException;
use Glueful\Logging\LogManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test for Exception Handling
 */
class ExceptionHandlerTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&\Glueful\Logging\LogManagerInterface
     */
    private $mockLogManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|LoggerInterface
     */
    private $mockLogger;

    /**
     * @var array<string, \PHPUnit\Framework\MockObject\MockObject|LoggerInterface>
     */
    private $mockLoggers = [];

    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock logger for testing
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        // Create a mock log manager for testing
        $this->mockLogManager = $this->createMock(LogManagerInterface::class);

        // Configure getLogger to return different loggers based on the channel
        $this->mockLogManager->method('getLogger')
            ->willReturnCallback(function ($channel) {
                if (!isset($this->mockLoggers[$channel])) {
                    $this->mockLoggers[$channel] = $this->createMock(LoggerInterface::class);
                }
                return $this->mockLoggers[$channel];
            });

        // Inject the mock log manager
        ExceptionHandler::setLogManager($this->mockLogManager);

        // Enable test mode
        ExceptionHandler::setTestMode(true);
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Reset the log manager
        ExceptionHandler::setLogManager(null);

        // Disable test mode
        ExceptionHandler::setTestMode(false);

        parent::tearDown();
    }

    /**
     * Test handling of ValidationException
     */
    public function testHandleValidationException(): void
    {
        // Arrange
        $errors = [
            'name' => ['The name field is required'],
            'email' => ['The email must be a valid email address']
        ];
        $exception = new ValidationException($errors);

        // Configure the validation logger to expect an error call
        // The mockLogManager will create it when getLogger('validation') is called
        $this->mockLoggers['validation'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['validation']->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($exception->getMessage()),
                $this->callback(function ($context) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']);
                })
            );

        // Enable test mode
        ExceptionHandler::setTestMode(true);

        // Act - Call the handleException method
        ExceptionHandler::handleException($exception);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assert - Check channel mapping and exception properties
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $channelMapProperty = $reflection->getProperty('channelMap');
        $channelMapProperty->setAccessible(true);
        $channelMap = $channelMapProperty->getValue();

        // Assert the channel mapping
        $this->assertEquals('validation', $channelMap[ValidationException::class]);

        // Assert exception properties
        $this->assertEquals($errors, $exception->getErrors());
        $this->assertEquals(422, $exception->getStatusCode());

        // Assert response format
        $this->assertNotNull($responseData);
        $this->assertEquals(422, $responseData['code']);
        $this->assertEquals('Validation failed', $responseData['message']);
        $this->assertEquals($errors, $responseData['data']);
    }
}
