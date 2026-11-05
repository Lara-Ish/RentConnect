<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== TABLES WITH tenant_id OR user_id ===\n";
$tables = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND COLUMN_NAME IN ('tenant_id', 'user_id', 'active_tenant_id')
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tables as $t) {
    echo "{$t['TABLE_NAME']}.{$t['COLUMN_NAME']}\n";
}
