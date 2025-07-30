<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Query;

use Glueful\Database\Query\SelectBuilder;
use Glueful\Database\Query\QueryState;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\RawExpression;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * SelectBuilder Unit Tests
 *
 * Tests the SelectBuilder component in isolation to ensure
 * proper SELECT query building functionality.
 */
class SelectBuilderTest extends TestCase
{
    private SelectBuilder $selectBuilder;
    private DatabaseDriver|MockObject $mockDriver;
    private QueryState $queryState;

    protected function setUp(): void
    {
        $this->mockDriver = $this->createMock(DatabaseDriver::class);
        $this->mockDriver->method('wrapIdentifier')
            ->willReturnCallback(fn($identifier) => "`{$identifier}`");
        
        $this->queryState = new QueryState();
        $this->selectBuilder = new SelectBuilder($this->mockDriver, $this->queryState);
    }

    public function testBuildBasicSelectQuery(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['id', 'name', 'email']);
        
        $sql = $this->selectBuilder->build();
        
        $this->assertEquals('SELECT `id`, `name`, `email` FROM `users`', $sql);
    }

    public function testBuildSelectWithWildcard(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['*']);
        
        $sql = $this->selectBuilder->build();
        
        $this->assertEquals('SELECT * FROM `users`', $sql);
    }

    public function testBuildDistinctSelect(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['name']);
        $this->queryState->setDistinct(true);
        
        $sql = $this->selectBuilder->build();
        
        $this->assertEquals('SELECT DISTINCT `name` FROM `users`', $sql);
    }

    public function testBuildSelectWithTableColumns(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['users.id', 'users.name', 'profiles.bio']);
        
        $sql = $this->selectBuilder->build();
        
        $expected = 'SELECT `users`.`id`, `users`.`name`, `profiles`.`bio` FROM `users`';
        $this->assertEquals($expected, $sql);
    }

    public function testBuildSelectWithAliases(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['users.id AS user_id', 'name AS user_name']);
        
        $sql = $this->selectBuilder->build();
        
        $expected = 'SELECT `users`.`id` AS `user_id`, `name` AS `user_name` FROM `users`';
        $this->assertEquals($expected, $sql);
    }

    public function testBuildSelectWithTableWildcard(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['users.*', 'profiles.bio']);
        
        $sql = $this->selectBuilder->build();
        
        $expected = 'SELECT `users`.*, `profiles`.`bio` FROM `users`';
        $this->assertEquals($expected, $sql);
    }

    public function testBuildSelectWithJoin(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['users.name', 'profiles.bio']);
        $this->queryState->addJoin([
            'type' => 'INNER',
            'table' => 'profiles',
            'first' => 'users.id',
            'operator' => '=',
            'second' => 'profiles.user_id'
        ]);
        
        $sql = $this->selectBuilder->build();
        
        $expected = 'SELECT `users`.`name`, `profiles`.`bio` FROM `users` INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`';
        $this->assertEquals($expected, $sql);
    }

    public function testBuildSelectWithMultipleJoins(): void
    {
        $this->queryState->setTable('users');
        $this->queryState->setSelectColumns(['users.name', 'profiles.bio', 'roles.name']);
        $this->queryState->addJoin([
            'type' => 'LEFT',
            'table' => 'profiles',
            'first' => 'users.id',
            'operator' => '=',
            'second' => 'profiles.user_id'
        ]);
        $this->queryState->addJoin([
            'type' => 'INNER',
            'table' => 'roles',
            'first' => 'users.role_id',
            'operator' => '=',
            'second' => 'roles.id'
        ]);
        
        $sql = $this->selectBuilder->build();
        
        $expected = 'SELECT `users`.`name`, `profiles`.`bio`, `roles`.`name` FROM `users`' .
                   ' LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`' .
                   ' INNER JOIN `roles` ON `users`.`role_id` = `roles`.`id`';
        $this->assertEquals($expected, $sql);
    }

    public function testSetAndGetColumns(): void
    {
        $columns = ['id', 'name', 'email'];
        $this->selectBuilder->setColumns($columns);
        
        $this->assertEquals($columns, $this->selectBuilder->getColumns());
    }

    public function testSetAndGetDistinct(): void
    {
        $this->assertFalse($this->selectBuilder->isDistinct());
        
        $this->selectBuilder->setDistinct(true);
        $this->assertTrue($this->selectBuilder->isDistinct());
    }

    public function testBuildColumnList(): void
    {
        $this->queryState->setSelectColumns(['id', 'users.name', 'email AS user_email']);
        
        $columnList = $this->selectBuilder->buildColumnList();
        
        $expected = '`id`, `users`.`name`, `email` AS `user_email`';
        $this->assertEquals($expected, $columnList);
    }

    public function testThrowsExceptionWhenNoTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No table specified');
        
        $this->selectBuilder->build();
    }

    public function testReset(): void
    {
        // This test verifies the reset method exists and doesn't break
        $this->selectBuilder->reset();
        $this->assertEquals([], $this->selectBuilder->getBindings());
    }

    public function testGetBindings(): void
    {
        // SelectBuilder currently doesn't have bindings, but the interface requires it
        $this->assertEquals([], $this->selectBuilder->getBindings());
    }
}