<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("DESCRIBE users");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}
