<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== PAYMENTS ===\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, payment_type, status, amount_due, amount_paid FROM payments")->fetchAll(PDO::FETCH_ASSOC));

echo "=== MAINTENANCE TICKETS ===\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, title, status FROM maintenance_tickets")->fetchAll(PDO::FETCH_ASSOC));

echo "=== NOTIFICATIONS ===\n";
print_r($pdo->query("SELECT id, user_id, type, title FROM notifications")->fetchAll(PDO::FETCH_ASSOC));

echo "=== EMAIL LOGS ===\n";
print_r($pdo->query("SELECT id, recipient_email, recipient_user_id, template_type, status FROM email_logs")->fetchAll(PDO::FETCH_ASSOC));

echo "=== PASSWORD RESETS ===\n";
print_r($pdo->query("SELECT id, user_id, email FROM password_resets")->fetchAll(PDO::FETCH_ASSOC));
