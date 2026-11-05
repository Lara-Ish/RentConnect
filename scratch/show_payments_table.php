<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$create = $pdo->query("SHOW CREATE TABLE payments")->fetch(PDO::FETCH_ASSOC);
echo $create['Create Table'] . "\n";
