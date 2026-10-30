<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

try {
    $ins = $pdo->prepare("
        INSERT INTO users (unit_id, username, name, email, password_hash, role, is_active, created_at)
        VALUES (1, 'RC999', 'Duplicate Test', 'dup@test.com', 'dummyhash', 'tenant', 1, NOW())
    ");
    $ins->execute();
    echo "ERROR: Insert succeeded when it should have failed!\n";
} catch (PDOException $e) {
    echo "SUCCESS: Trigger fired!\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
}
