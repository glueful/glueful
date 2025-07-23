<?php

namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Test for Exception Handling
 */
class ExceptionHandlerTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&LoggerInterface
     */
    private $mockLogger;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&EventDispatcherInterface
     */
    private $mockEventDispatcher;

    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock logger for testing
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        // Create a mock event dispatcher for testing
        $this->mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Inject the mock logger and event dispatcher
        ExceptionHandler::setLogger($this->mockLogger);
        ExceptionHandler::setEventDispatcher($this->mockEventDispatcher);

        // Enable test mode
        ExceptionHandler::setTestMode(true);
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Reset the logger and event dispatcher
        ExceptionHandler::setLogger(null);
        ExceptionHandler::setEventDispatcher(null);

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

        // Configure the mock logger to expect an error call
        $this->mockLogger->expects($this->once())
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
