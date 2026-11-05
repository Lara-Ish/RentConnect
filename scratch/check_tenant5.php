<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, username, name, live_photo_filename, id_photo_filename FROM users WHERE id = 5");
$t = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($t);
