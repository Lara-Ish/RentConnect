<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
echo "--- USERS ---\n";
foreach ($pdo->query("SELECT id, username, name, email, role, unit_id FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo json_encode($u) . "\n";
}
echo "\n--- APARTMENT_SETTINGS ---\n";
foreach ($pdo->query("SELECT * FROM apartment_settings")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    echo json_encode($s) . "\n";
}
echo "\n--- PROPERTIES ---\n";
foreach ($pdo->query("SELECT * FROM properties")->fetchAll(PDO::FETCH_ASSOC) as $p) {
    echo json_encode($p) . "\n";
}
