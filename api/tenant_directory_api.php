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

        // DELETE ACTION (Tenant Moved Out / Account Deletion)
        if ($action === 'delete') {
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

            $pdo->beginTransaction();

            // Verify target user is an existing active tenant
            $check = $pdo->prepare("SELECT id, name, role, unit_id, is_active FROM users WHERE id = :id FOR UPDATE");
            $check->execute([':id' => $tenantId]);
            $target = $check->fetch();

            if (!$target || $target['role'] !== 'tenant' || (int)$target['is_active'] !== 1) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Tenant account not found or has already been deleted.']);
                exit;
            }

            $unitId = (int)($target['unit_id'] ?? 0);

            // 1. Clean up transient user records
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id")->execute([':id' => $tenantId]);
            $pdo->prepare("DELETE FROM notifications WHERE user_id = :id")->execute([':id' => $tenantId]);
            $pdo->prepare("DELETE FROM announcement_recipients WHERE tenant_id = :id")->execute([':id' => $tenantId]);
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = NULL WHERE active_tenant_id = :id")->execute([':id' => $tenantId]);

            // 2. Remove unconfigured placeholder payments (rent records created upon tenant registration that were never configured)
            $pdo->prepare("DELETE FROM payments WHERE tenant_id = :id AND status = 'pending_setup'")->execute([':id' => $tenantId]);

            // 3. Inspect audit trail: check for historical payments or maintenance tickets that must remain available for administrative audit
            $auditStmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM payments WHERE tenant_id = :id) AS pay_count,
                    (SELECT COUNT(*) FROM maintenance_tickets WHERE tenant_id = :id2) AS ticket_count
            ");
            $auditStmt->execute([':id' => $tenantId, ':id2' => $tenantId]);
            $auditCounts = $auditStmt->fetch();
            $hasAuditRecords = ((int)$auditCounts['pay_count'] > 0 || (int)$auditCounts['ticket_count'] > 0);

            if ($hasAuditRecords) {
                // Soft-delete / deactivate tenant account:
                // Scrambles credentials, frees unit, retains ledger records for landlord audit
                $upd = $pdo->prepare("
                    UPDATE users 
                    SET is_active = 0,
                        unit_id = NULL,
                        email = CONCAT(email, '.deleted.', UNIX_TIMESTAMP()),
                        password_hash = 'DEACTIVATED_ACCOUNT',
                        verification_status = 'unverified',
                        id_photo_filename = NULL,
                        live_photo_filename = NULL,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $upd->execute([':id' => $tenantId]);
            } else {
                // Safe to physically delete if no financial or maintenance audit records exist
                $del = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $del->execute([':id' => $tenantId]);
            }

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'unit_id' => $unitId,
                'tenant_id' => $tenantId,
                'message' => "Tenant account for '{$target['name']}' has been deleted successfully."
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
                'Welcome to RentConnect',
                "Welcome to RentConnect! You have been assigned to {$unit['unit_number']}.",
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

            // 2. Monthly Rent Bill (₱3,000.00, Unpaid, Recurring)
            $dupRentStmt = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = :tid AND payment_type = 'Rent' AND (billing_cycle = :cycle OR status IN ('unpaid', 'pending_setup')) LIMIT 1");
            $dupRentStmt->execute([':tid' => $newId, ':cycle' => $moveInCycle]);
            $existingRent = $dupRentStmt->fetch();

            if (!$existingRent) {
                $rentStmt = $pdo->prepare("
                    INSERT INTO payments (
                        tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, 
                        payment_date, status, payment_method, receipt_filename, verified_by_landlord, 
                        verification_notes, created_at
                    ) VALUES (
                        :tenant_id, :unit_id, 'Rent', :cycle, :due_date, :amount_due, 0.00,
                        NULL, 'unpaid', 'GCash / Bank Transfer', NULL, 0,
                        :vnotes, NOW()
                    )
                ");
                $rentStmt->execute([
                    ':tenant_id' => $newId,
                    ':unit_id' => $unitId,
                    ':cycle' => $moveInCycle,
                    ':due_date' => $rentDueDate,
                    ':amount_due' => $standardRentAmount,
                    ':vnotes' => "Monthly rent for " . date('F Y', strtotime($moveInCycle)) . " - Due upon occupancy."
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
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tenant Directory API error: ' . $e->getMessage()
    ]);
}
