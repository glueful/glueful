-- Create initial tables
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- Add basic roles
INSERT INTO roles (name) VALUES ('admin'), ('user') ON CONFLICT DO NOTHING;

-- Create functions for user management
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create trigger for updating timestamp
CREATE TRIGGER update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- Create user role assignment function
CREATE OR REPLACE FUNCTION assign_role_to_user(
    p_user_id INTEGER,
    p_role_name VARCHAR
)
RETURNS VOID AS $$
BEGIN
    INSERT INTO user_roles (user_id, role_id)
    SELECT p_user_id, id FROM roles WHERE name = p_role_name
    ON CONFLICT DO NOTHING;
END;
$$ LANGUAGE plpgsql;

-- Create user role removal function
CREATE OR REPLACE FUNCTION remove_role_from_user(
    p_user_id INTEGER,
    p_role_name VARCHAR
)
RETURNS VOID AS $$
BEGIN
    DELETE FROM user_roles
    WHERE user_id = p_user_id
    AND role_id = (SELECT id FROM roles WHERE name = p_role_name);
END;
$$ LANGUAGE plpgsql;
