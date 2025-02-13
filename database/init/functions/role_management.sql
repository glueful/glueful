DELIMITER //

CREATE PROCEDURE assign_role_to_user(
    IN p_user_uuid CHAR(12),
    IN p_role_name VARCHAR(255)
)
BEGIN
    INSERT IGNORE INTO user_roles_lookup (user_uuid, role_id)
    SELECT p_user_uuid, id FROM roles WHERE name = p_role_name;
END//

CREATE PROCEDURE remove_role_from_user(
    IN p_user_uuid CHAR(12),
    IN p_role_name VARCHAR(255)
)
BEGIN
    DELETE url FROM user_roles_lookup url
    INNER JOIN roles r ON url.role_id = r.id
    WHERE url.user_uuid = p_user_uuid
    AND r.name = p_role_name;
END//

DELIMITER ;
