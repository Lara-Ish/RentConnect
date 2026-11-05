<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== USERS TABLE INDEXES ===\n";
$indexes = $pdo->query("SHOW INDEX FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($indexes as $idx) {
    echo "Index: {$idx['Key_name']} | Column: {$idx['Column_name']} | Non_unique: {$idx['Non_unique']}\n";
}
