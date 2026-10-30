<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

foreach ([110, 111] as $uid) {
    echo "Check user $uid:\n";
    $p = $pdo->query("SELECT id, amount_due, status FROM payments WHERE tenant_id = $uid")->fetchAll(PDO::FETCH_ASSOC);
    echo "  Payments: " . count($p) . "\n";
    $t = $pdo->query("SELECT id, title, status FROM maintenance_tickets WHERE tenant_id = $uid")->fetchAll(PDO::FETCH_ASSOC);
    echo "  Tickets: " . count($t) . "\n";
}
