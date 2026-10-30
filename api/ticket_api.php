<?php
/**
 * RentConnect - Maintenance Ticket API Endpoint
 * Handles logging tickets (tenant) and updating resolution/costs (landlord)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';

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

        // Action 1: Landlord Updates Ticket Status / Cost
        if ($action === 'update_ticket') {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized action.']);
                exit;
            }

            $ticketId = (int)($input['ticket_id'] ?? 0);
            $newStatus = trim((string)($input['status'] ?? 'open'));
            $actualCost = (float)($input['actual_cost'] ?? 0.00);

            $stmt = $pdo->prepare("
                UPDATE maintenance_tickets 
                SET status = :s,
                    actual_cost = :c,
                    resolved_at = CASE WHEN :s IN ('resolved', 'closed') THEN NOW() ELSE NULL END
                WHERE id = :id
            ");
            $stmt->execute([
                ':s' => $newStatus,
                ':c' => $actualCost,
                ':id' => $ticketId
            ]);

            // Notify Tenant about ticket status update
            $ticketStmt = $pdo->prepare("SELECT tenant_id, title FROM maintenance_tickets WHERE id = :id LIMIT 1");
            $ticketStmt->execute([':id' => $ticketId]);
            $ticketRow = $ticketStmt->fetch();

            if ($ticketRow && !empty($ticketRow['tenant_id'])) {
                $statusLabel = ucfirst($newStatus);
                $titleText = ($newStatus === 'resolved' || $newStatus === 'closed') 
                    ? 'Maintenance Request Resolved' 
                    : 'Maintenance Ticket Updated';
                $msgText = ($newStatus === 'resolved')
                    ? "Your maintenance request #{$ticketId} ('{$ticketRow['title']}') is now marked as Resolved."
                    : "The owner updated maintenance ticket #{$ticketId} ('{$ticketRow['title']}') to status: {$statusLabel}.";
                NotificationService::notifyTenant(
                    $pdo,
                    (int)$ticketRow['tenant_id'],
                    'maintenance',
                    $titleText,
                    $msgText,
                    'tenantProfile',
                    $ticketId,
                    'ticket_status_' . $newStatus
                );
            }

            echo json_encode([
                'status' => 'success',
                'message' => "Ticket #{$ticketId} updated to {$newStatus} (\${$actualCost})."
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
                (:tenant_id, :unit_id, :title, :description, :photo_path, :category, :priority, 'open', 0.00, NOW())
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

        // Notify Landlords of newly logged maintenance ticket
        $tenantName = $tenantUser['name'] ?? 'Tenant';
        $unitText = $unitId ? " for Unit {$unitId}" : '';
        NotificationService::notifyLandlords(
            $pdo,
            'maintenance',
            'New Maintenance Ticket',
            "New maintenance ticket #{$newId} ('{$title}') submitted by {$tenantName}{$unitText}.",
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
                'status' => 'open',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit;
    }

    if ($requestMethod === 'GET') {
        $stmt = $pdo->query("
            SELECT mt.*, u.name AS tenant_name
            FROM maintenance_tickets mt
            JOIN users u ON mt.tenant_id = u.id
            ORDER BY mt.created_at DESC
        ");
        $tickets = $stmt->fetchAll();

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
