<?php
namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Exceptions\ApiException;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\NotFoundException;
use Glueful\Logging\LogManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test for Exception Logging Integration
 */
class ExceptionLoggingTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|LogManagerInterface
     */
    private $mockLogManager;

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

        // Create a mock log manager
        $this->mockLogManager = $this->createMock(LogManagerInterface::class);

        // Configure the mock log manager's getLogger method
        $this->mockLogManager->method('getLogger')
            ->will($this->returnCallback(function($channel) {
                if (!isset($this->mockLoggers[$channel])) {
                    $this->mockLoggers[$channel] = $this->createMock(LoggerInterface::class);
                }
                return $this->mockLoggers[$channel];
            }));

        // Inject the mock log manager
        ExceptionHandler::setLogManager($this->mockLogManager instanceof LogManagerInterface ? $this->mockLogManager : null);
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Reset the log manager
        ExceptionHandler::setLogManager(null);

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

        // Expect the validation logger to be called once
        $this->mockLoggers['validation'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['validation']->expects($this->once())
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

        // Expect the auth logger to be called once
        $this->mockLoggers['auth'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['auth']->expects($this->once())
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

        // Expect the http logger to be called once
        $this->mockLoggers['http'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['http']->expects($this->once())
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

        // Expect the api logger to be called once
        $this->mockLoggers['api'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['api']->expects($this->once())
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

        // Configure the mock log manager to throw an exception
        $this->mockLogManager = $this->createMock(LogManagerInterface::class);
        $this->mockLogManager->method('getLogger')
            ->willThrowException(new \Exception('Logger failed'));

        // Inject the failing log manager
        ExceptionHandler::setLogManager($this->mockLogManager instanceof LogManagerInterface ? $this->mockLogManager : null);

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

        // Expect the api logger to be called once with the custom context
        $this->mockLoggers['api'] = $this->createMock(LoggerInterface::class);
        $this->mockLoggers['api']->expects($this->once())
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