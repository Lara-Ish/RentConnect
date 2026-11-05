<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== USERS ===\n";
print_r($pdo->query("SELECT id, username, name, email, role, unit_id, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC));

echo "=== APARTMENT SETTINGS ===\n";
print_r($pdo->query("SELECT * FROM apartment_settings")->fetchAll(PDO::FETCH_ASSOC));

echo "=== UNITS ===\n";
print_r($pdo->query("SELECT * FROM units")->fetchAll(PDO::FETCH_ASSOC));

echo "=== PAYMENTS ===\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, billing_cycle, amount_due, amount_paid, status, receipt_filename FROM payments")->fetchAll(PDO::FETCH_ASSOC));

echo "=== MAINTENANCE TICKETS ===\n";
print_r($pdo->query("SELECT id, tenant_id, unit_id, title, status FROM maintenance_tickets")->fetchAll(PDO::FETCH_ASSOC));

echo "=== NOTIFICATIONS ===\n";
print_r($pdo->query("SELECT id, user_id, role, type, title FROM notifications")->fetchAll(PDO::FETCH_ASSOC));

echo "=== PASSWORD RESETS ===\n";
print_r($pdo->query("SELECT * FROM password_resets")->fetchAll(PDO::FETCH_ASSOC));

echo "=== EMAIL LOGS ===\n";
print_r($pdo->query("SELECT id, recipient_email, template_type, status FROM email_logs")->fetchAll(PDO::FETCH_ASSOC));
