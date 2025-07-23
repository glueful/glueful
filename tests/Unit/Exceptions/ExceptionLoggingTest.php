<?php

namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Exceptions\ApiException;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Test for Exception Logging Integration
 */
class ExceptionLoggingTest extends TestCase
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
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Reset the logger and event dispatcher
        ExceptionHandler::setLogger(null);
        ExceptionHandler::setEventDispatcher(null);

        parent::tearDown();
    }

    /**
     * Test logging of validation exceptions
     */
    public function testLoggingOfValidationExceptions(): void
    {
        // Create a validation exception
        $errors = [
            'name' => ['The name field is required'],
            'email' => ['The email must be a valid email address']
        ];
        $exception = new ValidationException($errors);

        // Expect the logger to be called once
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Validation failed'),
                $this->callback(function ($context) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']);
                })
            );

        // Call logError
        ExceptionHandler::logError($exception);
    }

    /**
     * Test logging of authentication exceptions
     */
    public function testLoggingOfAuthenticationExceptions(): void
    {
        // Create an authentication exception
        $message = 'Invalid credentials';
        $exception = new AuthenticationException($message);

        // Expect the logger to be called once
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($message),
                $this->callback(function ($context) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']);
                })
            );

        // Call logError
        ExceptionHandler::logError($exception);
    }

    /**
     * Test logging of not found exceptions
     */
    public function testLoggingOfNotFoundException(): void
    {
        // Create a not found exception
        $resourceName = 'User';
        $exception = new NotFoundException($resourceName);

        // Expect the logger to be called once
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo("$resourceName not found"),
                $this->callback(function ($context) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']);
                })
            );

        // Call logError
        ExceptionHandler::logError($exception);
    }

    /**
     * Test logging of API exceptions
     */
    public function testLoggingOfApiExceptions(): void
    {
        // Create an API exception
        $message = 'Custom API error';
        $statusCode = 429;
        $data = ['retryAfter' => 30];
        $exception = new ApiException($message, $statusCode, $data);

        // Expect the logger to be called once
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($message),
                $this->callback(function ($context) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']);
                })
            );

        // Call logError
        ExceptionHandler::logError($exception);
    }

    /**
     * Test fallback to error_log when logger fails
     */
    public function testFallbackToErrorLog(): void
    {
        // Create an exception
        $message = 'Test exception';
        $exception = new ApiException($message);

        // Configure the mock logger to throw an exception
        /** @var \PHPUnit\Framework\MockObject\MockObject&LoggerInterface $failingLogger */
        $failingLogger = $this->createMock(LoggerInterface::class);
        $failingLogger->method('error')
            ->willThrowException(new \Exception('Logger failed'));

        // Inject the failing logger
        ExceptionHandler::setLogger($failingLogger);

        // Capture error_log output
        $errorLogFile = tempnam(sys_get_temp_dir(), 'phpunit_');
        $originalErrorLog = ini_set('error_log', $errorLogFile);

        // Call logError - this should fallback to error_log
        ExceptionHandler::logError($exception);

        // Restore original error_log setting
        ini_set('error_log', $originalErrorLog);

        // Check if error_log was called
        $logContent = file_get_contents($errorLogFile);
        $this->assertStringContainsString($message, $logContent);

        // Clean up
        unlink($errorLogFile);
    }

    /**
     * Test custom context in logging
     */
    public function testCustomContextInLogging(): void
    {
        // Create an exception
        $message = 'Test exception';
        $exception = new ApiException($message);

        // Custom context to add
        $customContext = [
            'user_id' => 123,
            'request_id' => 'abcd-1234',
            'additional_info' => 'Custom error context'
        ];

        // Expect the logger to be called once with the custom context
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($message),
                $this->callback(function ($context) use ($customContext) {
                    return isset($context['file']) &&
                           isset($context['line']) &&
                           isset($context['trace']) &&
                           isset($context['type']) &&
                           isset($context['user_id']) &&
                           $context['user_id'] === $customContext['user_id'] &&
                           isset($context['request_id']) &&
                           $context['request_id'] === $customContext['request_id'];
                })
            );

        // Call logError with custom context
        ExceptionHandler::logError($exception, $customContext);
    }
}
