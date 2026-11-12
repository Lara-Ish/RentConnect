<?php
/**
 * RentConnect - Tenant Active Directory CRUD API
 * Restricted to Landlord (Admin).
 * Manages tenant personal records across the 3 units & move-out account deletions.
 * Auto-generates sequential username (RC001, RC002, ...) and 6-digit numeric password.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/tenant_service.php';

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
        'message' => 'Unauthorized: Only the landlord has permission to manage the tenant directory.'
    ]);
    exit;
}

$pdo = Database::getConnection();

try {
    // -------------------------------------------------------------------------
    // 1. GET: Fetch Tenants Grouped by the 3 Units OR Fetch Archived Tenants
    // -------------------------------------------------------------------------
    if ($requestMethod === 'GET') {
        $action = trim((string)($_GET['action'] ?? 'list_active'));

        // ACTION: Check Tenant Settlement Status (Move-Out Audit)
        if ($action === 'check_settlement') {
            $tId = (int)($_GET['tenant_id'] ?? 0);
            if ($tId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }
            $tStmt = $pdo->prepare("SELECT id, name, unit_id, rental_status, is_active, is_archived FROM users WHERE id = :id AND role = 'tenant' LIMIT 1");
            $tStmt->execute([':id' => $tId]);
            $tenant = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Tenant not found.']);
                exit;
            }
            $settlement = TenantService::checkTenantSettlement($pdo, $tId);
            echo json_encode([
                'status'        => 'success',
                'tenant_id'     => $tId,
                'tenant_name'   => $tenant['name'],
                'rental_status' => $tenant['rental_status'] ?? 'active',
                'is_active'     => (int)$tenant['is_active'],
                'is_archived'   => (int)$tenant['is_archived'],
                'settlement'    => $settlement
            ]);
            exit;
        }

        // ACTION: List Archived Tenants with Search & Financial Summary
        if ($action === 'list_archived') {
            $search = trim((string)($_GET['search'] ?? ''));
            $params = [];
            $whereClause = "u.role = 'tenant' AND (u.is_archived = 1 OR u.deleted_at IS NOT NULL)";

            if (!empty($search)) {
                $whereClause .= " AND (u.name LIKE :s1 OR u.email LIKE :s2 OR u.username LIKE :s3 OR u.archived_unit_number LIKE :s4)";
                $searchWild = "%{$search}%";
                $params[':s1'] = $searchWild;
                $params[':s2'] = $searchWild;
                $params[':s3'] = $searchWild;
                $params[':s4'] = $searchWild;
            }

            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.username, u.email, u.phone, u.birthday, 
                       u.archived_unit_number, u.archived_monthly_rent, u.archived_spaces_text,
                       u.move_in_date, u.deleted_at, u.created_at, u.profile_picture,
                       (SELECT COUNT(*) FROM payments WHERE tenant_id = u.id) AS total_payments,
                       (SELECT COUNT(*) FROM maintenance_tickets WHERE tenant_id = u.id) AS total_tickets,
                       (SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE tenant_id = u.id AND status = 'approved') AS total_paid
                FROM users u
                WHERE {$whereClause}
                ORDER BY u.deleted_at DESC, u.id DESC
            ");
            $stmt->execute($params);
            $archivedTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($archivedTenants as &$at) {
                $at['formatted_deleted_at'] = !empty($at['deleted_at']) ? date('M j, Y g:i A', strtotime($at['deleted_at'])) : 'Archived';
                $at['formatted_move_in'] = !empty($at['move_in_date']) ? date('M j, Y', strtotime($at['move_in_date'])) : 'Not set';
                $at['total_paid_formatted'] = '₱' . number_format((float)$at['total_paid'], 2);
                $at['initial'] = strtoupper(mb_substr(trim(explode(' ', trim($at['name']))[0]), 0, 1, 'UTF-8')) ?: 'T';
            }

            echo json_encode([
                'status' => 'success',
                'count' => count($archivedTenants),
                'archived_tenants' => $archivedTenants
            ]);
            exit;
        }

        // ACTION: Get Archived Tenant Complete Details (Profile, Ledger, Tickets, Acknowledgments)
        if ($action === 'get_archived_tenant_details') {
            $tId = (int)($_GET['tenant_id'] ?? 0);
            if ($tId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }

            // 1. Profile information
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.username, u.email, u.phone, u.birthday,
                       u.emergency_contact, u.emergency_contact_name, u.emergency_contact_relationship, u.emergency_contact_phone,
                       u.archived_unit_number, u.archived_monthly_rent, u.archived_spaces_text,
                       u.move_in_date, u.total_occupants, u.adults_count, u.kids_count,
                       u.id_type, u.id_photo_filename, u.profile_picture,
                       u.verification_status, u.id_submitted_at, u.id_verified_at,
                       u.deleted_at, u.created_at,
                       (SELECT address FROM apartment_settings LIMIT 1) AS property_address,
                       (SELECT security_deposit FROM apartment_settings LIMIT 1) AS standard_deposit
                FROM users u
                WHERE u.id = :id AND u.role = 'tenant'
                LIMIT 1
            ");
            $stmt->execute([':id' => $tId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Archived tenant account not found.']);
                exit;
            }

            $tenant['formatted_deleted_at'] = !empty($tenant['deleted_at']) ? date('M j, Y g:i A', strtotime($tenant['deleted_at'])) : 'Archived';
            $tenant['formatted_move_in'] = !empty($tenant['move_in_date']) ? date('M j, Y', strtotime($tenant['move_in_date'])) : 'Not recorded';
            $tenant['initial'] = strtoupper(mb_substr(trim(explode(' ', trim($tenant['name']))[0]), 0, 1, 'UTF-8')) ?: 'T';

            $propAddress = $tenant['property_address'] ?: '742 Evergreen Boulevard, Portland, OR 97201';
            $unitPrefix = !empty($tenant['archived_unit_number']) ? ($tenant['archived_unit_number'] . ', ') : '';
            $tenant['complete_address'] = $unitPrefix . 'TaraRent Residences, ' . $propAddress;
            $tenant['formatted_birthday'] = !empty($tenant['birthday']) ? date('M j, Y', strtotime($tenant['birthday'])) : 'Not provided';
            $tenant['formatted_created_at'] = !empty($tenant['created_at']) ? date('M j, Y', strtotime($tenant['created_at'])) : 'N/A';
            $depositAmount = (float)($tenant['archived_monthly_rent'] ?: ($tenant['standard_deposit'] ?: 3000.00));
            $tenant['advance_deposit_formatted'] = '₱' . number_format($depositAmount, 2);
            $tenant['archived_monthly_rent_formatted'] = '₱' . number_format((float)($tenant['archived_monthly_rent'] ?: 3000.00), 2) . ' / mo';

            // 2. Payments & Billing History (Paid, Unpaid, Overdue, Pending)
            $pStmt = $pdo->prepare("
                SELECT id, payment_type, billing_cycle, due_date, base_rent_amount, 
                       additional_space_fee, additional_spaces_breakdown,
                       amount_due, amount_paid, payment_date, status, payment_method, 
                       receipt_filename, verified_by_landlord, verification_notes, verified_at, created_at
                FROM payments
                WHERE tenant_id = :id
                ORDER BY billing_cycle DESC, id DESC
            ");
            $pStmt->execute([':id' => $tId]);
            $payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($payments as &$p) {
                $p['billing_cycle_formatted'] = !empty($p['billing_cycle']) ? date('F Y', strtotime($p['billing_cycle'])) : 'Billing Cycle';
                $p['due_date_formatted'] = !empty($p['due_date']) ? date('M j, Y', strtotime($p['due_date'])) : 'N/A';
                $p['payment_date_formatted'] = !empty($p['payment_date']) ? date('M j, Y', strtotime($p['payment_date'])) : 'Pending';
                $p['amount_due_formatted'] = '₱' . number_format((float)$p['amount_due'], 2);
                $p['amount_paid_formatted'] = '₱' . number_format((float)$p['amount_paid'], 2);
            }

            // 3. Maintenance Tickets & Responses
            $tStmt = $pdo->prepare("
                SELECT id, title, description, photo_path, category, priority, status, 
                       actual_cost, resolution_notes, created_at, resolved_at
                FROM maintenance_tickets
                WHERE tenant_id = :id
                ORDER BY created_at DESC, id DESC
            ");
            $tStmt->execute([':id' => $tId]);
            $tickets = $tStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tickets as &$tk) {
                $tk['formatted_date'] = date('M j, Y g:i A', strtotime($tk['created_at']));
                $tk['actual_cost_formatted'] = '₱' . number_format((float)$tk['actual_cost'], 2);
            }

            echo json_encode([
                'status' => 'success',
                'tenant' => $tenant,
                'payments' => $payments,
                'tickets' => $tickets
            ]);
            exit;
        }

        // DEFAULT ACTION: Fetch Active Tenants Grouped by Units
        // Fetch 3 Units
        $unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_number ASC");
        $units = $unitsStmt->fetchAll();

        // Fetch All Active Tenants with personal details & emergency contacts (strictly excluding archived)
        $tenantsStmt = $pdo->query("
            SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
                   u.verification_status, u.id_type, u.id_photo_filename,
                   u.birthday, u.emergency_contact, u.emergency_contact_name,
                   u.emergency_contact_relationship, u.emergency_contact_phone,
                   u.move_in_date, u.total_occupants, u.adults_count, u.kids_count, u.created_at,
                   un.unit_number, un.monthly_rent
            FROM users u
            JOIN units un ON u.unit_id = un.id
            WHERE u.role = 'tenant' 
              AND u.is_active = 1 
              AND (u.is_archived = 0 OR u.is_archived IS NULL)
              AND u.deleted_at IS NULL
            ORDER BY un.unit_number ASC, u.name ASC
        ");
        $allTenants = $tenantsStmt->fetchAll();

        // Group tenants by unit
        $grouped = [];
        foreach ($units as $u) {
            $uId = (int)$u['id'];
            $grouped[$uId] = [
                'unit_id' => $uId,
                'unit_number' => $u['unit_number'],
                'monthly_rent' => (float)$u['monthly_rent'],
                'capacity' => 'Unlimited',
                'tenants' => []
            ];
        }

        foreach ($allTenants as $t) {
            $uId = (int)$t['unit_id'];
            if (isset($grouped[$uId])) {
                $grouped[$uId]['tenants'][] = [
                    'id' => (int)$t['id'],
                    'name' => $t['name'],
                    'username' => $t['username'],
                    'email' => $t['email'],
                    'phone' => $t['phone'] ?: 'No phone provided',
                    'verification_status' => $t['verification_status'] ?? 'unverified',
                    'id_type' => $t['id_type'] ?: null,
                    'id_photo_filename' => $t['id_photo_filename'] ?: null,
                    'birthday' => $t['birthday'] ?: null,
                    'emergency_contact_name' => $t['emergency_contact_name'] ?: ($t['emergency_contact'] ?: 'None listed'),
                    'emergency_contact_relationship' => $t['emergency_contact_relationship'] ?: '',
                    'emergency_contact_phone' => $t['emergency_contact_phone'] ?: '',
                    'move_in_date' => $t['move_in_date'] ?: date('Y-m-d', strtotime($t['created_at'])),
                    'total_occupants' => (int)($t['total_occupants'] ?? 1),
                    'adults_count' => (int)($t['adults_count'] ?? 1),
                    'kids_count' => (int)($t['kids_count'] ?? 0)
                ];
            }
        }

        $occupiedUnitIds = array_keys(array_filter($grouped, fn($u) => !empty($u['tenants'])));

        // Fetch Additional Rental Spaces (Space 1 & Space 2)
        $spacesStmt = $pdo->query("SELECT id, space_number, space_name, monthly_fee, status, assigned_tenant_id FROM additional_spaces ORDER BY space_number ASC");
        $additionalSpacesList = $spacesStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'total_tenants' => count($allTenants),
            'occupied_unit_ids' => $occupiedUnitIds,
            'units' => array_values($grouped),
            'additional_spaces' => $additionalSpacesList
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. POST: Create or Delete Tenant Accounts
    // -------------------------------------------------------------------------
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim((string)($input['action'] ?? 'create'));

        // DELETE / MOVE-OUT ACTION (Automatic Archival of Fully-Settled Moved-Out Tenants)
        if ($action === 'delete' || $action === 'mark_moved_out' || $action === 'archive') {
            $tenantId = (int)($input['tenant_id'] ?? 0);

            if ($tenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }

            // Security: Prevent accidental deletion of currently logged-in Owner/Admin account
            $currentUserId = (int)(Auth::user()['id'] ?? 0);
            if ($tenantId === $currentUserId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete the logged-in administrator account.']);
                exit;
            }

            // Require fresh Owner/Admin account password verification on backend before deletion
            $ownerPassword = (string)($input['owner_password'] ?? '');
            $pwCheck = AccessControl::verifyOwnerPassword($pdo, $ownerPassword);
            if (!$pwCheck['valid']) {
                http_response_code((int)$pwCheck['status_code']);
                echo json_encode(['status' => 'error', 'message' => $pwCheck['message']]);
                exit;
            }

            // Confirm the targeted account is specifically a tenant before proceeding
            $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'tenant' LIMIT 1");
            $targetStmt->execute([':id' => $tenantId]);
            if (!$targetStmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Target tenant account was not found.']);
                exit;
            }

            $result = TenantService::markMovedOut($pdo, $tenantId);

            if ($result['status'] === 'error') {
                http_response_code(404);
                echo json_encode($result);
                exit;
            }

            echo json_encode($result);
            exit;
        }

        // RESTORE TENANT ACCOUNT ACTION (Restores archived tenant to active list)
        if ($action === 'restore') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Fetch target archived tenant
                $check = $pdo->prepare("
                    SELECT id, name, email, archived_unit_number, archived_monthly_rent, is_archived, deleted_at 
                    FROM users 
                    WHERE id = :id AND role = 'tenant'
                    FOR UPDATE
                ");
                $check->execute([':id' => $tenantId]);
                $target = $check->fetch(PDO::FETCH_ASSOC);

                if (!$target || ((int)($target['is_archived'] ?? 0) !== 1 && empty($target['deleted_at']))) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'This account is not archived or is already active.']);
                    exit;
                }

                // Resolve target unit from archived_unit_number or default to available unit
                $targetUnitNum = trim((string)($target['archived_unit_number'] ?? ''));
                $targetUnit = null;
                if (!empty($targetUnitNum)) {
                    $uStmt = $pdo->prepare("SELECT id, unit_number, monthly_rent FROM units WHERE unit_number = :unum LIMIT 1");
                    $uStmt->execute([':unum' => $targetUnitNum]);
                    $targetUnit = $uStmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$targetUnit) {
                    // Try to find any available unit
                    $availStmt = $pdo->query("
                        SELECT u.id, u.unit_number, u.monthly_rent 
                        FROM units u 
                        WHERE u.id NOT IN (
                            SELECT unit_id FROM users WHERE role = 'tenant' AND is_active = 1 AND unit_id IS NOT NULL
                        )
                        ORDER BY u.id ASC 
                        LIMIT 1
                    ");
                    $targetUnit = $availStmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$targetUnit) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Cannot restore account: All units are currently occupied. Please make a unit available first.'
                    ]);
                    exit;
                }

                $targetUnitId = (int)$targetUnit['id'];

                // Check that the tenant's assigned unit is available before restoring
                // Prevent duplicate active accounts for the same unit
                $chkOcc = $pdo->prepare("
                    SELECT id, name FROM users 
                    WHERE unit_id = :uid AND role = 'tenant' AND is_active = 1 AND id != :tid 
                    LIMIT 1 
                    FOR UPDATE
                ");
                $chkOcc->execute([':uid' => $targetUnitId, ':tid' => $tenantId]);
                $occupant = $chkOcc->fetch(PDO::FETCH_ASSOC);

                if ($occupant) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Cannot restore account: {$targetUnit['unit_number']} is currently occupied by active tenant '{$occupant['name']}'. Only one active tenant is allowed per unit."
                    ]);
                    exit;
                }

                // Restore account: set is_active = 1, is_archived = 0, deleted_at = NULL, unit_id = $targetUnitId
                $upd = $pdo->prepare("
                    UPDATE users 
                    SET is_active = 1,
                        is_archived = 0,
                        deleted_at = NULL,
                        unit_id = :uid,
                        active_tenant_unit_id = :uid,
                        active_tenant_email = email,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $upd->execute([
                    ':uid' => $targetUnitId,
                    ':id' => $tenantId
                ]);

                // Update apartment_settings if applicable
                $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = :id WHERE id = 1 AND (active_tenant_id IS NULL OR active_tenant_id = :id2)")
                    ->execute([':id' => $tenantId, ':id2' => $tenantId]);

                $pdo->commit();

                echo json_encode([
                    'status' => 'success',
                    'message' => "Tenant account '{$target['name']}' has been successfully restored to {$targetUnit['unit_number']}.",
                    'unit_id' => $targetUnitId,
                    'tenant_id' => $tenantId
                ]);
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Restoration failed: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        // PERMANENT DELETE ACTION (Permanent Removal with Explicit Owner Password Confirmation)
        if ($action === 'permanent_delete') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }

            $currentUserId = (int)(Auth::user()['id'] ?? 0);
            if ($tenantId === $currentUserId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete the logged-in administrator account.']);
                exit;
            }

            $ownerPassword = (string)($input['owner_password'] ?? '');
            $pwCheck = AccessControl::verifyOwnerPassword($pdo, $ownerPassword);
            if (!$pwCheck['valid']) {
                http_response_code((int)$pwCheck['status_code']);
                echo json_encode(['status' => 'error', 'message' => $pwCheck['message']]);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $check = $pdo->prepare("
                    SELECT id, name, role, is_archived, deleted_at 
                    FROM users 
                    WHERE id = :id AND role = 'tenant'
                    FOR UPDATE
                ");
                $check->execute([':id' => $tenantId]);
                $target = $check->fetch(PDO::FETCH_ASSOC);

                if (!$target || ((int)($target['is_archived'] ?? 0) !== 1 && empty($target['deleted_at']))) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Only archived tenant accounts can be permanently deleted.']);
                    exit;
                }

                // Delete dependent non-financial items and release any space reference
                $pdo->prepare("UPDATE additional_spaces SET status = 'available', assigned_tenant_id = NULL, assigned_unit_id = NULL, rented_at = NULL WHERE assigned_tenant_id = :id")->execute([':id' => $tenantId]);
                $pdo->prepare("DELETE FROM notifications WHERE user_id = :id")->execute([':id' => $tenantId]);
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id")->execute([':id' => $tenantId]);
                $pdo->prepare("DELETE FROM maintenance_tickets WHERE tenant_id = :id")->execute([':id' => $tenantId]);
                $pdo->prepare("DELETE FROM payments WHERE tenant_id = :id")->execute([':id' => $tenantId]);
                $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'tenant'")->execute([':id' => $tenantId]);

                $pdo->commit();

                echo json_encode([
                    'status' => 'success',
                    'message' => "Archived tenant account '{$target['name']}' and associated records have been permanently deleted."
                ]);
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Permanent deletion failed: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        // VERIFY TENANT ID ACTION (Landlord Approves or Rejects Tenant ID)
        if ($action === 'verify_tenant_id') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            $decision = trim((string)($input['decision'] ?? 'verified')); // 'verified' or 'rejected'

            if (!in_array($decision, ['verified', 'rejected'], true)) {
                $decision = 'verified';
            }

            $isVerifiedInt = ($decision === 'verified') ? 1 : 0;
            $update = $pdo->prepare("
                UPDATE users 
                SET verification_status = :status,
                    is_verified = :is_verified,
                    id_verified_at = " . ($decision === 'verified' ? 'NOW()' : 'NULL') . "
                WHERE id = :id AND role = 'tenant'
            ");
            $update->execute([
                ':status' => $decision,
                ':is_verified' => $isVerifiedInt,
                ':id' => $tenantId
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => "Tenant verification status updated to '{$decision}'."
            ]);
            exit;
        }

        // TRANSFER OR EDIT TENANT UNIT ACTION
        if ($action === 'transfer' || $action === 'update_unit') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            $targetUnitId = (int)($input['unit_id'] ?? 0);

            if ($tenantId <= 0 || $targetUnitId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Tenant ID and target Unit ID are required.']);
                exit;
            }

            $pdo->beginTransaction();

            // Verify target unit exists
            $uStmt = $pdo->prepare("SELECT id, unit_number, monthly_rent FROM units WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $targetUnitId]);
            $targetUnit = $uStmt->fetch();
            if (!$targetUnit) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Selected target unit does not exist.']);
                exit;
            }

            // Verify tenant exists
            $tStmt = $pdo->prepare("SELECT id, name, unit_id, role, is_active FROM users WHERE id = :id AND role = 'tenant' LIMIT 1");
            $tStmt->execute([':id' => $tenantId]);
            $targetTenant = $tStmt->fetch();
            if (!$targetTenant) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Tenant not found.']);
                exit;
            }

            // Concurrency-safe check: Is target unit occupied by another active tenant?
            $chkTarget = $pdo->prepare("
                SELECT id, name FROM users 
                WHERE unit_id = :uid AND role = 'tenant' AND is_active = 1 AND id != :tid 
                LIMIT 1 
                FOR UPDATE
            ");
            $chkTarget->execute([':uid' => $targetUnitId, ':tid' => $tenantId]);
            if ($chkTarget->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
                ]);
                exit;
            }

            // Perform transfer
            try {
                $upd = $pdo->prepare("UPDATE users SET unit_id = :uid WHERE id = :id");
                $upd->execute([':uid' => $targetUnitId, ':id' => $tenantId]);

                // Update pending payments to new unit if appropriate
                $updPay = $pdo->prepare("UPDATE payments SET unit_id = :uid WHERE tenant_id = :tid AND status = 'pending'");
                $updPay->execute([':uid' => $targetUnitId, ':tid' => $tenantId]);

                $pdo->commit();

                // Send notification
                NotificationService::notifyTenant(
                    $pdo,
                    $tenantId,
                    'account',
                    'Unit Transfer Completed',
                    "You have been successfully transferred to {$targetUnit['unit_number']}.",
                    'tenantProfile',
                    $tenantId,
                    'tenant_transferred'
                );

                echo json_encode([
                    'status' => 'success',
                    'message' => "Tenant '{$targetTenant['name']}' transferred to {$targetUnit['unit_number']} successfully.",
                    'unit_id' => $targetUnitId
                ]);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (strpos($e->getMessage(), 'already occupied') !== false || strpos($e->getMessage(), 'uq_active_tenant_unit') !== false || $e->getCode() === '45000') {
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
                    ]);
                    exit;
                }
                throw $e;
            }
        }

        // EDIT / UPDATE TENANT PERSONAL DETAILS
        if ($action === 'update' || $action === 'edit_tenant') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Tenant ID is required.']);
                exit;
            }
            $tName = trim((string)($input['name'] ?? ''));
            $tEmail = trim((string)($input['email'] ?? ''));
            $tPhone = trim((string)($input['phone'] ?? ''));
            $tBirthday = trim((string)($input['birthday'] ?? ''));
            $tMoveIn = trim((string)($input['move_in_date'] ?? ''));
            $tEmergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
            $tEmergencyRel = trim((string)($input['emergency_contact_relationship'] ?? ''));
            $tEmergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));
            $tAdults = (int)($input['adults_count'] ?? 1);
            $tKids = (int)($input['kids_count'] ?? 0);
            if ($tAdults < 1) $tAdults = 1;
            if ($tKids < 0) $tKids = 0;
            $tTotal = $tAdults + $tKids;

            // Validate moving-in date if updated
            if (!empty($tMoveIn)) {
                date_default_timezone_set('Asia/Manila');
                $manilaTodayStr = (new DateTime('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
                $moveInObj = DateTime::createFromFormat('Y-m-d', $tMoveIn, new DateTimeZone('Asia/Manila'));
                if (!$moveInObj || $moveInObj->format('Y-m-d') !== $tMoveIn || $tMoveIn < $manilaTodayStr) {
                    http_response_code(422);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Invalid moving-in date. Please select today or a future date.'
                    ]);
                    exit;
                }
            }

            // Check email uniqueness strictly against OTHER active tenant accounts
            if (!empty($tEmail)) {
                if (!filter_var($tEmail, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(422);
                    echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address.']);
                    exit;
                }
                $dupCheck = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE email = :e 
                      AND id != :id 
                      AND role = 'tenant' 
                      AND is_active = 1 
                      AND (is_archived = 0 OR is_archived IS NULL)
                    LIMIT 1
                ");
                $dupCheck->execute([':e' => $tEmail, ':id' => $tenantId]);
                if ($dupCheck->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'This email address is already associated with an active tenant account.'
                    ]);
                    exit;
                }
            }

            $upd = $pdo->prepare("
                UPDATE users 
                SET name = COALESCE(NULLIF(:name, ''), name),
                    email = COALESCE(NULLIF(:email, ''), email),
                    phone = COALESCE(NULLIF(:phone, ''), phone),
                    birthday = NULLIF(:birthday, ''),
                    move_in_date = COALESCE(NULLIF(:move_in, ''), move_in_date),
                    emergency_contact_name = NULLIF(:em_name, ''),
                    emergency_contact_relationship = NULLIF(:em_rel, ''),
                    emergency_contact_phone = NULLIF(:em_phone, ''),
                    adults_count = :adults,
                    kids_count = :kids,
                    total_occupants = :total
                WHERE id = :id AND role = 'tenant'
            ");
            $upd->execute([
                ':name' => $tName,
                ':email' => $tEmail,
                ':phone' => $tPhone,
                ':birthday' => $tBirthday,
                ':move_in' => $tMoveIn,
                ':em_name' => $tEmergencyName,
                ':em_rel' => $tEmergencyRel,
                ':em_phone' => $tEmergencyPhone,
                ':adults' => $tAdults,
                ':kids' => $tKids,
                ':total' => $tTotal,
                ':id' => $tenantId
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Tenant details updated successfully.'
            ]);
            exit;
        }

        // CREATE ACTION (Provision New Tenant with Auto-Generated Credentials)
        if ($action === 'create') {
            // Anti-duplicate protection: prevent repeated rapid submissions within 2 seconds
            if (session_status() === PHP_SESSION_ACTIVE) {
                $now = microtime(true);
                $lastCreateTime = (float)($_SESSION['last_tenant_create_time'] ?? 0);
                if (($now - $lastCreateTime) < 2.0) {
                    http_response_code(429);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Tenant creation is already in progress. Please wait a moment.'
                    ]);
                    exit;
                }
                $_SESSION['last_tenant_create_time'] = $now;
            }

            $name = trim((string)($input['name'] ?? ''));
            $birthday = trim((string)($input['birthday'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $unitId = (int)($input['unit_id'] ?? 1);
            $emergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
            $emergencyRel = trim((string)($input['emergency_contact_relationship'] ?? ''));
            $emergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));
            $moveInDate = trim((string)($input['move_in_date'] ?? ''));
            $adultsCount = (int)($input['adults_count'] ?? 1);
            $kidsCount = (int)($input['kids_count'] ?? 0);
            if ($adultsCount < 1) $adultsCount = 1;
            if ($kidsCount < 0) $kidsCount = 0;
            $totalOccupants = $adultsCount + $kidsCount;

            if (empty($name) || empty($email) || empty($moveInDate)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Full Name, Email Address, and Date of Moving In are required fields.'
                ]);
                exit;
            }

            // Server-Side Validation: Date of Moving In must be today or a future date in Asia/Manila timezone
            date_default_timezone_set('Asia/Manila');
            $manilaTodayStr = (new DateTime('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $moveInObj = DateTime::createFromFormat('Y-m-d', $moveInDate, new DateTimeZone('Asia/Manila'));
            if (!$moveInObj || $moveInObj->format('Y-m-d') !== $moveInDate || $moveInDate < $manilaTodayStr) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid moving-in date. Please select today or a future date.'
                ]);
                exit;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please provide a valid email address.'
                ]);
                exit;
            }

            // Check if unit exists & fetch hardcoded configuration
            $unitCheck = $pdo->prepare("SELECT id, unit_number, bedrooms, monthly_rent FROM units WHERE id = :id LIMIT 1");
            $unitCheck->execute([':id' => $unitId]);
            $unit = $unitCheck->fetch();

            if (!$unit) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Selected unit does not exist.']);
                exit;
            }

            // Concurrency Lock & Validation: Only ONE active tenant account per apartment unit
            $pdo->beginTransaction();
            $unitOccupancyStmt = $pdo->prepare("
                SELECT id, name FROM users 
                WHERE unit_id = :unit_id AND role = 'tenant' AND is_active = 1 
                LIMIT 1 
                FOR UPDATE
            ");
            $unitOccupancyStmt->execute([':unit_id' => $unitId]);
            $occupiedTenant = $unitOccupancyStmt->fetch();

            if ($occupiedTenant) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
                ]);
                exit;
            }

            // Check uniqueness of email strictly against active tenant accounts
            // Inactive, archived, or deleted tenant accounts do not block email reuse
            $dupStmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE email = :e 
                  AND role = 'tenant' 
                  AND is_active = 1 
                  AND (is_archived = 0 OR is_archived IS NULL)
                LIMIT 1 
                FOR UPDATE
            ");
            $dupStmt->execute([':e' => $email]);
            if ($dupStmt->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'This email address is already associated with an active tenant account.'
                ]);
                exit;
            }

            // Also prevent using an active administrator/landlord email address
            $adminCheckStmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE email = :e 
                  AND role = 'landlord' 
                  AND is_active = 1
                LIMIT 1 
                FOR UPDATE
            ");
            $adminCheckStmt->execute([':e' => $email]);
            if ($adminCheckStmt->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'This email address is already associated with an active administrator account.'
                ]);
                exit;
            }

            // 1. Auto-generate sequential username: RC001, RC002, RC003, ...
            $existingUsernamesStmt = $pdo->query("SELECT username FROM users WHERE username REGEXP '^RC[0-9]+$'");
            $existingUsernames = $existingUsernamesStmt->fetchAll(PDO::FETCH_COLUMN);

            $maxNum = 0;
            foreach ($existingUsernames as $uName) {
                $numPart = (int)substr($uName, 2);
                if ($numPart > $maxNum) {
                    $maxNum = $numPart;
                }
            }

            $nextNum = $maxNum + 1;
            $generatedUsername = sprintf('RC%03d', $nextNum);

            // Guard against any collision
            while (true) {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $checkStmt->execute([':u' => $generatedUsername]);
                if (!$checkStmt->fetch()) {
                    break;
                }
                $nextNum++;
                $generatedUsername = sprintf('RC%03d', $nextNum);
            }

            // 2. Auto-generate 6-digit numeric password (e.g. 111111, 482915)
            $generatedPassword = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $passwordHash = password_hash($generatedPassword, PASSWORD_BCRYPT);

            // Legacy emergency contact string fallback
            $legacyEmergencyParts = array_filter([$emergencyName, $emergencyRel ? "($emergencyRel)" : '', $emergencyPhone]);
            $legacyEmergency = implode(' ', $legacyEmergencyParts);

            $insertStmt = $pdo->prepare("
                INSERT INTO users (
                    unit_id, username, name, email, password_hash, role, verification_status, phone, 
                    birthday, emergency_contact, emergency_contact_name, 
                    emergency_contact_relationship, emergency_contact_phone, 
                    move_in_date, total_occupants, adults_count, kids_count, is_active, created_at
                ) VALUES (
                    :unit_id, :username, :name, :email, :password_hash, 'tenant', 'unverified', :phone,
                    :birthday, :emergency_contact, :emergency_contact_name,
                    :emergency_contact_relationship, :emergency_contact_phone,
                    :move_in_date, :total_occupants, :adults_count, :kids_count, 1, NOW()
                )
            ");
            $insertStmt->execute([
                ':unit_id' => $unitId,
                ':username' => $generatedUsername,
                ':name' => $name,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':phone' => $phone ?: null,
                ':birthday' => !empty($birthday) ? $birthday : null,
                ':emergency_contact' => $legacyEmergency ?: null,
                ':emergency_contact_name' => $emergencyName ?: null,
                ':emergency_contact_relationship' => $emergencyRel ?: null,
                ':emergency_contact_phone' => $emergencyPhone ?: null,
                ':move_in_date' => $moveInDate,
                ':total_occupants' => $totalOccupants,
                ':adults_count' => $adultsCount,
                ':kids_count' => $kidsCount
            ]);

            $newId = (int)$pdo->lastInsertId();

            // Auto-generate notifications for Landlord and new Tenant (in-app only, NO secondary emails)
            NotificationService::notifyLandlords(
                $pdo,
                'account',
                'New Tenant Assigned',
                "A new tenant ({$name}) has been assigned to {$unit['unit_number']}.",
                'sectionDirectory',
                $newId,
                'tenant_registered',
                false
            );
            NotificationService::notifyTenant(
                $pdo,
                $newId,
                'account',
                'Welcome to TaraRent',
                "Welcome to TaraRent! You have been assigned to {$unit['unit_number']}.",
                'tenantProfile',
                $newId,
                'tenant_welcome',
                false
            );

            // -----------------------------------------------------------------
            // AUTOMATIC BILL GENERATION UPON TENANT ACCOUNT CREATION
            // Generate two separate payment obligations:
            // 1. Advance Deposit: ₱3,000.00 (One-Time, Unpaid)
            // 2. Monthly Rent:    ₱3,000.00 (Recurring, Unpaid)
            // Total Initial Due:  ₱6,000.00
            // -----------------------------------------------------------------
            $moveInCycle = date('Y-m-01', strtotime($moveInDate));
            $rentDueDate = date('Y-m-05', strtotime($moveInCycle));
            $standardRentAmount = !empty($unit['monthly_rent']) ? (float)$unit['monthly_rent'] : 3000.00;
            if ($standardRentAmount <= 0) {
                $standardRentAmount = 3000.00;
            }

            // 1. Advance Deposit Bill (₱3,000.00, Unpaid, Non-recurring)
            $dupDepositStmt = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = :tid AND payment_type = 'Advance Deposit' LIMIT 1");
            $dupDepositStmt->execute([':tid' => $newId]);
            $existingDeposit = $dupDepositStmt->fetch();

            if (!$existingDeposit) {
                $depositStmt = $pdo->prepare("
                    INSERT INTO payments (
                        tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, 
                        payment_date, status, payment_method, receipt_filename, verified_by_landlord, 
                        verification_notes, created_at
                    ) VALUES (
                        :tenant_id, :unit_id, 'Advance Deposit', :cycle, :due_date, 3000.00, 0.00,
                        NULL, 'unpaid', 'GCash / Bank Transfer', NULL, 0,
                        'Advance deposit required upon account creation', NOW()
                    )
                ");
                $depositStmt->execute([
                    ':tenant_id' => $newId,
                    ':unit_id' => $unitId,
                    ':cycle' => $moveInCycle,
                    ':due_date' => $moveInDate
                ]);
                $advanceDepositId = (int)$pdo->lastInsertId();
            } else {
                $advanceDepositId = (int)$existingDeposit['id'];
            }

            // -----------------------------------------------------------------
            // PROCESS ADDITIONAL RENTAL SPACE SELECTIONS (Optional)
            // Space 1 & Space 2 (₱1,500.00/month each)
            // -----------------------------------------------------------------
            $selectedSpaceIds = $input['additional_space_ids'] ?? $input['additional_spaces'] ?? [];
            if (!is_array($selectedSpaceIds)) {
                $selectedSpaceIds = [];
            }
            $selectedSpaceIds = array_values(array_unique(array_filter(array_map('intval', $selectedSpaceIds), fn($id) => $id > 0)));

            $totalExtraSpaceFee = 0.00;
            $spaceBreakdownParts = [];

            if (!empty($selectedSpaceIds)) {
                foreach ($selectedSpaceIds as $sId) {
                    // Lock and validate space availability
                    $spaceStmt = $pdo->prepare("SELECT id, space_number, space_name, monthly_fee, status FROM additional_spaces WHERE id = :id FOR UPDATE");
                    $spaceStmt->execute([':id' => $sId]);
                    $spaceRow = $spaceStmt->fetch();

                    if (!$spaceRow) {
                        $pdo->rollBack();
                        http_response_code(422);
                        echo json_encode([
                            'status' => 'error',
                            'message' => "Selected rental space #{$sId} does not exist."
                        ]);
                        exit;
                    }

                    if ($spaceRow['status'] !== 'available') {
                        $pdo->rollBack();
                        http_response_code(409);
                        echo json_encode([
                            'status' => 'error',
                            'message' => "{$spaceRow['space_name']} is already occupied by another tenant and cannot be assigned."
                        ]);
                        exit;
                    }

                    $spaceFee = (float)$spaceRow['monthly_fee'];
                    $totalExtraSpaceFee += $spaceFee;
                    $spaceBreakdownParts[] = "{$spaceRow['space_name']} (₱" . number_format($spaceFee, 2) . "/mo)";

                    // Assign space to the new tenant
                    $assignSpaceStmt = $pdo->prepare("
                        UPDATE additional_spaces
                        SET status = 'occupied',
                            assigned_tenant_id = :tid,
                            assigned_unit_id = :uid,
                            rented_at = NOW()
                        WHERE id = :id
                    ");
                    $assignSpaceStmt->execute([
                        ':tid' => $newId,
                        ':uid' => $unitId,
                        ':id' => $sId
                    ]);
                }
            }

            $spaceBreakdownText = !empty($spaceBreakdownParts) ? implode(' + ', $spaceBreakdownParts) : null;
            $totalRentDue = $standardRentAmount + $totalExtraSpaceFee;

            // 2. Monthly Rent Bill (₱3,000.00 base + additional space fees, Recurring)
            $dupRentStmt = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = :tid AND payment_type = 'Rent' AND (billing_cycle = :cycle OR status IN ('unpaid', 'pending_setup')) LIMIT 1");
            $dupRentStmt->execute([':tid' => $newId, ':cycle' => $moveInCycle]);
            $existingRent = $dupRentStmt->fetch();

            if (!$existingRent) {
                $rentNotes = "Monthly rent for " . date('F Y', strtotime($moveInCycle)) . " - Due upon occupancy.";
                if ($totalExtraSpaceFee > 0) {
                    $rentNotes .= " Includes additional space rental: {$spaceBreakdownText}.";
                }
                $rentStmt = $pdo->prepare("
                    INSERT INTO payments (
                        tenant_id, unit_id, payment_type, billing_cycle, due_date, base_rent_amount, additional_space_fee, additional_spaces_breakdown, amount_due, amount_paid, 
                        payment_date, status, payment_method, receipt_filename, verified_by_landlord, 
                        verification_notes, created_at
                    ) VALUES (
                        :tenant_id, :unit_id, 'Rent', :cycle, :due_date, :base_rent, :extra_fee, :breakdown, :amount_due, 0.00,
                        NULL, 'unpaid', 'GCash / Bank Transfer', NULL, 0,
                        :vnotes, NOW()
                    )
                ");
                $rentStmt->execute([
                    ':tenant_id' => $newId,
                    ':unit_id' => $unitId,
                    ':cycle' => $moveInCycle,
                    ':due_date' => $rentDueDate,
                    ':base_rent' => $standardRentAmount,
                    ':extra_fee' => $totalExtraSpaceFee,
                    ':breakdown' => $spaceBreakdownText,
                    ':amount_due' => $totalRentDue,
                    ':vnotes' => $rentNotes
                ]);
                $monthlyRentId = (int)$pdo->lastInsertId();
            } else {
                $monthlyRentId = (int)$existingRent['id'];
            }

            // Update apartment settings active tenant tracking
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = :tid, updated_at = NOW() WHERE id = 1")
                ->execute([':tid' => $newId]);

            // Commit transaction
            $pdo->commit();

            // Automatically send exactly ONE email to the Tenant with account details (username & temporary password)
            // CRITICAL: Dynamically query the registered email address directly from the MySQL database record
            // Do not send this email to the Owner/Admin or any other tenant.
            $emailSent = false;
            try {
                $dbTenantStmt = $pdo->prepare("
                    SELECT u.id, u.username, u.name, u.email, un.unit_number 
                    FROM users u 
                    LEFT JOIN units un ON u.unit_id = un.id 
                    WHERE u.id = :id AND u.role = 'tenant' 
                    LIMIT 1
                ");
                $dbTenantStmt->execute([':id' => $newId]);
                $dbTenant = $dbTenantStmt->fetch(PDO::FETCH_ASSOC);

                if ($dbTenant && !empty($dbTenant['email']) && filter_var($dbTenant['email'], FILTER_VALIDATE_EMAIL)) {
                    $retrievedEmail    = (string)$dbTenant['email'];
                    $retrievedName     = (string)$dbTenant['name'];
                    $retrievedUsername = (string)($dbTenant['username'] ?: $generatedUsername);
                    $retrievedUnit     = (string)($dbTenant['unit_number'] ?: $unit['unit_number']);

                    $emailSent = EmailService::sendTenantAccountCreated(
                        $pdo,
                        $retrievedEmail,
                        $retrievedName,
                        $retrievedUsername,
                        $generatedPassword,
                        $retrievedUnit,
                        $newId,
                        $newId
                    );
                } else {
                    EmailService::logEmail(
                        $pdo,
                        (string)($dbTenant['email'] ?? $email),
                        (string)($dbTenant['name'] ?? $name),
                        'Your Apartment Management System Account Has Been Created',
                        'tenant_account_created',
                        $newId,
                        'tenant',
                        'failed',
                        'Invalid or missing tenant email retrieved from database.',
                        $newId
                    );
                }
            } catch (Throwable $mailEx) {
                error_log('[Tenant Directory] Failed to dispatch account creation email: ' . $mailEx->getMessage());
            }

            $successMessage = $emailSent
                ? "Tenant account created successfully! Account details have been sent to the tenant's email address."
                : "Tenant account created, but account details email could not be sent. Please check email logs or SMTP settings.";

            echo json_encode([
                'status' => 'success',
                'message' => $successMessage,
                'tenant_id' => $newId,
                'advance_deposit_id' => $advanceDepositId,
                'rent_payment_id' => $monthlyRentId,
                'placeholder_payment_id' => $monthlyRentId,
                'email_sent' => $emailSent,
                'credentials' => [
                    'username' => $generatedUsername,
                    'password' => $generatedPassword,
                    'name' => $name,
                    'email' => $email,
                    'unit_number' => $unit['unit_number']
                ]
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (strpos($e->getMessage(), 'already occupied') !== false || strpos($e->getMessage(), 'uq_active_tenant_unit') !== false || (string)$e->getCode() === '45000') {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
        ]);
        exit;
    }
    if (strpos($e->getMessage(), 'uq_active_tenant_email') !== false) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'This email address is already associated with an active tenant account.'
        ]);
        exit;
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tenant Directory API error: ' . $e->getMessage()
    ]);
}
