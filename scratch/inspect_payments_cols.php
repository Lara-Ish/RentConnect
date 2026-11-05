<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$cols = $pdo->query('SHOW COLUMNS FROM payments')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']}: {$col['Type']} | Null: {$col['Null']} | Default: {$col['Default']}\n";
}
