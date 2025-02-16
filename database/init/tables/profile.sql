CREATE TABLE IF NOT EXISTS profile (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(12) NOT NULL UNIQUE,
    user_uuid CHAR(12) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    photo_uuid CHAR(12) DEFAULT NULL,
    photo_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted')),
    created_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_uuid) REFERENCES users(uuid) ON DELETE CASCADE,
    FOREIGN KEY (photo_uuid) REFERENCES blobs(uuid) ON DELETE SET NULL
);

-- Create indexes for frequently queried fields
CREATE INDEX idx_profile_uuid ON profile(uuid);
CREATE INDEX idx_profile_user_uuid ON profile(user_uuid);
CREATE INDEX idx_profile_photo_uuid ON profile(photo_uuid);

