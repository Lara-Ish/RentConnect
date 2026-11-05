<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== PASSWORD_RESETS COLUMNS ===\n";
$cols = $pdo->query("DESCRIBE password_resets")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']} - {$col['Type']} - Null:{$col['Null']} - Default:{$col['Default']}\n";
}

echo "\n=== USERS IN DB ===\n";
$users = $pdo->query("SELECT id, username, name, email, role, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "ID:{$u['id']} | {$u['role']} | {$u['username']} | {$u['name']} | {$u['email']} | Active:{$u['is_active']}\n";
}

