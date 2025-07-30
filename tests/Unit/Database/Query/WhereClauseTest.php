<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Query;

use Glueful\Database\Query\WhereClause;
use Glueful\Database\Driver\DatabaseDriver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * WhereClause Unit Tests
 *
 * Tests the WhereClause component in isolation to ensure
 * proper WHERE clause building functionality.
 */
class WhereClauseTest extends TestCase
{
    private WhereClause $whereClause;
    private DatabaseDriver|MockObject $mockDriver;

    protected function setUp(): void
    {
        $this->mockDriver = $this->createMock(DatabaseDriver::class);
        $this->mockDriver->method('wrapIdentifier')
            ->willReturnCallback(fn($identifier) => "`{$identifier}`");
        
        $this->whereClause = new WhereClause($this->mockDriver);
    }

    public function testAddBasicCondition(): void
    {
        $this->whereClause->add('status', '=', 'active');
        
        $this->assertEquals(' WHERE `status` = ?', $this->whereClause->toSql());
        $this->assertEquals(['active'], $this->whereClause->getBindings());
        $this->assertTrue($this->whereClause->hasConditions());
    }

    public function testAddConditionTwoParameters(): void
    {
        $this->whereClause->add('id', 123);
        
        $this->assertEquals(' WHERE `id` = ?', $this->whereClause->toSql());
        $this->assertEquals([123], $this->whereClause->getBindings());
    }

    public function testAddArrayConditions(): void
    {
        $this->whereClause->add(['status' => 'active', 'role' => 'admin']);
        
        $this->assertEquals(' WHERE `status` = ? AND `role` = ?', $this->whereClause->toSql());
        $this->assertEquals(['active', 'admin'], $this->whereClause->getBindings());
    }

    public function testAddOrCondition(): void
    {
        $this->whereClause->add('status', 'active');
        $this->whereClause->addOr('role', 'admin');
        
        $this->assertEquals(' WHERE `status` = ? OR `role` = ?', $this->whereClause->toSql());
        $this->assertEquals(['active', 'admin'], $this->whereClause->getBindings());
    }

    public function testWhereIn(): void
    {
        $this->whereClause->whereIn('id', [1, 2, 3]);
        
        $this->assertEquals(' WHERE `id` IN (?, ?, ?)', $this->whereClause->toSql());
        $this->assertEquals([1, 2, 3], $this->whereClause->getBindings());
    }

    public function testWhereInWithEmptyArray(): void
    {
        $this->whereClause->whereIn('id', []);
        
        $this->assertEquals(' WHERE 1 = 0', $this->whereClause->toSql());
        $this->assertEquals([], $this->whereClause->getBindings());
    }

    public function testWhereNotIn(): void
    {
        $this->whereClause->whereNotIn('id', [1, 2, 3]);
        
        $this->assertEquals(' WHERE `id` NOT IN (?, ?, ?)', $this->whereClause->toSql());
        $this->assertEquals([1, 2, 3], $this->whereClause->getBindings());
    }

    public function testWhereNotInWithEmptyArray(): void
    {
        $this->whereClause->whereNotIn('id', []);
        
        $this->assertEquals('', $this->whereClause->toSql());
        $this->assertEquals([], $this->whereClause->getBindings());
        $this->assertFalse($this->whereClause->hasConditions());
    }

    public function testWhereNull(): void
    {
        $this->whereClause->whereNull('deleted_at');
        
        $this->assertEquals(' WHERE `deleted_at` IS NULL', $this->whereClause->toSql());
        $this->assertEquals([], $this->whereClause->getBindings());
    }

    public function testWhereNotNull(): void
    {
        $this->whereClause->whereNotNull('deleted_at');
        
        $this->assertEquals(' WHERE `deleted_at` IS NOT NULL', $this->whereClause->toSql());
        $this->assertEquals([], $this->whereClause->getBindings());
    }

    public function testWhereBetween(): void
    {
        $this->whereClause->whereBetween('age', 18, 65);
        
        $this->assertEquals(' WHERE `age` BETWEEN ? AND ?', $this->whereClause->toSql());
        $this->assertEquals([18, 65], $this->whereClause->getBindings());
    }

    public function testWhereLike(): void
    {
        $this->whereClause->whereLike('name', '%john%');
        
        $this->assertEquals(' WHERE `name` LIKE ?', $this->whereClause->toSql());
        $this->assertEquals(['%john%'], $this->whereClause->getBindings());
    }

    public function testWhereRaw(): void
    {
        $this->whereClause->whereRaw('YEAR(created_at) = ?', [2024]);
        
        $this->assertEquals(' WHERE YEAR(created_at) = ?', $this->whereClause->toSql());
        $this->assertEquals([2024], $this->whereClause->getBindings());
    }

    public function testMultipleConditions(): void
    {
        $this->whereClause->add('status', 'active');
        $this->whereClause->add('role', 'admin');
        $this->whereClause->whereIn('department_id', [1, 2, 3]);
        
        $expectedSql = ' WHERE `status` = ? AND `role` = ? AND `department_id` IN (?, ?, ?)';
        $this->assertEquals($expectedSql, $this->whereClause->toSql());
        $this->assertEquals(['active', 'admin', 1, 2, 3], $this->whereClause->getBindings());
    }

    public function testTableDotColumnWrapping(): void
    {
        $this->whereClause->add('users.status', 'active');
        
        $this->assertEquals(' WHERE `users`.`status` = ?', $this->whereClause->toSql());
        $this->assertEquals(['active'], $this->whereClause->getBindings());
    }

    public function testNestedConditions(): void
    {
        $this->whereClause->add('status', 'active');
        $this->whereClause->add(function($query) {
            $query->add('role', 'admin');
            $query->addOr('role', 'manager');
        });
        
        $expectedSql = ' WHERE `status` = ? AND (`role` = ? OR `role` = ?)';
        $this->assertEquals($expectedSql, $this->whereClause->toSql());
        $this->assertEquals(['active', 'admin', 'manager'], $this->whereClause->getBindings());
    }

    public function testReset(): void
    {
        $this->whereClause->add('status', 'active');
        $this->whereClause->whereIn('id', [1, 2, 3]);
        
        $this->assertTrue($this->whereClause->hasConditions());
        $this->assertNotEmpty($this->whereClause->getBindings());
        
        $this->whereClause->reset();
        
        $this->assertFalse($this->whereClause->hasConditions());
        $this->assertEquals([], $this->whereClause->getBindings());
        $this->assertEquals('', $this->whereClause->toSql());
    }

    public function testHasConditions(): void
    {
        $this->assertFalse($this->whereClause->hasConditions());
        
        $this->whereClause->add('status', 'active');
        $this->assertTrue($this->whereClause->hasConditions());
        
        $this->whereClause->reset();
        $this->assertFalse($this->whereClause->hasConditions());
    }

    public function testEmptyWhereClause(): void
    {
        $this->assertEquals('', $this->whereClause->toSql());
        $this->assertEquals([], $this->whereClause->getBindings());
        $this->assertFalse($this->whereClause->hasConditions());
    }
}