CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(12) NOT NULL UNIQUE,
    user_uuid CHAR(12) NOT NULL,                -- Changed from user_id
    access_token VARCHAR(255) NOT NULL,         -- Short-lived JWT token (15 mins)
    refresh_token VARCHAR(255) NULL,            -- Long-lived token (7 days)
    token_fingerprint BINARY(32) NOT NULL,      -- Changed to BINARY for hashed fingerprint
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    last_token_refresh TIMESTAMP NULL,
    access_expires_at TIMESTAMP NOT NULL,
    refresh_expires_at TIMESTAMP NOT NULL,
    status ENUM('active', 'revoked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_sessions (user_uuid, status),
    INDEX idx_access_token (access_token),
    INDEX idx_refresh_token (refresh_token),
    INDEX idx_token_fingerprint (token_fingerprint),  -- New index for fingerprint lookups
    INDEX idx_session_uuid (uuid),
    
    FOREIGN KEY (user_uuid) REFERENCES users(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;