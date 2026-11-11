<?php
/**
 * TaraRent - Additional Rental Spaces API Endpoint
 * Manages 2 parking / extra lots (₱1,500/month each).
 * Handles tenant self-rental, admin assignment, and cancellation with atomic billing integration.
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

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Please sign in to access additional rental spaces.'
    ]);
    exit;
}

$currentUser = Auth::user();
$pdo = Database::getConnection();

try {
    // =========================================================================
    // 1. GET: Fetch List of Additional Spaces & Current Occupancy
    // =========================================================================
    if ($requestMethod === 'GET') {
        $stmt = $pdo->query("
            SELECT s.*, 
                   u.name AS tenant_name, 
                   u.username AS tenant_username, 
                   un.unit_number
            FROM additional_spaces s
            LEFT JOIN users u ON s.assigned_tenant_id = u.id
            LEFT JOIN units un ON s.assigned_unit_id = un.id
            ORDER BY s.space_number ASC
        ");
        $rawSpaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $spaces = [];
        $totalSpaces = count($rawSpaces);
        $availableCount = 0;
        $occupiedCount = 0;
        $myRentedCount = 0;
        $myExtraFee = 0.0;
        $totalMonthlyRevenue = 0.0;

        $userId = $currentUser ? (int)$currentUser['id'] : 0;
        $isLandlord = Auth::isLandlord();

        foreach ($rawSpaces as $row) {
            $isOccupied = ($row['status'] === 'occupied' && !empty($row['assigned_tenant_id']));
            $isRentedByMe = ($isOccupied && (int)$row['assigned_tenant_id'] === $userId);

            if ($isOccupied) {
                $occupiedCount++;
                $totalMonthlyRevenue += (float)$row['monthly_fee'];
            } else {
                $availableCount++;
            }

            if ($isRentedByMe) {
                $myRentedCount++;
                $myExtraFee += (float)$row['monthly_fee'];
            }

            $spaces[] = [
                'id' => (int)$row['id'],
                'space_number' => (int)$row['space_number'],
                'space_name' => $row['space_name'],
                'monthly_fee' => (float)$row['monthly_fee'],
                'monthly_fee_formatted' => '₱' . number_format((float)$row['monthly_fee'], 2),
                'status' => $row['status'],
                'is_occupied' => $isOccupied,
                'is_rented_by_me' => $isRentedByMe,
                'can_rent' => (!$isOccupied || $isRentedByMe),
                'assigned_tenant_id' => $row['assigned_tenant_id'] ? (int)$row['assigned_tenant_id'] : null,
                'tenant_name' => $row['tenant_name'],
                'tenant_username' => $row['tenant_username'],
                'assigned_unit_id' => $row['assigned_unit_id'] ? (int)$row['assigned_unit_id'] : null,
                'unit_number' => $row['unit_number'],
                'rented_at' => $row['rented_at'],
                'rented_at_formatted' => $row['rented_at'] ? date('M j, Y • g:i A', strtotime($row['rented_at'])) : null
            ];
        }

        echo json_encode([
            'status' => 'success',
            'summary' => [
                'total_spaces' => $totalSpaces,
                'available_count' => $availableCount,
                'occupied_count' => $occupiedCount,
                'total_monthly_revenue' => $totalMonthlyRevenue,
                'total_monthly_revenue_formatted' => '₱' . number_format($totalMonthlyRevenue, 2),
                'my_rented_count' => $myRentedCount,
                'my_extra_fee' => $myExtraFee,
                'my_extra_fee_formatted' => '₱' . number_format($myExtraFee, 2)
            ],
            'spaces' => $spaces
        ]);
        exit;
    }

    // =========================================================================
    // 2. POST: Rent or Cancel Additional Spaces
    // =========================================================================
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim((string)($input['action'] ?? ''));

        // ---------------------------------------------------------------------
        // Action: RENT SPACE (Tenant initiates or Admin assigns)
        // ---------------------------------------------------------------------
        if ($action === 'rent_space') {
            $spaceId = (int)($input['space_id'] ?? 0);
            if ($spaceId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid space ID specified.']);
                exit;
            }

            // Determine target tenant & unit
            if (Auth::isLandlord()) {
                $targetTenantId = (int)($input['tenant_id'] ?? 0);
                if ($targetTenantId <= 0) {
                    http_response_code(422);
                    echo json_encode(['status' => 'error', 'message' => 'Please select a tenant to assign this space to.']);
                    exit;
                }
                $tCheck = $pdo->prepare("SELECT id, name, unit_id FROM users WHERE id = :id AND role = 'tenant' AND is_active = 1 LIMIT 1");
                $tCheck->execute([':id' => $targetTenantId]);
                $targetTenant = $tCheck->fetch(PDO::FETCH_ASSOC);
                if (!$targetTenant) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Target tenant account was not found.']);
                    exit;
                }
                $tenantId = (int)$targetTenant['id'];
                $tenantName = $targetTenant['name'];
                $unitId = (int)$targetTenant['unit_id'];
            } else {
                // Tenant self-rental
                $tenantId = (int)$currentUser['id'];
                $tenantName = (string)$currentUser['name'];
                $unitId = (int)($currentUser['unit_id'] ?? 0);
                if ($unitId <= 0) {
                    $uStmt = $pdo->prepare("SELECT unit_id FROM users WHERE id = :id LIMIT 1");
                    $uStmt->execute([':id' => $tenantId]);
                    $unitId = (int)$uStmt->fetchColumn();
                }
            }

            if ($unitId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Tenant must be assigned to an apartment unit to rent additional space.']);
                exit;
            }

            // Fetch unit number
            $unitStmt = $pdo->prepare("SELECT unit_number, monthly_rent FROM units WHERE id = :uid LIMIT 1");
            $unitStmt->execute([':uid' => $unitId]);
            $unitRow = $unitStmt->fetch(PDO::FETCH_ASSOC);
            $unitNumber = $unitRow['unit_number'] ?? 'Unit';
            $baseRent = (float)($unitRow['monthly_rent'] ?? 3000.00);

            // Atomic database transaction with row-level lock
            $pdo->beginTransaction();

            $spaceStmt = $pdo->prepare("SELECT * FROM additional_spaces WHERE id = :id FOR UPDATE");
            $spaceStmt->execute([':id' => $spaceId]);
            $space = $spaceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$space) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Rental space not found.']);
                exit;
            }

            // Availability rule: If already rented by someone else, block
            if ($space['status'] === 'occupied' && !empty($space['assigned_tenant_id'])) {
                $pdo->rollBack();
                if ((int)$space['assigned_tenant_id'] === $tenantId) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => "You are already renting {$space['space_name']}."]);
                } else {
                    http_response_code(409);
                    echo json_encode(['status' => 'error', 'message' => "{$space['space_name']} is already occupied and rented by another tenant."]);
                }
                exit;
            }

            // Mark space as occupied by this tenant
            $updSpace = $pdo->prepare("
                UPDATE additional_spaces 
                SET status = 'occupied',
                    assigned_tenant_id = :tid,
                    assigned_unit_id = :uid,
                    rented_at = NOW()
                WHERE id = :id
            ");
            $updSpace->execute([
                ':tid' => $tenantId,
                ':uid' => $unitId,
                ':id' => $spaceId
            ]);

            // Recalculate tenant's total additional spaces and breakdown
            $activeSpacesStmt = $pdo->prepare("
                SELECT space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid AND status = 'occupied'
                ORDER BY space_number ASC
            ");
            $activeSpacesStmt->execute([':tid' => $tenantId]);
            $tenantSpaces = $activeSpacesStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalExtraFee = 0.0;
            $breakdownParts = [];
            foreach ($tenantSpaces as $ts) {
                $fee = (float)$ts['monthly_fee'];
                $totalExtraFee += $fee;
                $breakdownParts[] = "{$ts['space_name']} (₱" . number_format($fee, 2) . "/mo)";
            }
            $breakdownStr = implode(' + ', $breakdownParts);
            $totalMonthlyBill = $baseRent + $totalExtraFee;

            // Automatically sync tenant's active unpaid Rent bill (current cycle)
            $unpaidBillStmt = $pdo->prepare("
                SELECT id, base_rent_amount, amount_due, status 
                FROM payments 
                WHERE tenant_id = :tid 
                  AND payment_type = 'Rent' 
                  AND status IN ('unpaid', 'pending_setup')
                ORDER BY id DESC 
                LIMIT 1
                FOR UPDATE
            ");
            $unpaidBillStmt->execute([':tid' => $tenantId]);
            $unpaidBill = $unpaidBillStmt->fetch(PDO::FETCH_ASSOC);

            if ($unpaidBill) {
                $actualBaseRent = !empty($unpaidBill['base_rent_amount']) && (float)$unpaidBill['base_rent_amount'] > 0
                    ? (float)$unpaidBill['base_rent_amount']
                    : $baseRent;

                $newAmountDue = $actualBaseRent + $totalExtraFee;
                $vNotes = "Rent: ₱" . number_format($actualBaseRent, 2) . " + Additional Space: ₱" . number_format($totalExtraFee, 2) . " (" . $breakdownStr . ")";

                $updBillStmt = $pdo->prepare("
                    UPDATE payments 
                    SET base_rent_amount = :base,
                        additional_space_fee = :extra,
                        additional_spaces_breakdown = :breakdown,
                        amount_due = :total,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updBillStmt->execute([
                    ':base' => $actualBaseRent,
                    ':extra' => $totalExtraFee,
                    ':breakdown' => $breakdownStr,
                    ':total' => $newAmountDue,
                    ':notes' => $vNotes,
                    ':id' => $unpaidBill['id']
                ]);
            }

            $pdo->commit();

            // In-app notifications
            NotificationService::notifyLandlords(
                $pdo,
                'space_rental',
                'Additional Space Rented',
                "Tenant {$tenantName} ({$unitNumber}) has rented {$space['space_name']} for ₱1,500/month. Total monthly rent is now ₱" . number_format($totalMonthlyBill, 2) . ".",
                'sectionSpaces',
                $spaceId,
                'space_rented',
                false
            );

            NotificationService::notifyTenant(
                $pdo,
                $tenantId,
                'space_rental',
                'Additional Space Rented Successfully',
                "You have successfully rented {$space['space_name']} for ₱1,500/month. It has been added to your monthly bill (Total: ₱" . number_format($totalMonthlyBill, 2) . ").",
                'tenantPayments',
                $spaceId,
                'space_rented',
                false
            );

            echo json_encode([
                'status' => 'success',
                'message' => "Successfully rented {$space['space_name']}! ₱1,500.00 has been added to the monthly rental bill.",
                'space_id' => $spaceId,
                'space_name' => $space['space_name'],
                'base_rent' => $baseRent,
                'additional_space_fee' => $totalExtraFee,
                'additional_spaces_breakdown' => $breakdownStr,
                'total_monthly_bill' => $totalMonthlyBill,
                'total_monthly_bill_formatted' => '₱' . number_format($totalMonthlyBill, 2)
            ]);
            exit;
        }

        // ---------------------------------------------------------------------
        // Action: CANCEL SPACE RENTAL (Tenant stops rental or Admin releases)
        // ---------------------------------------------------------------------
        if ($action === 'cancel_space') {
            $spaceId = (int)($input['space_id'] ?? 0);
            if ($spaceId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid space ID specified.']);
                exit;
            }

            $pdo->beginTransaction();

            $spaceStmt = $pdo->prepare("SELECT * FROM additional_spaces WHERE id = :id FOR UPDATE");
            $spaceStmt->execute([':id' => $spaceId]);
            $space = $spaceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$space) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Rental space not found.']);
                exit;
            }

            if ($space['status'] !== 'occupied' || empty($space['assigned_tenant_id'])) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => "{$space['space_name']} is not currently occupied."]);
                exit;
            }

            $assignedTenantId = (int)$space['assigned_tenant_id'];

            // Security check: Only Landlord or the assigned Tenant can cancel
            if (!Auth::isLandlord() && $assignedTenantId !== (int)$currentUser['id']) {
                $pdo->rollBack();
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You can only cancel spaces rented by your account.']);
                exit;
            }

            // Fetch tenant and unit details for clean notification & recalculation
            $tStmt = $pdo->prepare("SELECT u.id, u.name, u.unit_id, un.unit_number, un.monthly_rent FROM users u LEFT JOIN units un ON u.unit_id = un.id WHERE u.id = :id LIMIT 1");
            $tStmt->execute([':id' => $assignedTenantId]);
            $tenantInfo = $tStmt->fetch(PDO::FETCH_ASSOC);
            $tenantName = $tenantInfo['name'] ?? 'Tenant';
            $unitNumber = $tenantInfo['unit_number'] ?? 'Unit';
            $baseRent = (float)($tenantInfo['monthly_rent'] ?? 3000.00);

            // Release space back to Available
            $relStmt = $pdo->prepare("
                UPDATE additional_spaces 
                SET status = 'available',
                    assigned_tenant_id = NULL,
                    assigned_unit_id = NULL,
                    rented_at = NULL
                WHERE id = :id
            ");
            $relStmt->execute([':id' => $spaceId]);

            // Recalculate remaining active spaces for this tenant
            $remSpacesStmt = $pdo->prepare("
                SELECT space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid AND status = 'occupied'
                ORDER BY space_number ASC
            ");
            $remSpacesStmt->execute([':tid' => $assignedTenantId]);
            $remainingSpaces = $remSpacesStmt->fetchAll(PDO::FETCH_ASSOC);

            $remainingExtraFee = 0.0;
            $remParts = [];
            foreach ($remainingSpaces as $rs) {
                $fee = (float)$rs['monthly_fee'];
                $remainingExtraFee += $fee;
                $remParts[] = "{$rs['space_name']} (₱" . number_format($fee, 2) . "/mo)";
            }
            $remainingBreakdown = !empty($remParts) ? implode(' + ', $remParts) : null;
            $newTotalMonthlyBill = $baseRent + $remainingExtraFee;

            // If tenant has an unpaid Rent bill, adjust its amount (paid bills remain completely untouched)
            $unpaidBillStmt = $pdo->prepare("
                SELECT id, base_rent_amount, amount_due, status 
                FROM payments 
                WHERE tenant_id = :tid 
                  AND payment_type = 'Rent' 
                  AND status IN ('unpaid', 'pending_setup')
                ORDER BY id DESC 
                LIMIT 1
                FOR UPDATE
            ");
            $unpaidBillStmt->execute([':tid' => $assignedTenantId]);
            $unpaidBill = $unpaidBillStmt->fetch(PDO::FETCH_ASSOC);

            if ($unpaidBill) {
                $actualBaseRent = !empty($unpaidBill['base_rent_amount']) && (float)$unpaidBill['base_rent_amount'] > 0
                    ? (float)$unpaidBill['base_rent_amount']
                    : $baseRent;

                $newAmountDue = $actualBaseRent + $remainingExtraFee;
                $vNotes = $remainingExtraFee > 0
                    ? "Rent: ₱" . number_format($actualBaseRent, 2) . " + Additional Space: ₱" . number_format($remainingExtraFee, 2) . " (" . $remainingBreakdown . ")"
                    : "Monthly rent for apartment unit.";

                $updBillStmt = $pdo->prepare("
                    UPDATE payments 
                    SET base_rent_amount = :base,
                        additional_space_fee = :extra,
                        additional_spaces_breakdown = :breakdown,
                        amount_due = :total,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updBillStmt->execute([
                    ':base' => $actualBaseRent,
                    ':extra' => $remainingExtraFee,
                    ':breakdown' => $remainingBreakdown,
                    ':total' => $newAmountDue,
                    ':notes' => $vNotes,
                    ':id' => $unpaidBill['id']
                ]);
            }

            $pdo->commit();

            // In-app notifications
            $cancelledByAdmin = Auth::isLandlord();
            $actorNotice = $cancelledByAdmin ? "Admin has released" : "Tenant {$tenantName} has cancelled";

            NotificationService::notifyLandlords(
                $pdo,
                'space_rental',
                'Additional Space Rental Released',
                "Rental for {$space['space_name']} ({$tenantName}, {$unitNumber}) has been cancelled. Space is now Available.",
                'sectionSpaces',
                $spaceId,
                'space_cancelled',
                false
            );

            NotificationService::notifyTenant(
                $pdo,
                $assignedTenantId,
                'space_rental',
                'Rental Space Cancelled',
                "Your rental for {$space['space_name']} has been cancelled. The ₱1,500/month fee has been removed. Total monthly rent is now ₱" . number_format($newTotalMonthlyBill, 2) . ".",
                'tenantPayments',
                $spaceId,
                'space_cancelled',
                false
            );

            echo json_encode([
                'status' => 'success',
                'message' => "{$space['space_name']} has been released and is now Available. The ₱1,500.00 fee has been removed from monthly rent.",
                'space_id' => $spaceId,
                'space_name' => $space['space_name'],
                'base_rent' => $baseRent,
                'additional_space_fee' => $remainingExtraFee,
                'additional_spaces_breakdown' => $remainingBreakdown,
                'total_monthly_bill' => $newTotalMonthlyBill,
                'total_monthly_bill_formatted' => '₱' . number_format($newTotalMonthlyBill, 2)
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action requested.']);
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Additional Spaces API Error: ' . $e->getMessage()
    ]);
    exit;
}
