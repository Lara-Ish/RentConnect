<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
