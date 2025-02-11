CREATE OR REPLACE FUNCTION assign_role_to_user(
    p_user_id INTEGER,
    p_role_name VARCHAR
)
RETURNS VOID AS $$
BEGIN
    INSERT INTO user_roles_lookup (user_id, role_id)
    SELECT p_user_id, id FROM roles WHERE name = p_role_name
    ON CONFLICT DO NOTHING;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION remove_role_from_user(
    p_user_id INTEGER,
    p_role_name VARCHAR
)
RETURNS VOID AS $$
BEGIN
    DELETE FROM user_roles_lookup
    WHERE user_id = p_user_id
    AND role_id = (SELECT id FROM roles WHERE name = p_role_name);
END;
$$ LANGUAGE plpgsql;
