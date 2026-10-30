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
    // 1. GET: Fetch Tenants Grouped by the 3 Units
    // -------------------------------------------------------------------------
    if ($requestMethod === 'GET') {
        // Fetch 3 Units
        $unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_number ASC");
        $units = $unitsStmt->fetchAll();

        // Fetch All Active Tenants with personal details & emergency contacts
        $tenantsStmt = $pdo->query("
            SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
                   u.verification_status, u.id_type, u.id_photo_filename,
                   u.birthday, u.emergency_contact, u.emergency_contact_name,
                   u.emergency_contact_relationship, u.emergency_contact_phone,
                   u.move_in_date, u.total_occupants, u.adults_count, u.kids_count, u.created_at,
                   un.unit_number, un.monthly_rent
            FROM users u
            JOIN units un ON u.unit_id = un.id
            WHERE u.role = 'tenant' AND u.is_active = 1
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

        echo json_encode([
            'status' => 'success',
            'total_tenants' => count($allTenants),
            'occupied_unit_ids' => $occupiedUnitIds,
            'units' => array_values($grouped)
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. POST: Create or Delete Tenant Accounts
    // -------------------------------------------------------------------------
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim((string)($input['action'] ?? 'create'));

        // DELETE ACTION (Tenant Moved Out)
        if ($action === 'delete') {
            $tenantId = (int)($input['tenant_id'] ?? 0);

            if ($tenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid tenant ID.']);
                exit;
            }

            // Verify target user is a tenant (never delete admin)
            $check = $pdo->prepare("SELECT id, name, role, unit_id FROM users WHERE id = :id LIMIT 1");
            $check->execute([':id' => $tenantId]);
            $target = $check->fetch();

            if (!$target || $target['role'] !== 'tenant') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Target user is not a removable tenant.']);
                exit;
            }

            // Delete tenant account
            $delStmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'tenant'");
            $delStmt->execute([':id' => $tenantId]);

            echo json_encode([
                'status' => 'success',
                'unit_id' => (int)($target['unit_id'] ?? 0),
                'message' => "Tenant account for '{$target['name']}' has been removed successfully (Move-out processed)."
            ]);
            exit;
        }

        // VERIFY TENANT ID ACTION (Landlord Approves or Rejects Tenant ID)
        if ($action === 'verify_tenant_id') {
            $tenantId = (int)($input['tenant_id'] ?? 0);
            $decision = trim((string)($input['decision'] ?? 'verified')); // 'verified' or 'rejected'

            if (!in_array($decision, ['verified', 'rejected'], true)) {
                $decision = 'verified';
            }

            $update = $pdo->prepare("
                UPDATE users 
                SET verification_status = :status,
                    id_verified_at = IF(:status = 'verified', NOW(), NULL)
                WHERE id = :id AND role = 'tenant'
            ");
            $update->execute([
                ':status' => $decision,
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

        // CREATE ACTION (Provision New Tenant with Auto-Generated Credentials)
        if ($action === 'create') {
            $name = trim((string)($input['name'] ?? ''));
            $birthday = trim((string)($input['birthday'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $unitId = (int)($input['unit_id'] ?? 1);
            $emergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
            $emergencyRel = trim((string)($input['emergency_contact_relationship'] ?? ''));
            $emergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));
            $moveInDate = trim((string)($input['move_in_date'] ?? date('Y-m-d')));
            $adultsCount = (int)($input['adults_count'] ?? 1);
            $kidsCount = (int)($input['kids_count'] ?? 0);
            if ($adultsCount < 1) $adultsCount = 1;
            if ($kidsCount < 0) $kidsCount = 0;
            $totalOccupants = $adultsCount + $kidsCount;

            if (empty($name) || empty($email)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Full Name and Email Address are required fields.'
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

            // Check uniqueness of email
            $dupStmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
            $dupStmt->execute([':e' => $email]);
            if ($dupStmt->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'A user with this email address already exists.'
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

            // Auto-generate notifications for Landlord and new Tenant
            NotificationService::notifyLandlords(
                $pdo,
                'account',
                'New Tenant Assigned',
                "A new tenant ({$name}) has been assigned to {$unit['unit_number']}.",
                'sectionDirectory',
                $newId,
                'tenant_registered'
            );
            NotificationService::notifyTenant(
                $pdo,
                $newId,
                'account',
                'Welcome to RentConnect',
                "Welcome to RentConnect! You have been assigned to {$unit['unit_number']}.",
                'tenantProfile',
                $newId,
                'tenant_welcome'
            );

            // Auto-Generate Placeholder Payment with Hardcoded Unit Pricing:
            // Unit 1: 1 Bedroom, ₱6,000 | Unit 2: 2 Bedrooms, ₱8,000 | Unit 3: 3 Bedrooms, ₱8,000
            $HARDCODED_UNITS = [
                1 => ['unit_number' => 'Unit 1', 'bedrooms' => 1, 'monthly_rent' => 6000.00],
                2 => ['unit_number' => 'Unit 2', 'bedrooms' => 2, 'monthly_rent' => 8000.00],
                3 => ['unit_number' => 'Unit 3', 'bedrooms' => 3, 'monthly_rent' => 8000.00],
            ];

            $unitIdInt = (int)$unitId;
            $rentAmount = isset($HARDCODED_UNITS[$unitIdInt])
                ? (float)$HARDCODED_UNITS[$unitIdInt]['monthly_rent']
                : (!empty($unit['monthly_rent']) ? (float)$unit['monthly_rent'] : 8000.00);

            $billingCycle = date('Y-m-01', strtotime($moveInDate ?: date('Y-m-d')));
            $dueDate = date('Y-m-05', strtotime($moveInDate ?: date('Y-m-d')));

            $placeholderPaymentStmt = $pdo->prepare("
                INSERT INTO payments (
                    tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, 
                    status, payment_method, verified_by_landlord, verification_notes, created_at
                ) VALUES (
                    :tenant_id, :unit_id, :billing_cycle, :due_date, :amount_due, 0.00,
                    'pending', 'GCash / Bank Transfer', 0, 'Placeholder initial rent payment generated upon tenant registration.', NOW()
                )
            ");
            $placeholderPaymentStmt->execute([
                ':tenant_id' => $newId,
                ':unit_id' => $unitId,
                ':billing_cycle' => $billingCycle,
                ':due_date' => $dueDate,
                ':amount_due' => $rentAmount
            ]);
            $placeholderPaymentId = (int)$pdo->lastInsertId();

            // Update apartment settings monthly rent if this is unit 1 or active tenant
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = :tid, monthly_rent = :rent, updated_at = NOW() WHERE id = 1")
                ->execute([':tid' => $newId, ':rent' => $rentAmount]);

            // Commit transaction
            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Tenant '{$name}' created and assigned to {$unit['unit_number']} successfully!",
                'tenant_id' => $newId,
                'placeholder_payment_id' => $placeholderPaymentId,
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
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tenant Directory API error: ' . $e->getMessage()
    ]);
}
