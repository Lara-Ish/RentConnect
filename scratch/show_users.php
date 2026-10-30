<?php
require_once __DIR__ . '/../config/auth.php';
$pdo = Database::getConnection();
$users = $pdo->query("SELECT id, username, name, email, role, unit_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
