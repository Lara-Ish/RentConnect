<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
