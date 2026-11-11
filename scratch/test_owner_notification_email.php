<?php
/**
 * Test Suite: Owner/Admin Notification Email Recipient Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

function assertCheck(string $label, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo " [PASS] $label\n";
    } else {
        echo "![FAIL] $label" . ($detail ? " ($detail)" : "") . "\n";
        exit(1);
    }
}

echo "========================================================\n";
echo "RentConnect - Owner Notification Email Recipient Tests\n";
echo "========================================================\n\n";

// 1. Verify Database Landlord Email
$stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'landlord' AND is_active = 1 LIMIT 1");
$landlord = $stmt->fetch(PDO::FETCH_ASSOC);
assertCheck(
    "Live database landlord record exists",
    !empty($landlord)
);
assertCheck(
    "Live database landlord email is 'rentconnect2026@gmail.com'",
    ($landlord['email'] ?? '') === 'rentconnect2026@gmail.com',
    "Actual email: " . ($landlord['email'] ?? 'null')
);

// 2. Verify EmailService::getOwnerFromDatabase()
$ownerData = EmailService::getOwnerFromDatabase($pdo);
assertCheck(
    "EmailService::getOwnerFromDatabase returns valid array",
    is_array($ownerData)
);
assertCheck(
    "EmailService::getOwnerFromDatabase email is 'rentconnect2026@gmail.com'",
    ($ownerData['email'] ?? '') === 'rentconnect2026@gmail.com',
    "Actual: " . ($ownerData['email'] ?? 'null')
);

// 3. Test EmailService direct owner notifications
// We record the current max id in email_logs to inspect newly generated logs
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();

// 3A. Payment proof submitted notification
$res1 = EmailService::notifyOwnerPaymentProofSubmitted(
    $pdo,
    $ownerData['email'],
    $ownerData['name'],
    'Test Resident',
    'Unit 1',
    3000.00,
    '2026-11-01',
    999901,
    (int)$ownerData['id']
);
assertCheck("notifyOwnerPaymentProofSubmitted executed", $res1);

$log1 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("Payment log created in email_logs", !empty($log1));
assertCheck(
    "Payment notification recipient_email is 'rentconnect2026@gmail.com'",
    $log1['recipient_email'] === 'rentconnect2026@gmail.com',
    "Actual: {$log1['recipient_email']}"
);
assertCheck(
    "Payment notification template_type is 'payment_proof_submitted'",
    $log1['template_type'] === 'payment_proof_submitted'
);

// 3B. Maintenance ticket created notification
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();
$res2 = EmailService::notifyOwnerMaintenanceTicketCreated(
    $pdo,
    $ownerData['email'],
    $ownerData['name'],
    'Test Resident',
    'Unit 1',
    'Faucet Leaking Heavily',
    'Plumbing',
    'high',
    999902,
    (int)$ownerData['id']
);
assertCheck("notifyOwnerMaintenanceTicketCreated executed", $res2);

$log2 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("Ticket log created in email_logs", !empty($log2));
assertCheck(
    "Ticket notification recipient_email is 'rentconnect2026@gmail.com'",
    $log2['recipient_email'] === 'rentconnect2026@gmail.com',
    "Actual: {$log2['recipient_email']}"
);
assertCheck(
    "Ticket notification template_type is 'maintenance_ticket_created'",
    $log2['template_type'] === 'maintenance_ticket_created'
);

// 3C. Defensive guard test: passing old email 'lead.dimaculangan@rentconnect.ph'
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();
$res3 = EmailService::notifyOwnerMaintenanceTicketCreated(
    $pdo,
    'lead.dimaculangan@rentconnect.ph', // intentional legacy parameter
    'Lead Dimaculangan',
    'Test Resident',
    'Unit 1',
    'Window Lock Jammed',
    'Security',
    'normal',
    999903,
    (int)$ownerData['id']
);
assertCheck("notifyOwnerMaintenanceTicketCreated with legacy parameter executed", $res3);

$log3 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck(
    "Legacy email automatically normalized to 'rentconnect2026@gmail.com'",
    $log3['recipient_email'] === 'rentconnect2026@gmail.com',
    "Actual: {$log3['recipient_email']}"
);

// 4. Test NotificationService::notifyLandlords for Payment Submission
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();
NotificationService::notifyLandlords(
    $pdo,
    'payment',
    'Tenant Rent Payment Submission',
    'Tenant: Test Resident • Unit: Unit 1 • Amount Paid: ₱3,000.00 • Submitted: Nov 7, 2026',
    'sectionPayments',
    999904,
    'payment_submitted',
    true
);

$log4 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("NotificationService dispatched landlord payment email", !empty($log4));
assertCheck(
    "NotificationService payment email recipient is 'rentconnect2026@gmail.com'",
    $log4['recipient_email'] === 'rentconnect2026@gmail.com',
    "Actual: {$log4['recipient_email']}"
);

// 5. Test NotificationService::notifyLandlords for Ticket Submission
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();
NotificationService::notifyLandlords(
    $pdo,
    'maintenance',
    'Tenant Maintenance Ticket Submission',
    'Tenant: Test Resident • Unit: Unit 1 • Ticket Subject: AC filter replacement • Submitted: Nov 7, 2026',
    'sectionTickets',
    999905,
    'ticket_open',
    true
);

$log5 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("NotificationService dispatched landlord ticket email", !empty($log5));
assertCheck(
    "NotificationService ticket email recipient is 'rentconnect2026@gmail.com'",
    $log5['recipient_email'] === 'rentconnect2026@gmail.com',
    "Actual: {$log5['recipient_email']}"
);

// 6. Test Tenant notifications to ensure tenant recipients are strictly preserved
$maxLogIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM email_logs")->fetchColumn();
$tenantTestEmail = 'ljvillapandolucero123@gmail.com';
EmailService::notifyTenantPaymentVerified(
    $pdo,
    $tenantTestEmail,
    'Lara Janelle Lucero',
    'Unit 1',
    3000.00,
    '2026-11-01',
    999906,
    15
);
$log6 = $pdo->query("SELECT * FROM email_logs WHERE id > $maxLogIdBefore ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("Tenant notification dispatched", !empty($log6));
assertCheck(
    "Tenant recipient email preserved as {$tenantTestEmail}",
    $log6['recipient_email'] === $tenantTestEmail,
    "Actual: {$log6['recipient_email']}"
);

// 7. Clean up test records in notifications and email_logs
$pdo->exec("DELETE FROM notifications WHERE reference_id >= 999900");
$pdo->exec("DELETE FROM email_logs WHERE reference_id >= 999900");

echo "\n========================================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY!\n";
echo "========================================================\n";
