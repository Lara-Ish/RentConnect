<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$u = $pdo->query("SELECT id, name, is_verified, verification_status, verification_notes FROM users WHERE role = 'tenant' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Tenant ID: " . $u['id'] . "\n";
echo "is_verified: " . var_export($u['is_verified'], true) . "\n";
echo "verification_status: " . $u['verification_status'] . "\n";
echo "verification_notes: " . $u['verification_notes'] . "\n";
