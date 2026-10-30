<?php
require_once __DIR__ . '/../config/auth.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES:\n";
print_r($tables);

foreach ($tables as $t) {
    echo "\nTABLE: $t\n";
    $c = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($c as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
}
