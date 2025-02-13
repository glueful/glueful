DELIMITER //

CREATE FUNCTION generate_nanoid(size INT) 
RETURNS VARCHAR(255)
DETERMINISTIC
BEGIN
    DECLARE chars VARCHAR(255) DEFAULT 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    DECLARE result VARCHAR(255) DEFAULT '';
    DECLARE i INT DEFAULT 0;
    
    WHILE i < size DO
        SET result = CONCAT(
            result,
            SUBSTRING(chars, FLOOR(1 + RAND() * 64), 1)
        );
        SET i = i + 1;
    END WHILE;
    
    RETURN result;
END//

DELIMITER ;
