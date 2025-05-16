<?php

namespace Tests\Helpers;

use Glueful\Logging\AuditLogger;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Helper class to mock the AuditLogger singleton for tests
 *
 * This prevents test code from connecting to a real database when using the AuditLogger
 */
class AuditLoggerMock
{
    /**
     * Setup a mock instance of the AuditLogger singleton
     *
     * @param \PHPUnit\Framework\TestCase $testCase The test case to create the mock from
     * @return MockObject The mocked AuditLogger instance
     */
    public static function setup(\PHPUnit\Framework\TestCase $testCase): MockObject
    {
        // Create a mock of the AuditLogger
        $mockAuditLogger = $testCase->getMockBuilder(AuditLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'audit', 'authEvent', 'dataEvent', 'configEvent',
                'generateComplianceReport'
            ])
            ->getMock();

        // Replace the singleton instance with our mock using reflection
        $reflectionClass = new \ReflectionClass(AuditLogger::class);
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, $mockAuditLogger);

        return $mockAuditLogger;
    }

    /**
     * Reset the AuditLogger singleton
     */
    public static function reset(): void
    {
        $reflectionClass = new \ReflectionClass(AuditLogger::class);
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }
}
