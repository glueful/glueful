<?php

declare(strict_types=1);

/**
 * Create {{EXTENSION_NAME}} Table Migration
 * 
 * Example migration for the {{EXTENSION_NAME}} extension
 */

use Glueful\Database\Connection;

// Get database connection
$connection = Connection::getInstance();

// Create the table
$sql = "
    CREATE TABLE IF NOT EXISTS {{EXTENSION_NAME|lower}}_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('active', 'inactive') DEFAULT 'active',
        metadata JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (name),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $connection->exec($sql);
    echo "✅ {{EXTENSION_NAME}} table created successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to create {{EXTENSION_NAME}} table: " . $e->getMessage() . "\n";
    throw $e;
}