<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM users WHERE id = 2 AND role = 'tenant'");
    $del->execute();
    echo "Deletion succeeded in transaction!\n";
    $pdo->rollBack();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "CODE: " . $e->getCode() . "\n";
}
