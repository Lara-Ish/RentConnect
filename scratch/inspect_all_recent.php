<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== USERS ===\n";
$stmt = $pdo->query("SELECT id, username, name, email, role, unit_id, is_active, created_at, updated_at FROM users");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== UNITS ===\n";
$stmt = $pdo->query("SELECT * FROM units");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== RECENT PAYMENTS ===\n";
$stmt = $pdo->query("SELECT id, tenant_id, unit_id, payment_type, status, created_at FROM payments ORDER BY id DESC LIMIT 5");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== RECENT EMAIL LOGS ===\n";
$stmt = $pdo->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 5");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
