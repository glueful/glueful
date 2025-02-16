CREATE TABLE IF NOT EXISTS user_roles_lookup (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_uuid CHAR(12) NOT NULL,
    role_id BIGINT NOT NULL,
    FOREIGN KEY (user_uuid) REFERENCES users(uuid) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE(user_uuid, role_id)
);

-- Create indexes for foreign keys
CREATE INDEX idx_user_roles_user_uuid ON user_roles_lookup(user_uuid);
CREATE INDEX idx_user_roles_role_id ON user_roles_lookup(role_id);
