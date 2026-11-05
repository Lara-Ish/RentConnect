<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "--- UNITS SCHEMA & ROWS ---\n";
print_r($pdo->query("DESCRIBE units")->fetchAll(PDO::FETCH_ASSOC));
print_r($pdo->query("SELECT * FROM units")->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- PAYMENTS SCHEMA ---\n";
print_r($pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- EXISTING PAYMENTS ROWS ---\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, status, payment_method, verification_notes, created_at FROM payments")->fetchAll(PDO::FETCH_ASSOC));
