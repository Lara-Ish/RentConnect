<?php
require_once __DIR__ . '/../config/auth.php';
$pdo = Database::getConnection();
echo "PAYMENTS:\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, billing_cycle, due_date, amount_paid, status, created_at FROM payments")->fetchAll(PDO::FETCH_ASSOC));
echo "\nMAINTENANCE TICKETS:\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, title, category, priority, status, created_at FROM maintenance_tickets")->fetchAll(PDO::FETCH_ASSOC));
