<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;
use Glueful\Helpers\FileHandler;
use Glueful\Uploader\Storage\StorageInterface;
use Glueful\Uploader\FileUploader;
use Glueful\Auth\AuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\Helpers\AuthTestHelper;
use Tests\Unit\Helpers\TestFileHandler;
use Tests\Unit\Helpers\TestFileUploaderWrapper;

/**
 * Tests for the FileHandler class
 */
class FileHandlerTest extends TestCase
{
    private FileHandler $fileHandler;
    private TestFileUploaderWrapper $uploaderWrapper;
    private MockObject $mockAuth;
    private MockObject $mockStorage;

    protected function setUp(): void
    {
        parent::setUp();

        // Instead of using a real FileUploader, use our TestFileUploaderWrapper
        $this->uploaderWrapper = new TestFileUploaderWrapper();

        // Create mock for AuthenticationService
        $this->mockAuth = $this->createMock(AuthenticationService::class);

        // Create mock for StorageInterface
        $this->mockStorage = $this->createMock(StorageInterface::class);

        // Create the TestFileHandler instance with our wrapper
        $this->fileHandler = new TestFileHandler($this->uploaderWrapper);

        // Directly set the auth property on our TestFileHandler instance
        // No need for reflection since we're using our test subclass that has this property
        $this->fileHandler->auth = $this->mockAuth;
    }

    /**
     * Set the mocked token value for testing using our test helper
     */
    private function setMockedToken(?string $token): void
    {
        AuthTestHelper::setMockedToken($token);
    }

    /**
     * Access a private method for testing
     * Using modern approach that doesn't need setAccessible
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        // In PHP 8+, non-public methods can be invoked via ReflectionMethod
        // without explicitly calling setAccessible
        $method = new ReflectionMethod(get_class($object), $methodName);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Test file upload processing
     */
    public function testFileUpload(): void
    {
        // Set up the mocked token
        $this->setMockedToken('valid-token');

        // Prepare test data
        $getParams = [
            'user_id' => '123',
            'token' => 'valid-token'
        ];

        $fileParams = [
            'file' => [
                'name' => 'test.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error' => 0,
                'size' => 1024
            ]
        ];

        // Set expected result for our wrapper
        $this->uploaderWrapper->setExpectedResult('handleUpload', [
            'success' => true,
            'data' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'url' => 'http://example.com/uploads/test.jpg',
                'name' => 'test.jpg',
                'type' => 'image'
            ],
            'message' => 'File uploaded successfully'
        ]);

        // Execute the method under test
        $result = $this->fileHandler->handleFileUpload($getParams, $fileParams);

        // Assert the expected results
        $this->assertTrue($result['success']);
        $this->assertEquals('File uploaded successfully', $result['message']);
        $this->assertArrayHasKey('uuid', $result['data']);
        $this->assertArrayHasKey('url', $result['data']);

