<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

// First deactivate 110 and 111 so Unit 1 has only 107
$pdo->exec("UPDATE users SET is_active = 0 WHERE id IN (110, 111)");

try {
    // Check if column already exists
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'active_tenant_unit_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN active_tenant_unit_id INT UNSIGNED GENERATED ALWAYS AS (IF(role = 'tenant' AND is_active = 1, unit_id, NULL)) STORED");
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_active_tenant_unit (active_tenant_unit_id)");
        echo "Virtual column and UNIQUE key created successfully!\n";
    } else {
        echo "Column active_tenant_unit_id already exists.\n";
    }
} catch (Exception $e) {
    echo "Virtual column / UNIQUE key error: " . $e->getMessage() . "\n";
}
