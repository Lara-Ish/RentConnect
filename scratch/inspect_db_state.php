<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== TABLES ===\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "Table: {$t} (Rows: {$count})\n";
}

echo "\n=== FOREIGN KEYS ===\n";
$fks = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($fks as $fk) {
    echo "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']} ({$fk['CONSTRAINT_NAME']})\n";
}

echo "\n=== USERS ===\n";
$users = $pdo->query("SELECT id, username, email, role, unit_id, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

echo "\n=== UNITS ===\n";
$units = $pdo->query("SELECT id, unit_number, monthly_rent FROM units")->fetchAll(PDO::FETCH_ASSOC);
print_r($units);

echo "\n=== APARTMENT SETTINGS ===\n";
$settings = $pdo->query("SELECT * FROM apartment_settings")->fetchAll(PDO::FETCH_ASSOC);
print_r($settings);
