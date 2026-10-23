<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
echo "Columns: " . implode(', ', $cols) . PHP_EOL;
if (!in_array('is_verified', $cols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_status");
    echo "Added is_verified column successfully!" . PHP_EOL;
} else {
    echo "is_verified already exists." . PHP_EOL;
}
