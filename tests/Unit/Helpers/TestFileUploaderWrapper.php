<?php
namespace Tests\Unit\Helpers;

/**
 * A test wrapper for FileUploader that can be used in tests
 * Since the original FileUploader class is final, we can't mock it directly
 * This class provides a way to intercept calls to FileUploader methods for testing
 */
class TestFileUploaderWrapper
{
    // Track method calls for testing
    public array $uploadCalls = [];
    public array $base64UploadCalls = [];
    public array $expectedResults = [];

    /**
     * Record a call to handleUpload and return the expected result
     */
    public function handleUpload(string $token, array $getParams, array $fileParams): array
    {
        $this->uploadCalls[] = [
            'token' => $token,
            'getParams' => $getParams,
            'fileParams' => $fileParams
        ];

        return $this->expectedResults['handleUpload'] ?? [
            'success' => true,
            'data' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'url' => 'http://example.com/uploads/test.jpg',
                'name' => 'test.jpg',
                'type' => 'image'
            ],
            'message' => 'File uploaded successfully'
        ];
    }

    /**
     * Record a call to handleBase64Upload and return the expected result
     */
    public function handleBase64Upload(string $base64): string
    {
        $this->base64UploadCalls[] = [
            'base64' => $base64
        ];

        return $this->expectedResults['handleBase64Upload'] ?? '/tmp/generated-temp-file';
    }

    /**
     * Set the expected result for a method
     */
    public function setExpectedResult(string $method, mixed $result): self
    {
        $this->expectedResults[$method] = $result;
        return $this;
    }

    /**
     * Get the number of calls to a method
     */
    public function getCallCount(string $method): int
    {
        if ($method === 'handleUpload') {
            return count($this->uploadCalls);
        } elseif ($method === 'handleBase64Upload') {
            return count($this->base64UploadCalls);
        }

        return 0;
    }
}
