<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, username, name, email, role, unit_id, is_active, verification_status, is_verified FROM users WHERE role = 'tenant'");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($tenants, JSON_PRETTY_PRINT);
