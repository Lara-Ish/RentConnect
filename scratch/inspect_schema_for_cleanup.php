<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES IN DATABASE:\n";
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo " - $t (rows: $count)\n";
}

echo "\n--- COLUMNS FOR payments ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);

echo "\n--- COLUMNS FOR apartment_settings ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM apartment_settings")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);

echo "\n--- FOREIGN KEYS ---\n";
$fks = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
print_r($fks);
