<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

$cols = $pdo->query("SHOW COLUMNS FROM email_logs LIKE 'recipient_user_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE email_logs ADD COLUMN recipient_user_id INT UNSIGNED NULL AFTER id, ADD INDEX idx_email_logs_user (recipient_user_id)");
    echo "Added recipient_user_id column to email_logs.\n";
} else {
    echo "recipient_user_id column already exists in email_logs.\n";
}
