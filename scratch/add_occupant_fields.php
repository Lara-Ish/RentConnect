<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

// Check if columns exist
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'total_occupants'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN total_occupants INT NOT NULL DEFAULT 1 AFTER move_in_date");
    echo "Added total_occupants column.\n";
} else {
    echo "total_occupants already exists.\n";
}

$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'adults_count'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN adults_count INT NOT NULL DEFAULT 1 AFTER total_occupants");
    echo "Added adults_count column.\n";
} else {
    echo "adults_count already exists.\n";
}

$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'kids_count'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN kids_count INT NOT NULL DEFAULT 0 AFTER adults_count");
    echo "Added kids_count column.\n";
} else {
    echo "kids_count already exists.\n";
}

// Ensure default values on existing tenants:
$pdo->exec("UPDATE users SET total_occupants = 1, adults_count = 1, kids_count = 0 WHERE total_occupants IS NULL OR total_occupants < 1");
echo "Updated default values for existing rows.\n";
