CREATE TABLE IF NOT EXISTS roles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(12) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted')),
    created_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for frequently queried fields
CREATE INDEX idx_roles_name ON roles(name);
CREATE INDEX idx_roles_uuid ON roles(uuid);

-- Add basic roles with required fields
INSERT INTO roles (uuid, name, description, status) VALUES 
    (generate_nanoid(12), 'Administrator', 'Full system access with all privileges', 'active'),
    (generate_nanoid(12), 'User', 'Standard user access with basic privileges', 'active')
ON DUPLICATE KEY UPDATE id=id;
