<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "{$t}: {$count}\n";
}
