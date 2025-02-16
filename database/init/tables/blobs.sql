CREATE TABLE IF NOT EXISTS blobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(12) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    mime_type VARCHAR(127) NOT NULL,
    size BIGINT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted')),
    created_by CHAR(12) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(uuid)
);

-- Create indexes for frequently queried fields
CREATE INDEX idx_blobs_created_by ON blobs(created_by);
CREATE INDEX idx_blobs_uuid ON blobs(uuid);