        // Verify that the uploader was called with correct parameters
        $this->assertEquals(1, $this->uploaderWrapper->getCallCount('handleUpload'));
        $this->assertEquals('valid-token', $this->uploaderWrapper->uploadCalls[0]['token']);
        $this->assertEquals($getParams, $this->uploaderWrapper->uploadCalls[0]['getParams']);
        $this->assertEquals($fileParams, $this->uploaderWrapper->uploadCalls[0]['fileParams']);
    }

    /**
     * Test file upload handling when authentication is missing
     */
    public function testFileUploadWithoutAuthentication(): void
    {
        // Set mocked token to null to simulate missing authentication
        $this->setMockedToken(null);

        // Execute the method under test
        $result = $this->fileHandler->handleFileUpload([], []);

        // Assert the expected results
        $this->assertFalse($result['success']);
        $this->assertEquals('Authentication required', $result['message']);
        $this->assertEquals(401, $result['code']);
    }

    /**
     * Test base64 file upload processing
     */
    public function testBase64Upload(): void
    {
        // Set mocked token for authentication
        $this->setMockedToken('valid-token');

        // Prepare test data
        $getParams = [
            'name' => 'encoded.jpg',
            'mime_type' => 'image/jpeg',
            'user_id' => '123'
        ];

        $postParams = [
            'base64' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD...'
        ];

        // Configure expected results for both method calls
        $this->uploaderWrapper->setExpectedResult('handleBase64Upload', '/tmp/generated-temp-file');
        $this->uploaderWrapper->setExpectedResult('handleUpload', [
            'success' => true,
            'data' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'url' => 'http://example.com/uploads/encoded.jpg'
            ],
            'message' => 'Base64 file uploaded successfully'
        ]);

        // Execute the method under test
        $result = $this->fileHandler->handleBase64Upload($getParams, $postParams);

        // Assert the expected results
        $this->assertTrue($result['success']);
        $this->assertEquals('Base64 file uploaded successfully', $result['message']);
        $this->assertArrayHasKey('uuid', $result['data']);
        $this->assertArrayHasKey('url', $result['data']);

        // Verify that both methods were called correctly
        $this->assertEquals(1, $this->uploaderWrapper->getCallCount('handleBase64Upload'));
        $this->assertEquals(1, $this->uploaderWrapper->getCallCount('handleUpload'));
        $this->assertEquals('data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD...', $this->uploaderWrapper->base64UploadCalls[0]['base64']);
    }

    /**
     * Test file retrieval by UUID
     */
    public function testFileRetrievalByUuid(): void
    {
        // Create a mock private method to getBlobInfo
        $fileUuid = '550e8400-e29b-41d4-a716-446655440000';
        $fileInfo = [
            'uuid' => $fileUuid,
            'filename' => 'test-file.jpg',
            'filepath' => 'uploads/2023/01/test-file.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 5000,
            'storage_type' => 'local',
            'status' => 'active'
        ];

        // Create a specific test instance for this test
        $testHandler = new class($this->uploaderWrapper) extends TestFileHandler {
            public function getBlobInfo(string $uuid): ?array {
                if ($uuid === '550e8400-e29b-41d4-a716-446655440000') {
                    return [
                        'uuid' => $uuid,
                        'filename' => 'test-file.jpg',
                        'filepath' => 'uploads/2023/01/test-file.jpg',
                        'mime_type' => 'image/jpeg',
                        'file_size' => 5000,
                        'storage_type' => 'local',
                        'status' => 'active'
                    ];
                }
                return null;
            }

            public function getStorageDriver(string $storageType = 'local') {
                $mockStorage = new class() {
                    public function getUrl(string $path): string {
                        return 'http://example.com/' . $path;
                    }
                };
                return $mockStorage;
            }

            public function getBlobAsFile($storage, array $fileInfo): array {
                return [
                    'uuid' => $fileInfo['uuid'],
                    'url' => 'http://example.com/uploads/test-file.jpg',
                    'name' => 'test-file.jpg',
                    'mime_type' => 'image/jpeg',
                    'size' => 5000,
                    'type' => 'image'
                ];
            }
        };

        // Execute the method using the real getBlob implementation with our overrides
        $result = $testHandler->getBlob($fileUuid, 'info');

        // Assert results
        $this->assertTrue($result['success']);
        $this->assertEquals('File information retrieved', $result['message']);
        $this->assertArrayHasKey('uuid', $result['data']);
        $this->assertArrayHasKey('url', $result['data']);
        $this->assertArrayHasKey('name', $result['data']);
        $this->assertArrayHasKey('mime_type', $result['data']);
    }

    /**
     * Test file retrieval when file not found
     */
    public function testFileRetrievalNotFound(): void
    {
        // Create a mock private method to getBlobInfo
        $fileUuid = 'non-existent-uuid';

        // Create a specific test instance for this test with our overrides
        $testHandler = new class($this->uploaderWrapper) extends TestFileHandler {
            public function getBlobInfo(string $uuid): ?array {
                // Always return null to simulate file not found
                return null;
            }
        };

        // Execute the method using the real implementation with our override
        $result = $testHandler->getBlob($fileUuid, 'info');

        // Assert results
        $this->assertFalse($result['success']);
        $this->assertEquals('File not found', $result['message']);
        $this->assertEquals(404, $result['code']);
    }

    /**
     * Test image processing functionality
     */
    public function testImageProcessing(): void
    {
        $fileUuid = '550e8400-e29b-41d4-a716-446655440000';

        // Image processing parameters
        $params = [
            'w' => 300,
            'h' => 200,
            'q' => 85
        ];

        // Create a specific test instance with our overrides
        $testHandler = new class($this->uploaderWrapper) extends TestFileHandler {
            public function getBlobInfo(string $uuid): ?array {
                if ($uuid === '550e8400-e29b-41d4-a716-446655440000') {
                    return [
                        'uuid' => $uuid,
                        'filename' => 'test-image.jpg',
                        'filepath' => 'uploads/2023/01/test-image.jpg',
                        'mime_type' => 'image/jpeg',
                        'file_size' => 10000,
                        'storage_type' => 'local',
                        'status' => 'active'
                    ];
                }
                return null;
            }

            public function getStorageDriver(string $storageType = 'local') {
                $mockStorage = new class() {
                    public function getUrl(string $path): string {
                        return 'http://example.com/' . $path;
                    }
                };
                return $mockStorage;
            }

            public function processImageBlob(string $src, array $params): array {
                return [
                    'url' => 'http://example.com/cache/images/8f7d8bc7.jpg',
                    'cached' => true,
                    'dimensions' => [
                        'width' => $params['w'],
                        'height' => $params['h']
                    ],
                    'size' => 4500,
                    'params' => $params
                ];
            }
        };

        // Execute the method using the real implementation with our overrides
        $result = $testHandler->getBlob($fileUuid, 'image', $params);

        // Assert results
        $this->assertTrue($result['success']);
        $this->assertEquals('Image processed successfully', $result['message']);
        $this->assertArrayHasKey('url', $result['data']);
        $this->assertArrayHasKey('cached', $result['data']);
        $this->assertArrayHasKey('dimensions', $result['data']);
        $this->assertEquals(300, $result['data']['dimensions']['width']);
        $this->assertEquals(200, $result['data']['dimensions']['height']);
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        // Reset our static mock
        AuthTestHelper::setMockedToken(null);

        parent::tearDown();
    }

    /**
     * Test file type detection
     */
    public function testFileTypeDetection(): void
    {
        // Test various mime types to ensure they're properly categorized
        $imageTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];

        $videoTypes = [
            'video/mp4',
            'video/quicktime',
            'video/mpeg'
        ];

        $documentTypes = [
            'application/pdf',
            'application/msword',
            'text/plain'
        ];

        // Use reflection to access the private method
        // In PHP 8+, we don't need to call setAccessible explicitly
        $method = new ReflectionMethod(FileHandler::class, 'getFileType');

        // Test image types
        foreach ($imageTypes as $mimeType) {
            $result = $method->invoke($this->fileHandler, $mimeType);
            $this->assertEquals('image', $result, "Mime type $mimeType should be categorized as 'image'");
        }

        // Test video types
        foreach ($videoTypes as $mimeType) {
            $result = $method->invoke($this->fileHandler, $mimeType);
            $this->assertEquals('video', $result, "Mime type $mimeType should be categorized as 'video'");
        }

        // Test document types
        foreach ($documentTypes as $mimeType) {
            $result = $method->invoke($this->fileHandler, $mimeType);
            $this->assertEquals('document', $result, "Mime type $mimeType should be categorized as 'document'");
        }
    }
}
