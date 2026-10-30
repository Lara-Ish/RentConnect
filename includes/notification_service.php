<?php
/**
 * RentConnect - Centralized Notification Service
 * Manages event-driven and dynamic notifications for Landlord (Owner) and Tenant roles.
 */

declare(strict_types=1);

class NotificationService
{
    /**
     * Create a notification record for a specific user
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
        ?string $referenceType = null
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

        return (int)$pdo->lastInsertId();
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
        ?string $referenceType = null
    ): void {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'landlord' AND is_active = 1");
        $landlords = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($landlords)) {
            // Default to user id 1 if table is sparse
            self::create($pdo, 1, 'landlord', $type, $title, $message, $link, $referenceId, $referenceType);
            return;
        }

        foreach ($landlords as $lid) {
            self::create($pdo, (int)$lid, 'landlord', $type, $title, $message, $link, $referenceId, $referenceType);
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
        ?string $referenceType = null
    ): int {
        return self::create($pdo, $tenantId, 'tenant', $type, $title, $message, $link, $referenceId, $referenceType);
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
     * Synchronize and backfill system-event notifications so state is always complete
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
                    'payment_pending'
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
                    'ticket_open'
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
                        'unit_assignment'
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
                        'payment_approved'
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
                        'payment_rejected'
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
                            'payment_overdue'
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
                            'payment_due_today'
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
                            'payment_upcoming'
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
                        'ticket_resolved'
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
                        'ticket_in_progress'
                    );
                }
            }
        }
    }
}
