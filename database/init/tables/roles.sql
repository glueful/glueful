CREATE TABLE IF NOT EXISTS roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted')),
    created_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add basic roles with required fields
INSERT INTO roles (name, description, status) VALUES 
    ('Administrator', 'Full system access with all privileges', 'active'),
    ('User', 'Standard user access with basic privileges', 'active'),
ON CONFLICT DO NOTHING;

-- Create index on frequently queried fields
CREATE INDEX idx_roles_name ON roles(name);
