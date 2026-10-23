<?php
require_once __DIR__ . '/../config/db.php';
$stmt = Database::getConnection()->query('SELECT id, username, name, role, verification_status, live_photo_filename, id_photo_filename FROM users');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo $u['id'] . ': ' . $u['username'] . ' (' . $u['role'] . ', ' . $u['verification_status'] . ') -> live: ' . ($u['live_photo_filename'] ?? 'NULL') . ', id: ' . ($u['id_photo_filename'] ?? 'NULL') . PHP_EOL;
}
