<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
