CREATE TABLE IF NOT EXISTS schema_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    description TEXT,
    filename VARCHAR(255) NOT NULL,
    checksum VARCHAR(64) NOT NULL,
    batch INT NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    execution_time INT NOT NULL,
    status ENUM('pending', 'success', 'failed', 'rolled_back') DEFAULT 'pending',
    UNIQUE KEY unique_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;