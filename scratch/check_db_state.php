<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$users = $pdo->query("SELECT id, username, name, role, live_photo_filename, id_photo_filename FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "ID: {$u['id']}, User: {$u['username']}, Name: {$u['name']}, Role: {$u['role']}, Live: {$u['live_photo_filename']}, ID_Photo: {$u['id_photo_filename']}\n";
}
