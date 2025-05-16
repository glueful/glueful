<?php

namespace Tests\Unit\Repository;

use Tests\TestCase;
use Tests\Unit\Repository\Mocks\TestUserRepository;

class AuditLoggerTest extends TestCase
{
    public function testCreateMockAuditLogger(): void
    {
        // This test should pass if the AuditLogger mock is correctly implemented
        $repository = new TestUserRepository();
        $auditLogger = $repository->createMockAuditLoggerForTest(); // Expose the mock for testing
        // Just test that we can call the log method without errors
        $auditLogger->log('info', 'Test message', ['context' => 'test']);
        $this->assertTrue(true); // If we get here without exceptions, the test passes
    }
}
