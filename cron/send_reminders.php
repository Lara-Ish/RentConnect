<?php
/**
 * RentConnect - Scheduled Email Reminders Script (Cron Job)
 * Executes periodic checks for upcoming due dates, due-today notices, overdue alerts, and lease expirations.
 *
 * Schedule:
 *   1. 3 days before due date
 *   2. 1 day before due date
 *   3. On the due date
 *   4. When the rent becomes overdue
 *
 * Excludes:
 *   - Tenants who have already paid or whose payments are already verified (amount_paid > 0 or status in 'paid', 'verified')
 *   - Placeholder rent records (pending_setup)
 *
 * Usage via CLI:
 *   php cron/send_reminders.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/email_service.php';
require_once dirname(__DIR__) . '/includes/notification_service.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
}

$pdo = Database::getConnection();

$summary = [
    'upcoming_3days_sent' => 0,
    'upcoming_1day_sent'  => 0,
    'due_today_sent'      => 0,
    'overdue_sent'        => 0,
    'lease_sent'          => 0,
    'duplicates_skipped'  => 0,
    'errors'              => []
];

// -----------------------------------------------------------------------------
// 1. Upcoming Payment Reminders (3 Days Before Due Date)
// -----------------------------------------------------------------------------
try {
    $upcoming3Stmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND DATEDIFF(p.due_date, CURDATE()) = 3
          AND u.is_active = 1
    ");
    $upcoming3Stmt->execute();
    while ($row = $upcoming3Stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'payment_reminder_upcoming_3days' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'upcoming_3days',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['upcoming_3days_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Upcoming Rental Payment Reminder',
                "Reminder: Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due in 3 days (" . date('F j, Y', strtotime($row['due_date'])) . ").",
                'tenantPayments',
                $pid,
                'payment_upcoming_3days'
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Upcoming 3-day scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 2. Upcoming Payment Reminders (1 Day Before Due Date)
// -----------------------------------------------------------------------------
try {
    $upcoming1Stmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND DATEDIFF(p.due_date, CURDATE()) = 1
          AND u.is_active = 1
    ");
    $upcoming1Stmt->execute();
    while ($row = $upcoming1Stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'payment_reminder_upcoming_1day' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'upcoming_1day',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['upcoming_1day_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Rental Payment Due Tomorrow',
                "Reminder: Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due tomorrow (" . date('F j, Y', strtotime($row['due_date'])) . ").",
                'tenantPayments',
                $pid,
                'payment_upcoming_1day'
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Upcoming 1-day scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 3. Payment Due Today Reminders
// -----------------------------------------------------------------------------
try {
    $dueTodayStmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND p.due_date = CURDATE()
          AND u.is_active = 1
    ");
    $dueTodayStmt->execute();
    while ($row = $dueTodayStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'payment_reminder_due_today' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'due_today',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['due_today_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Rental Payment Due Today',
                "Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is due today (" . date('F j, Y', strtotime($row['due_date'])) . "). Please submit your payment.",
                'tenantPayments',
                $pid,
                'payment_due_today'
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Due today scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 4. Overdue Payment Reminders
// -----------------------------------------------------------------------------
try {
    $overdueStmt = $pdo->prepare("
        SELECT p.id, p.tenant_id, p.amount_due, p.due_date, p.billing_cycle,
               u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM payments p
        JOIN users u ON p.tenant_id = u.id
        LEFT JOIN units un ON p.unit_id = un.id
        WHERE (p.status = 'overdue' OR (p.status = 'pending' AND p.due_date < CURDATE()))
          AND p.status NOT IN ('paid', 'verified', 'pending_setup')
          AND p.amount_paid = 0
          AND p.due_date IS NOT NULL
          AND u.is_active = 1
    ");
    $overdueStmt->execute();
    while ($row = $overdueStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $tid = (int)$row['tenant_id'];

        // Only send overdue reminder once per 3 days per payment
        $chk = $pdo->prepare("
            SELECT id FROM email_logs 
            WHERE recipient_email = :e 
              AND template_type = 'payment_reminder_overdue' 
              AND reference_id = :ref 
              AND sent_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            LIMIT 1
        ");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $pid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantPaymentReminder(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            (float)$row['amount_due'],
            $row['due_date'],
            'overdue',
            $pid,
            $tid
        );

        if ($sent) {
            $summary['overdue_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                'Overdue Payment Alert',
                "Your rent payment of ₱" . number_format((float)$row['amount_due'], 2) . " for " . ($row['unit_number'] ?: 'your unit') . " is overdue. Please submit your payment promptly.",
                'tenantPayments',
                $pid,
                'payment_overdue'
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Overdue scan error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 5. Upcoming Lease Expiration Reminders (30 Days Remaining)
// -----------------------------------------------------------------------------
try {
    $leaseStmt = $pdo->query("
        SELECT s.active_tenant_id, s.lease_end, u.name as tenant_name, u.email as tenant_email, un.unit_number
        FROM apartment_settings s
        JOIN users u ON s.active_tenant_id = u.id
        LEFT JOIN units un ON u.unit_id = un.id
        WHERE s.lease_end IS NOT NULL
          AND DATEDIFF(s.lease_end, CURDATE()) = 30
          AND u.is_active = 1
    ");
    while ($row = $leaseStmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['active_tenant_id'];

        $chk = $pdo->prepare("SELECT id FROM email_logs WHERE recipient_email = :e AND template_type = 'lease_expiring_30' AND reference_id = :ref LIMIT 1");
        $chk->execute([':e' => $row['tenant_email'], ':ref' => $tid]);
        if ($chk->fetch()) {
            $summary['duplicates_skipped']++;
            continue;
        }

        $sent = EmailService::notifyTenantLeaseExpiring(
            $pdo,
            $row['tenant_email'],
            $row['tenant_name'],
            $row['unit_number'] ?: 'Unit',
            $row['lease_end'],
            30,
            $tid,
            $tid
        );

        if ($sent) {
            $summary['lease_sent']++;
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'account',
                'Upcoming Lease Expiration Notice',
                "Your apartment lease is scheduled to end in 30 days (" . date('F j, Y', strtotime($row['lease_end'])) . ").",
                'tenantProfile',
                $tid,
                'lease_expiring'
            );
        }
    }
} catch (Throwable $e) {
    $summary['errors'][] = 'Lease expiration scan error: ' . $e->getMessage();
}

if ($isCli) {
    echo "=== TARARENT SCHEDULED REMINDERS SUMMARY ===\n";
    echo "Upcoming (3 Days):     {$summary['upcoming_3days_sent']}\n";
    echo "Upcoming (1 Day):      {$summary['upcoming_1day_sent']}\n";
    echo "Due Today Sent:        {$summary['due_today_sent']}\n";
    echo "Overdue Notices Sent:  {$summary['overdue_sent']}\n";
    echo "Lease Notices Sent:    {$summary['lease_sent']}\n";
    echo "Duplicates Skipped:    {$summary['duplicates_skipped']}\n";
    if (!empty($summary['errors'])) {
        echo "Errors encountered:\n";
        foreach ($summary['errors'] as $err) {
            echo " - $err\n";
        }
    }
    echo "Completed successfully at " . date('Y-m-d H:i:s') . "\n";
} else {
    echo json_encode(['status' => 'success', 'summary' => $summary, 'timestamp' => date('Y-m-d H:i:s')]);
}
