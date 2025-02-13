CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(12) NOT NULL UNIQUE,
    table_name VARCHAR(64) NOT NULL,
    record_uuid CHAR(12) NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    user_uuid CHAR(12) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_record (record_uuid),
    INDEX idx_audit_user (user_uuid),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
