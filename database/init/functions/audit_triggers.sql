DELIMITER //

-- Users table audit triggers
CREATE TRIGGER users_after_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (uuid, table_name, record_uuid, action, new_values)
    VALUES (
        generate_nanoid(12),
        'users',
        NEW.uuid,
        'INSERT',
        JSON_OBJECT(
            'username', NEW.username,
            'email', NEW.email,
            'status', NEW.status
        )
    );
END//

CREATE TRIGGER users_after_update
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (uuid, table_name, record_uuid, action, old_values, new_values)
    VALUES (
        generate_nanoid(12),
        'users',
        NEW.uuid,
        'UPDATE',
        JSON_OBJECT(
            'username', OLD.username,
            'email', OLD.email,
            'status', OLD.status
        ),
        JSON_OBJECT(
            'username', NEW.username,
            'email', NEW.email,
            'status', NEW.status
        )
    );
END//

CREATE TRIGGER users_after_delete
AFTER DELETE ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (uuid, table_name, record_uuid, action, old_values)
    VALUES (
        generate_nanoid(12),
        'users',
        OLD.uuid,
        'DELETE',
        JSON_OBJECT(
            'username', OLD.username,
            'email', OLD.email,
            'status', OLD.status
        )
    );
END//

DELIMITER ;
