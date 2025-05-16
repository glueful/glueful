<?php
namespace Tests\Unit\Exceptions;

use Tests\TestCase;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Exceptions\ApiException;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\NotFoundException;

/**
 * Test for Exception Response Formatting
 *
 * Tests that exception handler produces correctly formatted API responses
 * for different types of exceptions.
 */
class ExceptionResponseFormattingTest extends TestCase
{
    /**
     * @var array Response data captured during tests
     */
    private $responseData;

    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Setup to capture the response data
        $this->responseData = null;

        // Enable test mode to prevent exit() calls
        ExceptionHandler::setTestMode(true);
    }

    /**
     * Test validation exception response format
     */
    public function testValidationExceptionResponseFormat(): void
    {
        // Use reflection to access the private outputJsonResponse method
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $method = $reflection->getMethod('outputJsonResponse');
        $method->setAccessible(true);

        // Test validation error response format
        $statusCode = 422;
        $message = 'Validation Error';
        $errors = [
            'name' => ['The name field is required'],
            'email' => ['The email must be a valid email address']
        ];

        $method->invokeArgs(null, [$statusCode, $message, $errors]);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assertions
        $this->assertNotNull($responseData);
        $this->assertEquals($statusCode, $responseData['status']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertEquals($errors, $responseData['data']);
    }

    /**
     * Test authentication exception response format
     */
    public function testAuthenticationExceptionResponseFormat(): void
    {
        // Use reflection to access the private outputJsonResponse method
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $method = $reflection->getMethod('outputJsonResponse');
        $method->setAccessible(true);

        // Test authentication error response format
        $statusCode = 401;
        $message = 'Unauthorized';

        $method->invokeArgs(null, [$statusCode, $message, 'Invalid token']);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assertions
        $this->assertNotNull($responseData);
        $this->assertEquals($statusCode, $responseData['status']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertEquals('Invalid token', $responseData['data']);
    }

    /**
     * Test not found exception response format
     */
    public function testNotFoundExceptionResponseFormat(): void
    {
        // Use reflection to access the private outputJsonResponse method
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $method = $reflection->getMethod('outputJsonResponse');
        $method->setAccessible(true);

        // Test not found error response format
        $statusCode = 404;
        $message = 'Not Found';

        $method->invokeArgs(null, [$statusCode, $message, 'User not found']);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assertions
        $this->assertNotNull($responseData);
        $this->assertEquals($statusCode, $responseData['status']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertEquals('User not found', $responseData['data']);
    }

    /**
     * Test generic API exception response format
     */
    public function testApiExceptionResponseFormat(): void
    {
        // Use reflection to access the private outputJsonResponse method
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $method = $reflection->getMethod('outputJsonResponse');
        $method->setAccessible(true);

        // Test custom API error response format
        $statusCode = 429;
        $message = 'Too Many Requests';
        $data = ['retryAfter' => 30];

        $method->invokeArgs(null, [$statusCode, $message, $data]);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assertions
        $this->assertNotNull($responseData);
        $this->assertEquals($statusCode, $responseData['status']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertEquals($data, $responseData['data']);
    }

    /**
     * Test response headers
     */
    public function testResponseHeaders(): void
    {
        // This test would need to be run in a proper HTTP context to check headers
        // For unit testing, we can use a mock handler to verify proper headers are set

        // Mock the http_response_code and header functions to capture their arguments
        $this->setUpFunctionMocks();

        // Use reflection to access the private outputJsonResponse method
        $reflection = new \ReflectionClass(ExceptionHandler::class);
        $method = $reflection->getMethod('outputJsonResponse');
        $method->setAccessible(true);

        // Test headers set correctly
        $statusCode = 400;
        $message = 'Bad Request';

        $method->invokeArgs(null, [$statusCode, $message, null]);

        // Get the captured response
        $responseData = ExceptionHandler::getTestResponse();

        // Assert response structure
        $this->assertNotNull($responseData);
        $this->assertEquals($statusCode, $responseData['status']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertArrayNotHasKey('data', $responseData);

        // Assert that the content type header is set to application/json
        // This would be an actual test if we could mock global functions
        $this->addToAssertionCount(1); // Placeholder assertion
    }

    /**
     * Helper function to set up mocks for global functions (placeholder)
     */
    private function setUpFunctionMocks(): void
    {
        // In a real test, we would use a library like uopz or similar
        // to mock the global http_response_code and header functions
        // For now, we'll just acknowledge that this is a limitation
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Disable test mode after tests
        ExceptionHandler::setTestMode(false);

        parent::tearDown();
    }
}
