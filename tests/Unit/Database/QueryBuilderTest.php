<?php

namespace Tests\Unit\Database;

use Tests\Unit\Database\SQLiteTestCase;

/**
 * QueryBuilder Class Unit Tests
 */
class QueryBuilderTest extends SQLiteTestCase
{
    /**
     * Test basic SELECT query
     */
    public function testSelect(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Test simple select
        $result = $this->db->select('users', ['id', 'username', 'email']);
        $this->assertInstanceOf(\Glueful\Database\QueryBuilder::class, $result);

        // Get results
        $users = $result->get();

        // Assert we have users
        $this->assertIsArray($users);
        $this->assertCount(3, $users);
        $this->assertEquals('john_doe', $users[0]['username']);
        $this->assertEquals('jane_smith', $users[1]['username']);
    }

    /**
     * Test WHERE clause
     */
    public function testWhere(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Test where clause
        $query = $this->db->select('users', ['*']);
        $query = $query->where(['username' => 'john_doe']);
        $users = $query->get();

        // Assert we have exactly one user
        $this->assertIsArray($users);
        $this->assertCount(1, $users);
        $this->assertEquals('john_doe', $users[0]['username']);
        $this->assertEquals('john@example.com', $users[0]['email']);
    }

    /**
     * Test JOIN operations
     */
    public function testJoin(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Test join
        $query = "SELECT posts.id, posts.title, users.username 
                FROM posts 
                JOIN users ON users.id = posts.user_id 
                ORDER BY posts.id ASC";
        $postsWithUsers = $this->db->rawQuery($query);

        // Assert join worked correctly
        $this->assertIsArray($postsWithUsers);
        $this->assertCount(3, $postsWithUsers);
        $this->assertEquals('First Post', $postsWithUsers[0]['title']);
        $this->assertEquals('john_doe', $postsWithUsers[0]['username']);
        $this->assertEquals('Jane\'s Post', $postsWithUsers[2]['title']);
        $this->assertEquals('jane_smith', $postsWithUsers[2]['username']);
    }

    /**
     * Test INSERT operations
     */
    public function testInsert(): void
    {
        // Create test tables
        $this->connection->createTestTables();

        // Insert data using QueryBuilder
        $result = $this->db->insert('users', [
            'username' => 'test_user',
            'email' => 'test@example.com'
        ]);

        // Assert insert was successful
        $this->assertEquals(1, $result, "Should have inserted 1 row");

        // Verify data was inserted
        $query = $this->db->select('users', ['*']);
        $query = $query->where(['username' => 'test_user']);
        $insertedUser = $query->get();

        $this->assertNotEmpty($insertedUser);
        $this->assertEquals('test@example.com', $insertedUser[0]['email']);
    }

    /**
     * Test UPDATE operations
     */
    public function testUpdate(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Update data using QueryBuilder
        $updated = $this->db->update('users', [
            'email' => 'john.updated@example.com'
        ], [
            'username' => 'john_doe'
        ]);

        // Assert update was successful
        $this->assertEquals(1, $updated, "Should have updated 1 row");

        // Verify data was updated
        $query = $this->db->select('users', ['*']);
        $query = $query->where(['username' => 'john_doe']);
        $updatedUser = $query->get();

        $this->assertEquals('john.updated@example.com', $updatedUser[0]['email']);
    }

    /**
     * Test DELETE operations
     */
    public function testDelete(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Count users before delete
        $usersBefore = $this->db->select('users')->get();
        $userCount = count($usersBefore);
        $this->assertEquals(3, $userCount);

        // Delete data using QueryBuilder
        $deleted = $this->db->delete('users', ['username' => 'bob_jones'], false);

        // Assert delete was successful
        $this->assertTrue($deleted);

        // Verify data was deleted
        $usersAfter = $this->db->select('users')->get();
        $newCount = count($usersAfter);
        $this->assertEquals(2, $newCount);

        // Verify the right user was deleted
        $users = $this->db->select('users', ['username'])->get();

        $usernames = array_column($users, 'username');
        $this->assertContains('john_doe', $usernames);
        $this->assertContains('jane_smith', $usernames);
        $this->assertNotContains('bob_jones', $usernames);
    }

    /**
     * Test transaction support
     */
    public function testTransactions(): void
    {
        // Create test tables
        $this->connection->createTestTables();

        // Execute a transaction
        $pdo = $this->connection->getPDO();

        try {
            $pdo->beginTransaction();

            $this->db->insert('users', [
                'username' => 'transaction_user1',
                'email' => 'trans1@example.com'
            ]);

            $this->db->insert('users', [
                'username' => 'transaction_user2',
                'email' => 'trans2@example.com'
            ]);

            $pdo->commit();
            $result = true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            $result = false;
        }

        // Assert transaction was successful
        $this->assertTrue($result);

        // Verify both inserts were applied
        $users = $this->db->select('users')->get();
        $this->assertCount(2, $users);

        // Test rollback
        try {
            $pdo->beginTransaction();

            $this->db->insert('users', [
                'username' => 'rollback_user',
                'email' => 'rollback@example.com'
            ]);

            // Force a rollback
            $pdo->rollBack();
        } catch (\Exception $e) {
            $pdo->rollBack();
        }

        // Verify the insert was rolled back
        $users = $this->db->select('users')->get();
        $this->assertCount(2, $users); // Still only 2 users

        $query = $this->db->select('users', ['*']);
        $query = $query->where(['username' => 'rollback_user']);
        $rollbackUser = $query->get();
        $this->assertEmpty($rollbackUser);
    }

    /**
     * Test aggregate functions
     */
    public function testAggregates(): void
    {
        // Create test tables and insert data
        $this->insertSampleData();

        // Test count using SQL
        $count = $this->db->rawQuery("SELECT COUNT(*) as count FROM users")[0]['count'];
        $this->assertEquals(3, $count);

        // Test posts per user (grouping and counting)
        $postsPerUser = $this->db->rawQuery("
            SELECT user_id, COUNT(*) as post_count 
            FROM posts 
            GROUP BY user_id
        ");

        $this->assertCount(2, $postsPerUser);

        // Find user_id = 1 result
        $user1Posts = null;
        foreach ($postsPerUser as $userPosts) {
            if ($userPosts['user_id'] == 1) {
                $user1Posts = $userPosts;
                break;
            }
        }

        $this->assertNotNull($user1Posts);
        $this->assertEquals(2, $user1Posts['post_count']);
    }
}
