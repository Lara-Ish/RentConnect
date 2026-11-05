<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$admin = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo "Admin record:\n";
print_r($admin);
