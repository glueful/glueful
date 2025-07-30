<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Transaction;

use Glueful\Database\Transaction\TransactionManager;
use Glueful\Database\Transaction\SavepointManager;
use Glueful\Database\QueryLogger;
use PDO;
use Exception;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * TransactionManager Unit Tests
 *
 * Tests the TransactionManager component in isolation to ensure
 * proper transaction handling functionality.
 */
class TransactionManagerTest extends TestCase
{
    private TransactionManager $transactionManager;
    private PDO|MockObject $mockPdo;
    private SavepointManager|MockObject $mockSavepointManager;
    private QueryLogger|MockObject $mockLogger;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockSavepointManager = $this->createMock(SavepointManager::class);
        $this->mockLogger = $this->createMock(QueryLogger::class);
        
        $this->transactionManager = new TransactionManager(
            $this->mockPdo,
            $this->mockSavepointManager,
            $this->mockLogger
        );
    }

    public function testBeginFirstLevelTransaction(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        
        $this->mockLogger->expects($this->once())
            ->method('logEvent')
            ->with('Transaction started', ['level' => 1], 'debug');

        $this->assertFalse($this->transactionManager->isActive());
        $this->assertEquals(0, $this->transactionManager->getLevel());

        $this->transactionManager->begin();

        $this->assertTrue($this->transactionManager->isActive());
        $this->assertEquals(1, $this->transactionManager->getLevel());
    }

    public function testBeginNestedTransaction(): void
    {
        // Start first transaction
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        
        $this->transactionManager->begin();

        // Start nested transaction (should create savepoint)
        $this->mockSavepointManager->expects($this->once())
            ->method('create')
            ->with(1);

        $this->transactionManager->begin();

        $this->assertEquals(2, $this->transactionManager->getLevel());
    }

    public function testCommitFirstLevelTransaction(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        $this->mockPdo->expects($this->once())
            ->method('commit');

        $this->transactionManager->begin();
        $this->transactionManager->commit();

        $this->assertFalse($this->transactionManager->isActive());
        $this->assertEquals(0, $this->transactionManager->getLevel());
    }

    public function testRollbackFirstLevelTransaction(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        $this->mockPdo->expects($this->once())
            ->method('rollBack');

        $this->transactionManager->begin();
        $this->transactionManager->rollback();

        $this->assertFalse($this->transactionManager->isActive());
        $this->assertEquals(0, $this->transactionManager->getLevel());
    }

    public function testRollbackNestedTransaction(): void
    {
        // Start nested transactions
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        
        $this->transactionManager->begin();
        $this->transactionManager->begin();

        // Rollback nested transaction
        $this->mockSavepointManager->expects($this->once())
            ->method('rollbackTo')
            ->with(1);

        $this->transactionManager->rollback();

        $this->assertTrue($this->transactionManager->isActive());
        $this->assertEquals(1, $this->transactionManager->getLevel());
    }

    public function testSuccessfulTransaction(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        $this->mockPdo->expects($this->once())
            ->method('commit');

        $result = $this->transactionManager->transaction(function() {
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertFalse($this->transactionManager->isActive());
    }

    public function testTransactionWithException(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');
        $this->mockPdo->expects($this->once())
            ->method('rollBack');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test exception');

        $this->transactionManager->transaction(function() {
            throw new Exception('Test exception');
        });

        $this->assertFalse($this->transactionManager->isActive());
    }

    public function testSetAndGetMaxRetries(): void
    {
        $this->assertEquals(3, $this->transactionManager->getMaxRetries());

        $this->transactionManager->setMaxRetries(5);
        $this->assertEquals(5, $this->transactionManager->getMaxRetries());

        // Test negative values are handled
        $this->transactionManager->setMaxRetries(-1);
        $this->assertEquals(0, $this->transactionManager->getMaxRetries());
    }

    public function testCommitWithoutActiveTransaction(): void
    {
        $this->mockLogger->expects($this->once())
            ->method('logEvent')
            ->with('Attempted to commit with no active transaction', [], 'warning');

        $this->transactionManager->commit();
        $this->assertEquals(0, $this->transactionManager->getLevel());
    }

    public function testRollbackWithoutActiveTransaction(): void
    {
        $this->mockLogger->expects($this->once())
            ->method('logEvent')
            ->with('Attempted to rollback with no active transaction', [], 'warning');

        $this->transactionManager->rollback();
        $this->assertEquals(0, $this->transactionManager->getLevel());
    }
}