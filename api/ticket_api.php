<?php
/**
 * RentConnect - Maintenance Ticket API Endpoint
 * Handles logging tickets (tenant) and updating resolution/costs (landlord)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/email_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$pdo = Database::getConnection();

try {
    if ($requestMethod === 'POST') {
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $input = is_array($rawInput) ? $rawInput : $_POST;
        $action = trim((string)($input['action'] ?? 'create'));

        // Action 1: Landlord Updates Ticket Status / Cost / Reply
        if ($action === 'update_ticket' || $action === 'reply_ticket' || $action === 'update_status') {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized action.']);
                exit;
            }

            $ticketId = (int)($input['ticket_id'] ?? 0);
            $newStatus = trim((string)($input['status'] ?? ''));
            $actualCost = isset($input['actual_cost']) ? (float)$input['actual_cost'] : null;
            $reply = trim((string)($input['reply'] ?? ($input['resolution_notes'] ?? '')));

            // Fetch existing ticket details and tenant info
            $ticketStmt = $pdo->prepare("
                SELECT mt.*, u.name AS tenant_name, u.email AS tenant_email, un.unit_number
                FROM maintenance_tickets mt
                JOIN users u ON mt.tenant_id = u.id
                LEFT JOIN units un ON mt.unit_id = un.id
                WHERE mt.id = :id
                LIMIT 1
            ");
            $ticketStmt->execute([':id' => $ticketId]);
            $ticketRow = $ticketStmt->fetch();

            if (!$ticketRow) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Maintenance ticket not found.']);
                exit;
            }

            // Normalize status to strictly: Pending, On-Going, or Done
            $statusMap = [
                'pending' => 'Pending',
                'open' => 'Pending',
                'on-going' => 'On-Going',
                'ongoing' => 'On-Going',
                'in_progress' => 'On-Going',
                'done' => 'Done',
                'resolved' => 'Done',
                'closed' => 'Done'
            ];
            $statusKey = strtolower($newStatus);
            if (!empty($newStatus) && isset($statusMap[$statusKey])) {
                $statusToSave = $statusMap[$statusKey];
            } elseif (!empty($newStatus)) {
                $statusToSave = $newStatus;
            } else {
                $curKey = strtolower((string)$ticketRow['status']);
                $statusToSave = $statusMap[$curKey] ?? ($ticketRow['status'] ?: 'Pending');
            }

            $costToSave = $actualCost !== null ? $actualCost : (float)($ticketRow['actual_cost'] ?? 0);
            $replyToSave = !empty($reply) ? $reply : ($ticketRow['resolution_notes'] ?? '');

            $isResolved = in_array(strtolower($statusToSave), ['done', 'resolved', 'closed'], true);
            $resolvedAt = $isResolved ? ($ticketRow['resolved_at'] ?: date('Y-m-d H:i:s')) : null;

            $stmt = $pdo->prepare("
                UPDATE maintenance_tickets 
                SET status = :status,
                    actual_cost = :actual_cost,
                    resolution_notes = :resolution_notes,
                    resolved_at = :resolved_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $statusToSave,
                ':actual_cost' => $costToSave,
                ':resolution_notes' => $replyToSave,
                ':resolved_at' => $resolvedAt,
                ':id' => $ticketId
            ]);

            // Automatically send email to Tenant regarding Owner reply and status update
            // CRITICAL: Tenant email is dynamically retrieved from database via JOIN users u ON mt.tenant_id = u.id
            $ownerMessage = !empty($reply) ? $reply : "Your ticket status has been updated to " . $statusToSave . ".";
            $emailSent = false;
            $tenantId = (int)$ticketRow['tenant_id'];
            $tenantEmail = trim((string)($ticketRow['tenant_email'] ?? ''));

            try {
                if (!empty($tenantEmail) && filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailSent = EmailService::notifyTenantTicketReply(
                        $pdo,
                        $tenantEmail,
                        (string)$ticketRow['tenant_name'],
                        (string)$ticketRow['title'],
                        $ownerMessage,
                        $statusToSave,
                        $ticketId,
                        $tenantId
                    );
                } else {
                    EmailService::logEmail(
                        $pdo,
                        $tenantEmail,
                        (string)$ticketRow['tenant_name'],
                        "Maintenance Request Update - {$ticketRow['title']}",
                        'ticket_reply',
                        $ticketId,
                        'ticket',
                        'failed',
                        'Tenant record in database is missing a valid email address.',
                        $tenantId
                    );
                }
            } catch (Throwable $mailEx) {
                error_log('[Ticket API] Failed to send email reply: ' . $mailEx->getMessage());
            }

            // In-app notification directing to tenantMaintenance
            $statusLabel = $statusToSave;
            $titleText = ($statusToSave === 'Done' || $statusToSave === 'resolved' || $statusToSave === 'closed') 
                ? 'Maintenance Request Completed' 
                : 'Maintenance Ticket Updated (' . $statusLabel . ')';
            $msgText = !empty($reply)
                ? "Owner replied to ticket #{$ticketId} ('{$ticketRow['title']}'): {$reply}"
                : "The owner updated maintenance ticket #{$ticketId} ('{$ticketRow['title']}') to status: {$statusLabel}.";

            NotificationService::notifyTenant(
                $pdo,
                (int)$ticketRow['tenant_id'],
                'maintenance',
                $titleText,
                $msgText,
                'tenantMaintenance',
                $ticketId,
                'ticket_status_' . strtolower(str_replace('-', '', $statusToSave))
            );

            echo json_encode([
                'status' => 'success',
                'message' => "Ticket #{$ticketId} status updated to '{$statusToSave}'.",
                'email_sent' => $emailSent,
                'ticket' => [
                    'id' => $ticketId,
                    'status' => $statusToSave,
                    'actual_cost' => $costToSave,
                    'resolution_notes' => $replyToSave
                ]
            ]);
            exit;
        }

        // Action 2: Tenant Creates New Maintenance Ticket
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $category = trim((string)($input['category'] ?? 'General'));
        $priority = trim((string)($input['priority'] ?? 'medium'));

        $tenantUser = Auth::user();
        $tenantId = $tenantUser ? (int)$tenantUser['id'] : 2;
        $unitId = $tenantUser && isset($tenantUser['unit_id']) ? (int)$tenantUser['unit_id'] : 1;

        if (empty($title) || empty($description)) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'Both Title and Issue Description are required.'
            ]);
            exit;
        }

        $validCats = ['Plumbing', 'Electrical', 'HVAC', 'Appliance', 'Structural', 'General'];
        if (!in_array($category, $validCats, true)) $category = 'General';

        $validPrios = ['low', 'medium', 'high', 'urgent'];
        if (!in_array($priority, $validPrios, true)) $priority = 'medium';

        // Secure handling of uploaded maintenance photos
        $savedPhotos = [];
        $uploadDir = __DIR__ . '/../uploads/maintenance/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Support array uploads (maintenance_photos[], photos[]) and single file upload (photo)
        $fileList = [];
        foreach (['maintenance_photos', 'photos', 'photo'] as $key) {
            if (isset($_FILES[$key])) {
                $f = $_FILES[$key];
                if (is_array($f['name'])) {
                    $count = count($f['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if (isset($f['error'][$i]) && $f['error'][$i] === UPLOAD_ERR_OK && !empty($f['tmp_name'][$i])) {
                            $fileList[] = [
                                'name' => $f['name'][$i],
                                'type' => $f['type'][$i] ?? '',
                                'tmp_name' => $f['tmp_name'][$i],
                                'error' => $f['error'][$i],
                                'size' => $f['size'][$i]
                            ];
                        }
                    }
                } elseif (isset($f['error']) && $f['error'] === UPLOAD_ERR_OK && !empty($f['tmp_name'])) {
                    $fileList[] = $f;
                }
            }
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        foreach ($fileList as $fileItem) {
            // Validate size (max 10MB per image)
            if ($fileItem['size'] > 10 * 1024 * 1024) {
                continue;
            }

            $ext = strtolower(pathinfo($fileItem['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                continue;
            }

            $mime = finfo_file($finfo, $fileItem['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                continue;
            }

            // Cryptographically secure randomized filename to prevent overwrites or directory traversal
            $safeFilename = 'maint_' . $tenantId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $uploadDir . $safeFilename;

            if (move_uploaded_file($fileItem['tmp_name'], $destPath)) {
                $savedPhotos[] = 'uploads/maintenance/' . $safeFilename;
            }
        }
        if ($finfo) {
            finfo_close($finfo);
        }

        $photoPathStr = !empty($savedPhotos) 
            ? implode(',', $savedPhotos) 
            : (isset($input['photo_path']) && !empty(trim((string)$input['photo_path'])) ? trim((string)$input['photo_path']) : null);

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_tickets 
                (tenant_id, unit_id, title, description, photo_path, category, priority, status, actual_cost, created_at)
            VALUES 
                (:tenant_id, :unit_id, :title, :description, :photo_path, :category, :priority, 'Pending', 0.00, NOW())
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':unit_id' => $unitId,
            ':title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            ':description' => htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
            ':photo_path' => $photoPathStr,
            ':category' => $category,
            ':priority' => $priority
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Retrieve unit number and ticket info for exact notification display
        $tUnitStmt = $pdo->prepare("SELECT unit_number FROM units WHERE id = :uid LIMIT 1");
        $tUnitStmt->execute([':uid' => $unitId]);
        $tUnitNum = $tUnitStmt->fetchColumn() ?: ('Unit ' . $unitId);

        $tenantName = $tenantUser['name'] ?? 'Tenant';
        $subDate = date('M j, Y');

        // Notify Landlords of newly logged maintenance ticket (strictly Tenant Maintenance Ticket Submission)
        NotificationService::notifyLandlords(
            $pdo,
            'maintenance',
            'Tenant Maintenance Ticket Submission',
            "Tenant: {$tenantName} • Unit: {$tUnitNum} • Ticket Subject: {$title} • Submitted: {$subDate}",
            'sectionTickets',
            $newId,
            'ticket_open'
        );

        echo json_encode([
            'status' => 'success',
            'message' => "Maintenance request #{$newId} logged successfully. Landlord has been alerted.",
            'ticket' => [
                'id' => $newId,
                'title' => $title,
                'description' => $description,
                'photo_path' => $photoPathStr,
                'category' => $category,
                'priority' => $priority,
                'status' => 'Pending',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit;
    }

    if ($requestMethod === 'GET') {
        $currentUser = Auth::user();
        $ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        $normalizeStatus = function($st) {
            $s = strtolower((string)$st);
            if ($s === 'open' || $s === 'pending') return 'Pending';
            if ($s === 'in_progress' || $s === 'on-going' || $s === 'ongoing') return 'On-Going';
            if ($s === 'resolved' || $s === 'closed' || $s === 'done') return 'Done';
            return $st ?: 'Pending';
        };

        if ($ticketId > 0) {
            $query = "
                SELECT mt.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, un.unit_number
                FROM maintenance_tickets mt
                JOIN users u ON mt.tenant_id = u.id
                LEFT JOIN units un ON mt.unit_id = un.id
                WHERE mt.id = :id
            ";
            $params = [':id' => $ticketId];

            if (Auth::isTenant() && $currentUser) {
                $query .= " AND mt.tenant_id = :tid";
                $params[':tid'] = (int)$currentUser['id'];
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Maintenance ticket not found or access restricted.']);
                exit;
            }

            $ticket['status'] = $normalizeStatus($ticket['status']);

            echo json_encode([
                'status' => 'success',
                'ticket' => $ticket
            ]);
            exit;
        }

        // List maintenance requests with strict tenant filtering
        $query = "
            SELECT mt.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, un.unit_number
            FROM maintenance_tickets mt
            JOIN users u ON mt.tenant_id = u.id
            LEFT JOIN units un ON mt.unit_id = un.id
        ";
        $params = [];

        if (Auth::isTenant() && $currentUser) {
            $query .= " WHERE mt.tenant_id = :tid";
            $params[':tid'] = (int)$currentUser['id'];
        }

        $query .= " ORDER BY mt.created_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        foreach ($tickets as &$t) {
            $t['status'] = $normalizeStatus($t['status']);
        }
        unset($t);

        echo json_encode([
            'status' => 'success',
            'count' => count($tickets),
            'tickets' => $tickets
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ticket service error: ' . $e->getMessage()
    ]);
}
