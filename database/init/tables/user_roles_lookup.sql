CREATE TABLE IF NOT EXISTS user_roles_lookup (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    role_id BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE(user_id, role_id)
);

-- Create indexes for foreign keys
CREATE INDEX idx_user_roles_user_id ON user_roles_lookup(user_id);
CREATE INDEX idx_user_roles_role_id ON user_roles_lookup(role_id);
