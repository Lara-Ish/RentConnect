<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

try {
    // Drop existing triggers if any
    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_one_active_tenant_insert");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_one_active_tenant_update");

    // Test creating BEFORE INSERT trigger
    $pdo->exec("
        CREATE TRIGGER trg_users_one_active_tenant_insert
        BEFORE INSERT ON users
        FOR EACH ROW
        BEGIN
            IF NEW.role = 'tenant' AND NEW.is_active = 1 AND NEW.unit_id IS NOT NULL THEN
                IF (SELECT COUNT(*) FROM users WHERE unit_id = NEW.unit_id AND role = 'tenant' AND is_active = 1) > 0 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.';
                END IF;
            END IF;
        END
    ");

    // Test creating BEFORE UPDATE trigger
    $pdo->exec("
        CREATE TRIGGER trg_users_one_active_tenant_update
        BEFORE UPDATE ON users
        FOR EACH ROW
        BEGIN
            IF NEW.role = 'tenant' AND NEW.is_active = 1 AND NEW.unit_id IS NOT NULL THEN
                IF (SELECT COUNT(*) FROM users WHERE unit_id = NEW.unit_id AND role = 'tenant' AND is_active = 1 AND id != OLD.id) > 0 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.';
                END IF;
            END IF;
        END
    ");

    echo "Triggers created successfully!\n";
} catch (Exception $e) {
    echo "Trigger creation failed: " . $e->getMessage() . "\n";
}
