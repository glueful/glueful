<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Driver\SQLiteDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Database Mock Helper
 *
 * Provides utilities for mocking database components in unit tests
 * to prevent actual database connections and operations.
 */
class DatabaseMock
{
    /**
     * Create and configure a mock QueryBuilder instance
     *
     * @param TestCase $testCase The test case instance for creating mocks
     * @return MockObject The configured mock QueryBuilder
     */
    public static function createMockQueryBuilder(TestCase $testCase): MockObject
    {
        // Create mock PDO
        $mockPDO = $testCase->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Create mock driver
        $mockDriver = $testCase->getMockBuilder(SQLiteDriver::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Create and configure QueryBuilder mock
        $mockQueryBuilder = $testCase->getMockBuilder(QueryBuilder::class)
            ->setConstructorArgs([$mockPDO, $mockDriver])
            ->getMock();

        // Configure common method chains to return self for method chaining
        $mockQueryBuilder->method('select')->willReturnSelf();
        $mockQueryBuilder->method('where')->willReturnSelf();
        $mockQueryBuilder->method('join')->willReturnSelf();
        $mockQueryBuilder->method('limit')->willReturnSelf();

        // Do not set default return values for get/update/delete/insert
        // Let individual tests set these expectations

        return $mockQueryBuilder;
    }

    /**
     * Inject mocks into a repository instance
     *
     * @param object $repository The repository instance
     * @param MockObject $mockQueryBuilder The mock QueryBuilder to inject
     * @return void
     */
    public static function injectMocksIntoRepository(object $repository, MockObject $mockQueryBuilder): void
    {
        $reflectionClass = new \ReflectionClass(get_parent_class($repository));

        // Set the db property (QueryBuilder)
        $dbProperty = $reflectionClass->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($repository, $mockQueryBuilder);

        // Set the table property if it's not already set
        $tableProperty = $reflectionClass->getProperty('table');
        $tableProperty->setAccessible(true);
        if (!$tableProperty->isInitialized($repository)) {
            $tableProperty->setValue($repository, 'mock_table');
        }
    }
}
