-- Create admin user
INSERT INTO users (
    uuid,
    username,
    email,
    password,
    status,
    created_date
) VALUES (
    generate_nanoid(12),  -- typical nanoid length is 21
    'admin',
    'admin@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    'active',
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE id=id;

DELIMITER //

CREATE PROCEDURE assign_admin_role()
BEGIN
    DECLARE v_user_uuid CHAR(12);
    DECLARE v_role_id BIGINT;
    
    -- Get the admin user UUID
    SELECT uuid INTO v_user_uuid FROM users WHERE username = 'admin' LIMIT 1;
    
    -- Get the admin role ID
    SELECT id INTO v_role_id FROM roles WHERE name = 'Administrator' LIMIT 1;
    
    -- Insert into user_roles_lookup
    IF v_user_uuid IS NOT NULL AND v_role_id IS NOT NULL THEN
        INSERT IGNORE INTO user_roles_lookup (user_uuid, role_id)
        VALUES (v_user_uuid, v_role_id);
    END IF;
END//

DELIMITER ;

CALL assign_admin_role();
DROP PROCEDURE IF EXISTS assign_admin_role;
