<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Tests\TestCase;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tests for the new Response API
 * 
 * Verifies that all Response methods create proper Symfony JsonResponse objects
 * with the correct status codes, headers, and content structure.
 */
class ResponseTest extends TestCase
{
    /**
     * Test Response::success() method
     */
    public function testSuccessResponse(): void
    {
        $data = ['user' => 'John Doe', 'email' => 'john@example.com'];
        $message = 'User retrieved successfully';
        
        $response = Response::success($data, $message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(200, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertTrue($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertEquals($data, $content['data']);
    }

    /**
     * Test Response::error() method
     */
    public function testErrorResponse(): void
    {
        $message = 'An error occurred';
        $statusCode = 400;
        $details = ['field' => 'Invalid value'];
        
        $response = Response::error($message, $statusCode, $details);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals($statusCode, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertFalse($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals($statusCode, $content['error']['code']);
        $this->assertEquals($details, $content['error']['details']);
    }

    /**
     * Test Response::created() method
     */
    public function testCreatedResponse(): void
    {
        $data = ['id' => 123, 'name' => 'New User'];
        $message = 'User created successfully';
        
        $response = Response::created($data, $message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(201, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertTrue($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertEquals($data, $content['data']);
    }

    /**
     * Test Response::notFound() method
     */
    public function testNotFoundResponse(): void
    {
        $message = 'User not found';
        
        $response = Response::notFound($message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(404, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertFalse($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals(404, $content['error']['code']);
    }

    /**
     * Test Response::forbidden() method
     */
    public function testForbiddenResponse(): void
    {
        $message = 'Access denied';
        
        $response = Response::forbidden($message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(403, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertFalse($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals(403, $content['error']['code']);
    }

    /**
     * Test Response::unauthorized() method
     */
    public function testUnauthorizedResponse(): void
    {
        $message = 'Authentication required';
        
        $response = Response::unauthorized($message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(401, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertFalse($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals(401, $content['error']['code']);
    }

    /**
     * Test Response::validation() method
     */
    public function testValidationResponse(): void
    {
        $message = 'Validation failed';
        $errors = [
            'name' => ['The name field is required'],
            'email' => ['The email must be valid']
        ];
        
        $response = Response::validation($errors, $message);
        
        // Assert it's a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        
        // Assert status code
        $this->assertEquals(422, $response->getStatusCode());
        
        // Assert content structure
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertFalse($content['success']);
        $this->assertEquals($message, $content['message']);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals($errors, $content['error']['details']);
        $this->assertEquals(422, $content['error']['code']);
    }

    /**
     * Test Response methods with null data
     */
    public function testResponsesWithNullData(): void
    {
        $response = Response::success(null, 'Success with no data');
        $content = json_decode($response->getContent(), true);
        $this->assertEquals([], $content['data']); // null converts to empty array

        $response = Response::error('Error with no data');
        $content = json_decode($response->getContent(), true);
        $this->assertArrayNotHasKey('details', $content['error']); // No details when null
    }

    /**
     * Test Response headers are properly set
     */
    public function testResponseHeaders(): void
    {
        $response = Response::success(['test' => 'data']);
        
        // Assert Content-Type header
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        
        // Assert that response is properly formatted JSON
        $this->assertJson($response->getContent());
    }

    /**
     * Test Response with custom status codes
     */
    public function testCustomStatusCodes(): void
    {
        $response = Response::error('Custom error', 418, ['teapot' => true]);
        
        $this->assertEquals(418, $response->getStatusCode());
        
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(418, $content['error']['code']);
    }

    /**
     * Test Response methods maintain consistent structure
     */
    public function testConsistentResponseStructure(): void
    {
        $responses = [
            Response::success(['data' => 'test']),
            Response::error('Test error'),
            Response::created(['id' => 1]),
            Response::notFound('Not found'),
            Response::forbidden('Forbidden'),
            Response::unauthorized('Unauthorized'),
        ];

        foreach ($responses as $response) {
            $content = json_decode($response->getContent(), true);
            
            // All responses should have these basic fields
            $this->assertArrayHasKey('success', $content);
            $this->assertArrayHasKey('message', $content);
            $this->assertIsBool($content['success']);
            $this->assertIsString($content['message']);
            
            // Error responses should have an error object with code
            if (!$content['success']) {
                $this->assertArrayHasKey('error', $content);
                $this->assertArrayHasKey('code', $content['error']);
                $this->assertIsInt($content['error']['code']);
            }
        }
    }

    /**
     * Test that Response::validation has errors in details field
     */
    public function testValidationStructure(): void
    {
        $errors = ['field' => ['error message']];
        $response = Response::validation($errors, 'Validation failed');
        
        $content = json_decode($response->getContent(), true);
        
        // Should have 'error.details' field containing errors
        $this->assertArrayHasKey('error', $content);
        $this->assertArrayHasKey('details', $content['error']);
        $this->assertEquals($errors, $content['error']['details']);
    }
}