<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

$stmt = $pdo->query("SHOW COLUMNS FROM units");
echo "UNITS COLUMNS:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("SELECT * FROM units");
echo "UNITS DATA:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT id, username, name, role, unit_id, is_active FROM users");
echo "USERS DATA:\n";
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
