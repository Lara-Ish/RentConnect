<?php
/**
 * RentConnect - Centralized Notification Service
 * Manages event-driven and dynamic notifications for Landlord (Owner) and Tenant roles.
 * Bridges in-app notifications with PHPMailer email notifications seamlessly.
 */

declare(strict_types=1);

require_once __DIR__ . '/email_service.php';

class NotificationService
{
    /**
     * Create a notification record for a specific user and optionally trigger email dispatch
     */
    public static function create(
        PDO $pdo,
        int $userId,
        string $role,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $sendEmail = true
    ): int {
        // Prevent duplicate notifications for the same reference event if still recent
        if ($referenceId !== null && $referenceType !== null) {
            $dupStmt = $pdo->prepare("
                SELECT id FROM notifications 
                WHERE user_id = :uid 
                  AND reference_id = :ref_id 
                  AND reference_type = :ref_type 
                  AND title = :title 
                LIMIT 1
            ");
            $dupStmt->execute([
                ':uid' => $userId,
                ':ref_id' => $referenceId,
                ':ref_type' => $referenceType,
                ':title' => $title
            ]);
            $existing = $dupStmt->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications 
                (user_id, role, type, title, message, link, reference_id, reference_type, is_read, created_at)
            VALUES 
                (:user_id, :role, :type, :title, :message, :link, :reference_id, :reference_type, 0, NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':role' => $role,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':link' => $link,
            ':reference_id' => $referenceId,
            ':reference_type' => $referenceType
        ]);

        $notificationId = (int)$pdo->lastInsertId();

        // Dispatch corresponding email via EmailService
        // Important: Email failure MUST NOT interrupt in-app notifications or calling operations
        if ($sendEmail) {
            try {
                self::dispatchEmail(
                    $pdo,
                    $userId,
                    $role,
                    $type,
                    $title,
                    $message,
                    $referenceId,
                    $referenceType
                );
            } catch (Throwable $e) {
                error_log("[RentConnect Notification] Email dispatch failed: " . $e->getMessage());
            }
        }

        return $notificationId;
    }

    /**
     * Dispatch corresponding email notification via EmailService
     */
    public static function dispatchEmail(
        PDO $pdo,
        int $userId,
        string $role,
        string $type,
        string $title,
        string $message,
        ?int $referenceId,
        ?string $referenceType
    ): void {
        // Fetch recipient account details dynamically from database
        $uStmt = $pdo->prepare("
            SELECT u.id, u.name, u.email, u.role, u.unit_id, un.unit_number
            FROM users u
            LEFT JOIN units un ON u.unit_id = un.id
            WHERE u.id = :uid AND u.is_active = 1
            LIMIT 1
        ");
        $uStmt->execute([':uid' => $userId]);
        $recipient = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipient || empty($recipient['email'])) {
            return;
        }

        $recipientEmail = $recipient['email'];
        $recipientName  = $recipient['name'] ?? 'User';
        $userUnit       = $recipient['unit_number'] ?: 'Unit';

        // -------------------------------------------------------------
        // OWNER / LANDLORD NOTIFICATIONS
        // -------------------------------------------------------------
        if ($role === 'landlord') {
            // 1. Payment Proof Submitted / Verification Required
            if ($type === 'payment' && in_array($referenceType, ['payment_pending', 'payment_submitted', 'payment_verification'], true)) {
                $amountPaid = 0.0;
                $billingCycle = date('Y-m-d');
                $tenantName = 'Tenant';
                $unitNum = 'Unit';

                if ($referenceId) {
                    $pStmt = $pdo->prepare("
                        SELECT p.amount_paid, p.amount_due, p.billing_cycle, u.name as tenant_name, un.unit_number
                        FROM payments p
                        JOIN users u ON p.tenant_id = u.id
                        LEFT JOIN units un ON p.unit_id = un.id
                        WHERE p.id = :pid
                        LIMIT 1
                    ");
                    $pStmt->execute([':pid' => $referenceId]);
                    $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pData) {
                        $amountPaid = (float)($pData['amount_paid'] > 0 ? $pData['amount_paid'] : $pData['amount_due']);
                        $billingCycle = $pData['billing_cycle'];
                        $tenantName = $pData['tenant_name'];
                        $unitNum = $pData['unit_number'] ?: 'Unit';
                    }
                }

                EmailService::notifyOwnerPaymentProofSubmitted(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $tenantName,
                    $unitNum,
                    $amountPaid,
                    $billingCycle,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 2. New Maintenance Ticket Created
            if ($type === 'maintenance' && in_array($referenceType, ['ticket_open', 'ticket_created'], true)) {
                $ticketTitle = 'Maintenance Request';
                $category = 'General';
                $priority = 'normal';
                $tenantName = 'Tenant';
                $unitNum = 'Unit';

                if ($referenceId) {
                    $tStmt = $pdo->prepare("
                        SELECT mt.title, mt.category, mt.priority, u.name as tenant_name, un.unit_number
                        FROM maintenance_tickets mt
                        JOIN users u ON mt.tenant_id = u.id
                        LEFT JOIN units un ON mt.unit_id = un.id
                        WHERE mt.id = :tid
                        LIMIT 1
                    ");
                    $tStmt->execute([':tid' => $referenceId]);
                    $tData = $tStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tData) {
                        $ticketTitle = $tData['title'];
                        $category    = $tData['category'];
                        $priority    = $tData['priority'];
                        $tenantName  = $tData['tenant_name'];
                        $unitNum     = $tData['unit_number'] ?: 'Unit';
                    }
                }

                EmailService::notifyOwnerMaintenanceTicketCreated(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $tenantName,
                    $unitNum,
                    $ticketTitle,
                    $category,
                    $priority,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 3. New Tenant Assigned / Registered
            if ($referenceType === 'tenant_registered' || $title === 'New Tenant Assigned') {
                $newTenantName = 'Tenant';
                $newUnit = 'Unit';
                if ($referenceId) {
                    $tenStmt = $pdo->prepare("
                        SELECT u.name, un.unit_number 
                        FROM users u 
                        LEFT JOIN units un ON u.unit_id = un.id 
                        WHERE u.id = :uid 
                        LIMIT 1
                    ");
                    $tenStmt->execute([':uid' => $referenceId]);
                    $tenData = $tenStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tenData) {
                        $newTenantName = $tenData['name'];
                        $newUnit = $tenData['unit_number'] ?: 'Unit';
                    }
                }

                EmailService::notifyOwnerTenantRegistered(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $newTenantName,
                    $newUnit,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 4. Tenant Account / Profile Updated
            if ($referenceType === 'tenant_profile_update' || str_contains(strtolower($title), 'profile updated')) {
                $tName = 'Tenant';
                $tUnit = 'Unit';
                if ($referenceId) {
                    $tenStmt = $pdo->prepare("
                        SELECT u.name, un.unit_number 
                        FROM users u 
                        LEFT JOIN units un ON u.unit_id = un.id 
                        WHERE u.id = :uid 
                        LIMIT 1
                    ");
                    $tenStmt->execute([':uid' => $referenceId]);
                    $tData = $tenStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tData) {
                        $tName = $tData['name'];
                        $tUnit = $tData['unit_number'] ?: 'Unit';
                    }
                }

                EmailService::notifyOwnerTenantAccountUpdated(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $tName,
                    $tUnit,
                    $message,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // Fallback: General Landlord Notification
            $config = MailConfig::get();
            EmailService::sendGeneralNotification(
                $pdo,
                $recipientEmail,
                $recipientName,
                $title,
                $message,
                $config['app_url'] . '/admin_dashboard.php',
                'Open Admin Dashboard',
                $userId
            );
            return;
        }

        // -------------------------------------------------------------
        // TENANT NOTIFICATIONS
        // -------------------------------------------------------------
        if ($role === 'tenant') {
            // 1. Payment Proof Verified
            if ($type === 'payment' && in_array($referenceType, ['payment_approved', 'payment_verified'], true)) {
                $amountPaid = 0.0;
                $billingCycle = date('Y-m-d');
                $unitNum = $userUnit;

                if ($referenceId) {
                    $pStmt = $pdo->prepare("
                        SELECT p.amount_paid, p.amount_due, p.billing_cycle, un.unit_number
                        FROM payments p
                        LEFT JOIN units un ON p.unit_id = un.id
                        WHERE p.id = :pid
                        LIMIT 1
                    ");
                    $pStmt->execute([':pid' => $referenceId]);
                    $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pData) {
                        $amountPaid = (float)($pData['amount_paid'] > 0 ? $pData['amount_paid'] : $pData['amount_due']);
                        $billingCycle = $pData['billing_cycle'];
                        $unitNum = $pData['unit_number'] ?: $userUnit;
                    }
                }

                EmailService::notifyTenantPaymentVerified(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $unitNum,
                    $amountPaid,
                    $billingCycle,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 2. Payment Proof Rejected
            if ($type === 'payment' && in_array($referenceType, ['payment_rejected', 'payment_correction'], true)) {
                $amountPaid = 0.0;
                $notes = '';
                $unitNum = $userUnit;

                if ($referenceId) {
                    $pStmt = $pdo->prepare("
                        SELECT p.amount_paid, p.amount_due, p.verification_notes, un.unit_number
                        FROM payments p
                        LEFT JOIN units un ON p.unit_id = un.id
                        WHERE p.id = :pid
                        LIMIT 1
                    ");
                    $pStmt->execute([':pid' => $referenceId]);
                    $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pData) {
                        $amountPaid = (float)($pData['amount_paid'] > 0 ? $pData['amount_paid'] : $pData['amount_due']);
                        $notes = (string)($pData['verification_notes'] ?? '');
                        $unitNum = $pData['unit_number'] ?: $userUnit;
                    }
                }

                EmailService::notifyTenantPaymentRejected(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $unitNum,
                    $amountPaid,
                    $notes,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 3. Payment Reminders (Upcoming, Due Today, Overdue)
            if ($type === 'payment' && in_array($referenceType, ['payment_upcoming', 'payment_due_today', 'payment_overdue'], true)) {
                $remType = str_replace('payment_', '', $referenceType);
                $amountDue = 0.0;
                $dueDate = date('Y-m-d');
                $unitNum = $userUnit;

                if ($referenceId) {
                    $pStmt = $pdo->prepare("
                        SELECT p.amount_due, p.due_date, un.unit_number
                        FROM payments p
                        LEFT JOIN units un ON p.unit_id = un.id
                        WHERE p.id = :pid
                        LIMIT 1
                    ");
                    $pStmt->execute([':pid' => $referenceId]);
                    $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pData) {
                        $amountDue = (float)$pData['amount_due'];
                        $dueDate   = (string)$pData['due_date'];
                        $unitNum   = $pData['unit_number'] ?: $userUnit;
                    }
                }

                EmailService::notifyTenantPaymentReminder(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $unitNum,
                    $amountDue,
                    $dueDate,
                    $remType,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 4. Maintenance Ticket Status Updated / Resolved
            if ($type === 'maintenance' && $referenceId) {
                $ticketTitle = 'Maintenance Request';
                $status = 'updated';
                $notes = '';
                $unitNum = $userUnit;

                $tStmt = $pdo->prepare("
                    SELECT mt.title, mt.status, mt.resolution_notes, un.unit_number
                    FROM maintenance_tickets mt
                    LEFT JOIN units un ON mt.unit_id = un.id
                    WHERE mt.id = :tid
                    LIMIT 1
                ");
                $tStmt->execute([':tid' => $referenceId]);
                $tData = $tStmt->fetch(PDO::FETCH_ASSOC);
                if ($tData) {
                    $ticketTitle = $tData['title'];
                    $status      = $tData['status'];
                    $notes       = (string)($tData['resolution_notes'] ?? '');
                    $unitNum     = $tData['unit_number'] ?: $userUnit;
                }

                EmailService::notifyTenantMaintenanceUpdated(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $unitNum,
                    $ticketTitle,
                    $status,
                    $notes,
                    $referenceId,
                    $userId
                );
                return;
            }

            // 5. Important Announcements from Owner
            if ($type === 'announcement' || str_contains((string)$referenceType, 'announcement')) {
                EmailService::notifyTenantAnnouncement(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $title,
                    $message,
                    $referenceId ?: 0,
                    $userId
                );
                return;
            }

            // 6. Welcome New Tenant
            if ($referenceType === 'tenant_welcome' || str_contains(strtolower($title), 'welcome')) {
                EmailService::notifyTenantWelcome(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $userUnit,
                    $userId,
                    $userId
                );
                return;
            }

            // 7. Password Reset Success
            if ($referenceType === 'password_reset' || str_contains(strtolower($title), 'password reset')) {
                EmailService::notifyPasswordResetSuccess(
                    $pdo,
                    $recipientEmail,
                    $recipientName,
                    $userId
                );
                return;
            }

            // Fallback: General Tenant Notification
            $config = MailConfig::get();
            EmailService::sendGeneralNotification(
                $pdo,
                $recipientEmail,
                $recipientName,
                $title,
                $message,
                $config['app_url'] . '/tenant_portal.php',
                'Open Tenant Portal',
                $userId
            );
            return;
        }
    }

    /**
     * Send notification to all active Landlords / Owners
     */
    public static function notifyLandlords(
        PDO $pdo,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $sendEmail = true
    ): void {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'landlord' AND is_active = 1");
        $landlords = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($landlords)) {
            // Default to user id 1 if table is sparse
            self::create($pdo, 1, 'landlord', $type, $title, $message, $link, $referenceId, $referenceType, $sendEmail);
            return;
        }

        foreach ($landlords as $lid) {
            self::create($pdo, (int)$lid, 'landlord', $type, $title, $message, $link, $referenceId, $referenceType, $sendEmail);
        }
    }

    /**
     * Send notification to a specific Tenant
     */
    public static function notifyTenant(
        PDO $pdo,
        int $tenantId,
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $sendEmail = true
    ): int {
        return self::create($pdo, $tenantId, 'tenant', $type, $title, $message, $link, $referenceId, $referenceType, $sendEmail);
    }

    /**
     * Broadcast an important announcement to all active tenants
     */
    public static function broadcastAnnouncement(PDO $pdo, string $title, string $message): int
    {
        $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'tenant' AND is_active = 1");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        foreach ($tenants as $t) {
            self::notifyTenant(
                $pdo,
                (int)$t['id'],
                'announcement',
                $title,
                $message,
                'tenantProfile',
                null,
                'announcement_broadcast',
                true
            );
            $count++;
        }

        return $count;
    }

    /**
     * Fetch all notifications for a specific user, sorted newest first
     */
    public static function getForUser(PDO $pdo, int $userId, int $limit = 50): array
    {
        $stmt = $pdo->prepare("
            SELECT id, user_id, role, type, title, message, link, reference_id, reference_type, is_read, created_at
            FROM notifications
            WHERE user_id = :uid
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['time_ago'] = self::formatTimeAgo($r['created_at']);
            $r['is_read'] = (int)$r['is_read'];
            $r['formatted_date'] = date('M j, Y • g:i A', strtotime($r['created_at']));
        }

        return $rows;
    }

    /**
     * Count unread notifications for a user
     */
    public static function getUnreadCount(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mark an individual notification as read
     */
    public static function markAsRead(PDO $pdo, int $notificationId, int $userId): bool
    {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = :id AND user_id = :uid
        ");
        return $stmt->execute([':id' => $notificationId, ':uid' => $userId]);
    }

    /**
     * Mark all notifications as read for a user
     */
    public static function markAllAsRead(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = :uid AND is_read = 0
        ");
        return $stmt->execute([':uid' => $userId]);
    }

    /**
     * Human-friendly relative time string
     */
    public static function formatTimeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $mins = (int)floor($diff / 60);
            return $mins . 'm ago';
        }
        if ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            return $hours . 'h ago';
        }
        if ($diff < 604800) {
            $days = (int)floor($diff / 86400);
            return $days . 'd ago';
        }
        return date('M j', $timestamp);
    }

    /**
     * Synchronize and backfill system-event notifications for in-app badge display
     * Note: Passes sendEmail = false to prevent sending emails on simple page load/dashboard syncs.
     */
    public static function syncSystemNotifications(PDO $pdo, array $user): void
    {
        $userId = (int)($user['id'] ?? 0);
        $role = $user['role'] ?? 'tenant';
        if ($userId <= 0) return;

        $today = date('Y-m-d');

        if ($role === 'landlord') {
            // 1. Sync pending payments needing verification
            $pendingPaymentsStmt = $pdo->query("
                SELECT p.id, p.billing_cycle, p.amount_paid, u.name as tenant_name, un.unit_number
                FROM payments p
                JOIN users u ON p.tenant_id = u.id
                LEFT JOIN units un ON p.unit_id = un.id
                WHERE p.status = 'pending'
            ");
            while ($p = $pendingPaymentsStmt->fetch(PDO::FETCH_ASSOC)) {
                $month = date('F Y', strtotime($p['billing_cycle']));
                $unitStr = $p['unit_number'] ? ' (' . $p['unit_number'] . ')' : '';
                self::create(
                    $pdo,
                    $userId,
                    'landlord',
                    'payment',
                    'Payment Verification Required',
                    "{$p['tenant_name']}{$unitStr} submitted payment proof for {$month}. Verification is required.",
                    'sectionPayments',
                    (int)$p['id'],
                    'payment_pending',
                    false // do not email on routine in-app sync
                );
            }

            // 2. Sync open maintenance tickets
            $openTicketsStmt = $pdo->query("
                SELECT mt.id, mt.title, mt.category, mt.priority, u.name as tenant_name, un.unit_number
                FROM maintenance_tickets mt
                JOIN users u ON mt.tenant_id = u.id
                LEFT JOIN units un ON mt.unit_id = un.id
                WHERE mt.status = 'open'
            ");
            while ($t = $openTicketsStmt->fetch(PDO::FETCH_ASSOC)) {
                $unitStr = $t['unit_number'] ? ' for ' . $t['unit_number'] : '';
                self::create(
                    $pdo,
                    $userId,
                    'landlord',
                    'maintenance',
                    'New Maintenance Ticket',
                    "New maintenance ticket #{$t['id']} ('{$t['title']}') submitted by {$t['tenant_name']}{$unitStr}.",
                    'sectionTickets',
                    (int)$t['id'],
                    'ticket_open',
                    false // do not email on routine in-app sync
                );
            }
        } elseif ($role === 'tenant') {
            // 1. Check unit assignment welcome
            if (!empty($user['unit_id'])) {
                $unitStmt = $pdo->prepare("SELECT unit_number, monthly_rent FROM units WHERE id = :uid LIMIT 1");
                $unitStmt->execute([':uid' => $user['unit_id']]);
                $unitRow = $unitStmt->fetch(PDO::FETCH_ASSOC);
                if ($unitRow) {
                    self::create(
                        $pdo,
                        $userId,
                        'tenant',
                        'account',
                        'Unit Assignment Confirmed',
                        "You are assigned to {$unitRow['unit_number']} with a monthly rent of ₱" . number_format((float)$unitRow['monthly_rent'], 2) . ".",
                        'tenantProfile',
                        (int)$user['unit_id'],
                        'unit_assignment',
                        false // do not email on routine in-app sync
                    );
                }
            }

            // 2. Check tenant payment records & due dates
            $paymentsStmt = $pdo->prepare("
                SELECT id, billing_cycle, due_date, amount_due, amount_paid, status, verification_notes
                FROM payments
                WHERE tenant_id = :tid
                ORDER BY billing_cycle DESC
            ");
            $paymentsStmt->execute([':tid' => $userId]);
            while ($p = $paymentsStmt->fetch(PDO::FETCH_ASSOC)) {
                $month = date('F Y', strtotime($p['billing_cycle']));
                $dueDate = $p['due_date'];

                if ($p['status'] === 'approved') {
                    self::create(
                        $pdo,
                        $userId,
                        'tenant',
                        'payment',
                        'Payment Proof Verified',
                        "Your payment proof for {$month} has been verified and approved by the owner.",
                        'tenantProfile',
                        (int)$p['id'],
                        'payment_approved',
                        false
                    );
                } elseif ($p['status'] === 'rejected') {
                    $notes = !empty($p['verification_notes']) ? ' (' . $p['verification_notes'] . ')' : '';
                    self::create(
                        $pdo,
                        $userId,
                        'tenant',
                        'payment',
                        'Payment Proof Rejected',
                        "Your payment proof for {$month} was rejected{$notes}. Please check payment details and re-submit.",
                        'tenantProfile',
                        (int)$p['id'],
                        'payment_rejected',
                        false
                    );
                } elseif ($p['status'] === 'pending') {
                    if ($today > $dueDate && (float)$p['amount_paid'] <= 0) {
                        self::create(
                            $pdo,
                            $userId,
                            'tenant',
                            'payment',
                            'Rental Payment Overdue',
                            "Your rental payment for {$month} is overdue. Please submit your payment receipt.",
                            'tenantProfile',
                            (int)$p['id'],
                            'payment_overdue',
                            false
                        );
                    } elseif ($today === $dueDate && (float)$p['amount_paid'] <= 0) {
                        self::create(
                            $pdo,
                            $userId,
                            'tenant',
                            'payment',
                            'Rental Payment Due Today',
                            "Your rental payment for {$month} (₱" . number_format((float)$p['amount_due'], 2) . ") is due today.",
                            'tenantProfile',
                            (int)$p['id'],
                            'payment_due_today',
                            false
                        );
                    } else {
                        self::create(
                            $pdo,
                            $userId,
                            'tenant',
                            'payment',
                            'Upcoming Rental Payment Due',
                            "Your rental payment for {$month} is due on " . date('F j, Y', strtotime($dueDate)) . ".",
                            'tenantProfile',
                            (int)$p['id'],
                            'payment_upcoming',
                            false
                        );
                    }
                }
            }

            // 3. Check maintenance tickets for this tenant
            $ticketsStmt = $pdo->prepare("
                SELECT id, title, status, resolution_notes
                FROM maintenance_tickets
                WHERE tenant_id = :tid
                ORDER BY id DESC
            ");
            $ticketsStmt->execute([':tid' => $userId]);
            while ($t = $ticketsStmt->fetch(PDO::FETCH_ASSOC)) {
                if ($t['status'] === 'resolved') {
                    self::create(
                        $pdo,
                        $userId,
                        'tenant',
                        'maintenance',
                        'Maintenance Request Resolved',
                        "Your maintenance request #{$t['id']} ('{$t['title']}') is now marked as Resolved.",
                        'tenantProfile',
                        (int)$t['id'],
                        'ticket_resolved',
                        false
                    );
                } elseif ($t['status'] === 'in_progress') {
                    self::create(
                        $pdo,
                        $userId,
                        'tenant',
                        'maintenance',
                        'Maintenance In Progress',
                        "The owner has updated ticket #{$t['id']} ('{$t['title']}'). Work is currently in progress.",
                        'tenantProfile',
                        (int)$t['id'],
                        'ticket_in_progress',
                        false
                    );
                }
            }
        }
    }
}
