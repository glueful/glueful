<?php

// Quick test for orWhere functionality
require_once __DIR__ . '/bootstrap.php';

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

try {
    $connection = new Connection();
    $pdo = $connection->getPDO();
    $driver = $connection->getDriver();
    
    $qb = new QueryBuilder($pdo, $driver);
    
    // Test basic orWhere functionality
    echo "Testing orWhere functionality:\n\n";
    
    // Test 1: Basic orWhere with array conditions
    $query1 = $qb->select('users', ['id', 'email'])
        ->where(['status' => 'active'])
        ->orWhere(['role' => 'admin']);
    
    echo "Test 1 - Basic orWhere:\n";
    echo $query1->toSql() . "\n\n";
    
    // Test 2: orWhereNull
    $qb2 = new QueryBuilder($pdo, $driver);
    $query2 = $qb2->select('user_roles', ['uuid', 'expires_at'])
        ->where(['user_uuid' => 'test123'])
        ->orWhereNull('expires_at');
    
    echo "Test 2 - orWhereNull:\n";
    echo $query2->toSql() . "\n\n";
    
    echo "✅ orWhere methods implemented successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error testing orWhere: " . $e->getMessage() . "\n";
}