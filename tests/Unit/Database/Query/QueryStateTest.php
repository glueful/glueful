<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Query;

use Glueful\Database\Query\QueryState;
use PHPUnit\Framework\TestCase;

/**
 * QueryState Unit Tests
 *
 * Tests the QueryState component in isolation to ensure
 * proper state management functionality.
 */
class QueryStateTest extends TestCase
{
    private QueryState $queryState;

    protected function setUp(): void
    {
        $this->queryState = new QueryState();
    }

    public function testSetAndGetTable(): void
    {
        $this->assertNull($this->queryState->getTable());

        $this->queryState->setTable('users');
        $this->assertEquals('users', $this->queryState->getTable());
    }

    public function testGetTableOrFail(): void
    {
        // Should throw exception when no table is set
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No table specified. Use from(\'table_name\') or table(\'table_name\') to set the table.');
        
        $this->queryState->getTableOrFail();
    }

    public function testGetTableOrFailWithTable(): void
    {
        $this->queryState->setTable('users');
        $this->assertEquals('users', $this->queryState->getTableOrFail());
    }

    public function testSetAndGetSelectColumns(): void
    {
        // Default should be ['*']
        $this->assertEquals(['*'], $this->queryState->getSelectColumns());

        $columns = ['id', 'name', 'email'];
        $this->queryState->setSelectColumns($columns);
        $this->assertEquals($columns, $this->queryState->getSelectColumns());
    }

    public function testSetSelectColumnsWithEmptyArray(): void
    {
        $this->queryState->setSelectColumns([]);
        $this->assertEquals(['*'], $this->queryState->getSelectColumns());
    }

    public function testSetAndGetDistinct(): void
    {
        $this->assertFalse($this->queryState->isDistinct());

        $this->queryState->setDistinct(true);
        $this->assertTrue($this->queryState->isDistinct());

        $this->queryState->setDistinct(false);
        $this->assertFalse($this->queryState->isDistinct());
    }

    public function testSetDistinctDefaultsToTrue(): void
    {
        $this->queryState->setDistinct();
        $this->assertTrue($this->queryState->isDistinct());
    }

    public function testAddAndGetJoins(): void
    {
        $this->assertEquals([], $this->queryState->getJoins());

        $joinData = [
            'type' => 'INNER',
            'table' => 'profiles',
            'first' => 'users.id',
            'operator' => '=',
            'second' => 'profiles.user_id'
        ];

        $this->queryState->addJoin($joinData);
        $this->assertEquals([$joinData], $this->queryState->getJoins());

        // Add another join
        $joinData2 = [
            'type' => 'LEFT',
            'table' => 'roles',
            'first' => 'users.role_id',
            'operator' => '=',
            'second' => 'roles.id'
        ];

        $this->queryState->addJoin($joinData2);
        $this->assertEquals([$joinData, $joinData2], $this->queryState->getJoins());
    }

    public function testSetAndGetLimit(): void
    {
        $this->assertNull($this->queryState->getLimit());

        $this->queryState->setLimit(10);
        $this->assertEquals(10, $this->queryState->getLimit());

        $this->queryState->setLimit(null);
        $this->assertNull($this->queryState->getLimit());
    }

    public function testSetAndGetOffset(): void
    {
        $this->assertNull($this->queryState->getOffset());

        $this->queryState->setOffset(20);
        $this->assertEquals(20, $this->queryState->getOffset());

        $this->queryState->setOffset(null);
        $this->assertNull($this->queryState->getOffset());
    }

    public function testReset(): void
    {
        // Set up some state
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['id', 'name']);
        $this->queryState->setDistinct(true);
        $this->queryState->addJoin(['type' => 'INNER', 'table' => 'profiles']);
        $this->queryState->setLimit(10);
        $this->queryState->setOffset(20);

        // Verify state is set
        $this->assertEquals('users', $this->queryState->getTable());
        $this->assertEquals(['id', 'name'], $this->queryState->getSelectColumns());
        $this->assertTrue($this->queryState->isDistinct());
        $this->assertEquals([['type' => 'INNER', 'table' => 'profiles']], $this->queryState->getJoins());
        $this->assertEquals(10, $this->queryState->getLimit());
        $this->assertEquals(20, $this->queryState->getOffset());

        // Reset and verify all state is cleared
        $this->queryState->reset();

        $this->assertNull($this->queryState->getTable());
        $this->assertEquals(['*'], $this->queryState->getSelectColumns());
        $this->assertFalse($this->queryState->isDistinct());
        $this->assertEquals([], $this->queryState->getJoins());
        $this->assertNull($this->queryState->getLimit());
        $this->assertNull($this->queryState->getOffset());
    }

    public function testClone(): void
    {
        // Set up original state
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['id', 'name']);
        $this->queryState->setDistinct(true);
        $this->queryState->addJoin(['type' => 'INNER', 'table' => 'profiles']);
        $this->queryState->setLimit(10);
        $this->queryState->setOffset(20);

        // Clone the state
        $cloned = $this->queryState->clone();

        // Verify cloned state matches original
        $this->assertEquals('users', $cloned->getTable());
        $this->assertEquals(['id', 'name'], $cloned->getSelectColumns());
        $this->assertTrue($cloned->isDistinct());
        $this->assertEquals([['type' => 'INNER', 'table' => 'profiles']], $cloned->getJoins());
        $this->assertEquals(10, $cloned->getLimit());
        $this->assertEquals(20, $cloned->getOffset());

        // Verify they are separate instances
        $this->assertNotSame($this->queryState, $cloned);

        // Verify modifying one doesn't affect the other
        $cloned->setTable('orders');
        $this->assertEquals('users', $this->queryState->getTable());
        $this->assertEquals('orders', $cloned->getTable());
    }
}