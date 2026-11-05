<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

foreach (['users', 'units', 'apartment_settings', 'maintenance_tickets', 'payments', 'announcements', 'announcement_recipients', 'email_logs'] as $t) {
    if (in_array($t, $tables, true)) {
        echo "\n=== SCHEMA: {$t} ===\n";
        $cols = $pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  - {$c['Field']} ({$c['Type']}) Null:{$c['Null']} Key:{$c['Key']} Default:{$c['Default']}\n";
        }
    }
}

echo "\n=== CURRENT USERS IN DB ===\n";
$users = $pdo->query("SELECT id, unit_id, role, name, email, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
