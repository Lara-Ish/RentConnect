<?php
/**
 * RentConnect - Rent Due Announcement API Endpoint
 * Restricted to Landlord / Owner.
 * Allows sending broadcast or unit-targeted rent announcements with email and in-app notifications.
 * Records complete announcement history and recipient delivery logs.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Security Check: Landlord Only
if (!Auth::isLandlord()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Only the property owner has permission to manage announcements.'
    ]);
    exit;
}

$pdo = Database::getConnection();
$currentUser = Auth::user();
$ownerId = $currentUser ? (int)$currentUser['id'] : 1;

try {
    // -------------------------------------------------------------------------
    // GET: Announcement History & Available Units/Tenants
    // -------------------------------------------------------------------------
    if ($requestMethod === 'GET') {
        // Fetch recent announcements with delivery counts
        $historyStmt = $pdo->query("
            SELECT a.id, a.title, a.message, a.due_date, a.target_type, a.recipient_count, a.created_at,
                   COUNT(ar.id) as total_recipients,
                   SUM(CASE WHEN ar.status IN ('sent', 'mock_sent') THEN 1 ELSE 0 END) as sent_count,
                   SUM(CASE WHEN ar.status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM announcements a
            LEFT JOIN announcement_recipients ar ON a.id = ar.announcement_id
            GROUP BY a.id
            ORDER BY a.created_at DESC
            LIMIT 20
        ");
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch active occupied units for selector
        $unitsStmt = $pdo->query("
            SELECT un.id as unit_id, un.unit_number, un.monthly_rent, u.id as tenant_id, u.name as tenant_name, u.email as tenant_email
            FROM units un
            JOIN users u ON u.unit_id = un.id AND u.role = 'tenant' AND u.is_active = 1
            ORDER BY un.unit_number ASC
        ");
        $activeUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'announcements' => $history,
            'active_units' => $activeUnits
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Send Rent Due Announcement
    // -------------------------------------------------------------------------
    if ($requestMethod === 'POST') {
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $input = is_array($rawInput) ? $rawInput : $_POST;

        $title = trim((string)($input['title'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        $dueDate = !empty($input['due_date']) ? trim((string)$input['due_date']) : null;
        $targetType = trim((string)($input['target_type'] ?? 'all'));
        if (!in_array($targetType, ['all', 'selected'], true)) {
            $targetType = 'all';
        }

        $targetUnitIds = [];
        if (isset($input['target_units']) && is_array($input['target_units'])) {
            $targetUnitIds = array_map('intval', $input['target_units']);
        } elseif (isset($input['unit_id']) && (int)$input['unit_id'] > 0) {
            $targetUnitIds = [(int)$input['unit_id']];
        }

        if (empty($title)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Announcement Title is required.']);
            exit;
        }

        if (empty($message)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Custom Message from Owner is required.']);
            exit;
        }

        // Fetch eligible recipients
        if ($targetType === 'selected' && !empty($targetUnitIds)) {
            $placeholders = implode(',', array_fill(0, count($targetUnitIds), '?'));
            $recipStmt = $pdo->prepare("
                SELECT u.id as tenant_id, u.name as tenant_name, u.email as tenant_email, 
                       un.id as unit_id, un.unit_number, un.monthly_rent
                FROM users u
                JOIN units un ON u.unit_id = un.id
                WHERE u.role = 'tenant' AND u.is_active = 1
                  AND un.id IN ($placeholders)
                ORDER BY un.unit_number ASC
            ");
            $recipStmt->execute($targetUnitIds);
        } else {
            // All active tenants
            $recipStmt = $pdo->query("
                SELECT u.id as tenant_id, u.name as tenant_name, u.email as tenant_email, 
                       un.id as unit_id, un.unit_number, un.monthly_rent
                FROM users u
                JOIN units un ON u.unit_id = un.id
                WHERE u.role = 'tenant' AND u.is_active = 1
                ORDER BY un.unit_number ASC
            ");
        }

        $recipients = $recipStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recipients)) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'No active tenants found for the selected target.'
            ]);
            exit;
        }

        // Insert announcement record
        $insAnn = $pdo->prepare("
            INSERT INTO announcements 
                (owner_id, title, message, due_date, target_type, recipient_count, created_at)
            VALUES 
                (:owner_id, :title, :message, :due_date, :target_type, :rcount, NOW())
        ");
        $insAnn->execute([
            ':owner_id'    => $ownerId,
            ':title'       => $title,
            ':message'     => $message,
            ':due_date'    => $dueDate ?: null,
            ':target_type' => $targetType,
            ':rcount'      => count($recipients)
        ]);
        $announcementId = (int)$pdo->lastInsertId();

        $sentCount = 0;
        $failedCount = 0;
        $recipientResults = [];

        foreach ($recipients as $tenant) {
            $tid = (int)$tenant['tenant_id'];
            $tEmail = trim((string)$tenant['tenant_email']);
            $tName = trim((string)$tenant['tenant_name']);
            $uNum = (string)$tenant['unit_number'];
            $rentAmt = (float)$tenant['monthly_rent'];

            $sent = false;
            $errorMsg = null;

            try {
                $sent = EmailService::sendRentDueAnnouncement(
                    $pdo,
                    $tEmail,
                    $tName,
                    $title,
                    $message,
                    $dueDate,
                    $uNum,
                    $rentAmt,
                    $announcementId,
                    $tid
                );
            } catch (Throwable $mailEx) {
                $errorMsg = $mailEx->getMessage();
                $sent = false;
            }

            $deliveryStatus = $sent ? 'sent' : 'failed';
            if ($sent) {
                $sentCount++;
            } else {
                $failedCount++;
            }

            // Record recipient log
            try {
                $insRec = $pdo->prepare("
                    INSERT INTO announcement_recipients 
                        (announcement_id, tenant_id, email, status, error_message, sent_at)
                    VALUES 
                        (:aid, :tid, :email, :status, :err, NOW())
                ");
                $insRec->execute([
                    ':aid'    => $announcementId,
                    ':tid'    => $tid,
                    ':email'  => $tEmail,
                    ':status' => $deliveryStatus,
                    ':err'    => $errorMsg
                ]);
            } catch (Throwable $e) {
                // Silently skip recipient insert failure
            }

            // In-app notification
            NotificationService::notifyTenant(
                $pdo,
                $tid,
                'payment',
                "Rent Announcement: {$title}",
                $message,
                'tenantPayments',
                $announcementId,
                'rent_announcement'
            );

            $recipientResults[] = [
                'tenant_id' => $tid,
                'name'      => $tName,
                'email'     => $tEmail,
                'unit'      => $uNum,
                'status'    => $deliveryStatus
            ];
        }

        echo json_encode([
            'status' => 'success',
            'message' => "Rent due announcement sent successfully to {$sentCount} tenant(s).",
            'announcement_id' => $announcementId,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'recipients' => $recipientResults
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Announcement API error: ' . $e->getMessage()
    ]);
}
