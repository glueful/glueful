-- Create admin user
INSERT INTO users (
    username,
    email,
    password,
    status,
    created_date
) VALUES (
    'admin',
    'admin@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'active',
    CURRENT_TIMESTAMP
) ON CONFLICT (username) DO NOTHING
RETURNING id;

-- Assign admin role to admin user
DO $$
DECLARE
    v_user_id BIGINT;
    v_role_id BIGINT;
BEGIN
    -- Get the admin user ID
    SELECT id INTO v_user_id FROM users WHERE username = 'admin' LIMIT 1;
    
    -- Get the admin role ID
    SELECT id INTO v_role_id FROM roles WHERE name = 'Administrator' LIMIT 1;
    
    -- Insert into user_roles_lookup
    IF v_user_id IS NOT NULL AND v_role_id IS NOT NULL THEN
        INSERT INTO user_roles_lookup (user_id, role_id)
        VALUES (v_user_id, v_role_id)
        ON CONFLICT DO NOTHING;
    END IF;
END $$;
