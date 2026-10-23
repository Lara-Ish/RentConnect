<?php
/**
 * RentConnect - Single Tenant Provisioning & Lease Management API
 * Authorized solely for Landlord (Admin). No public registration allowed.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Security Guard: Landlord Only
if (!Auth::isLandlord()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Only the property landlord may manage tenant credentials.'
    ]);
    exit;
}

$pdo = Database::getConnection();

try {
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $name = trim((string)($input['name'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $phone = trim((string)($input['phone'] ?? ''));
        $monthlyRent = (float)($input['monthly_rent'] ?? 6000.00);
        $leaseStart = trim((string)($input['lease_start'] ?? date('Y-m-01')));
        $leaseEnd = trim((string)($input['lease_end'] ?? date('Y-m-d', strtotime('+1 year'))));
        $action = trim((string)($input['action'] ?? 'update')); // 'update' or 'replace'

        if (empty($name) || empty($username) || empty($email)) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'Full Name, Username, and Email are required fields.'
            ]);
            exit;
        }

        // Fetch current apartment settings
        $settingsStmt = $pdo->query("SELECT * FROM apartment_settings WHERE id = 1 LIMIT 1");
        $settings = $settingsStmt->fetch();
        $currentTenantId = $settings ? (int)$settings['active_tenant_id'] : null;

        if ($action === 'replace' || !$currentTenantId) {
            // New Tenant Moving In: Require password
            if (empty($password)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'A password is required when provisioning a new tenant account.'
                ]);
                exit;
            }

            // Deactivate old tenant account if replacing
            if ($currentTenantId) {
                $deactivate = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = :id");
                $deactivate->execute([':id' => $currentTenantId]);
            }

            // Create new tenant user
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $insertUser = $pdo->prepare("
                INSERT INTO users (username, name, email, password_hash, role, phone, is_active, created_at)
                VALUES (:u, :n, :e, :p, 'tenant', :ph, 1, NOW())
            ");
            $insertUser->execute([
                ':u' => $username,
                ':n' => $name,
                ':e' => $email,
                ':p' => $passwordHash,
                ':ph' => $phone
            ]);
            $newTenantId = (int)$pdo->lastInsertId();

            // Auto-Generate Placeholder Payment for new tenant
            $insertPayment = $pdo->prepare("
                INSERT INTO payments (
                    tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, 
                    status, payment_method, verified_by_landlord, verification_notes, created_at
                ) VALUES (
                    :tenant_id, 1, :billing_cycle, :due_date, :amount_due, 0.00,
                    'pending', 'GCash / Bank Transfer', 0, 'Placeholder initial rent payment generated upon tenant registration.', NOW()
                )
            ");
            $insertPayment->execute([
                ':tenant_id' => $newTenantId,
                ':billing_cycle' => date('Y-m-01', strtotime($leaseStart ?: date('Y-m-d'))),
                ':due_date' => date('Y-m-05', strtotime($leaseStart ?: date('Y-m-d'))),
                ':amount_due' => (float)$monthlyRent
            ]);

            // Bind to apartment settings
            $updateSettings = $pdo->prepare("
                UPDATE apartment_settings 
                SET active_tenant_id = :tid,
                    monthly_rent = :rent,
                    lease_start = :lstart,
                    lease_end = :lend,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $updateSettings->execute([
                ':tid' => $newTenantId,
                ':rent' => $monthlyRent,
                ':lstart' => $leaseStart,
                ':lend' => $leaseEnd
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => "New tenant '{$name}' provisioned successfully! Previous tenant access revoked.",
                'tenant' => [
                    'id' => $newTenantId,
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'monthly_rent' => $monthlyRent,
                    'lease_start' => $leaseStart,
                    'lease_end' => $leaseEnd
                ]
            ]);
            exit;

        } else {
            // Updating existing active tenant credentials/lease
            $sql = "UPDATE users SET name = :n, username = :u, email = :e, phone = :ph";
            $params = [
                ':n' => $name,
                ':u' => $username,
                ':e' => $email,
                ':ph' => $phone,
                ':id' => $currentTenantId
            ];

            if (!empty($password)) {
                $sql .= ", password_hash = :p";
                $params[':p'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= " WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Update apartment settings lease terms
            $updateSettings = $pdo->prepare("
                UPDATE apartment_settings 
                SET monthly_rent = :rent,
                    lease_start = :lstart,
                    lease_end = :lend,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $updateSettings->execute([
                ':rent' => $monthlyRent,
                ':lstart' => $leaseStart,
                ':lend' => $leaseEnd
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => "Active tenant credentials & lease terms updated successfully.",
                'tenant' => [
                    'id' => $currentTenantId,
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'monthly_rent' => $monthlyRent,
                    'lease_start' => $leaseStart,
                    'lease_end' => $leaseEnd
                ]
            ]);
            exit;
        }
    }

    if ($requestMethod === 'GET') {
        $stmt = $pdo->query("
            SELECT 
                a.apartment_name,
                a.address,
                a.monthly_rent,
                a.security_deposit,
                a.lease_start,
                a.lease_end,
                u.id AS tenant_id,
                u.name AS tenant_name,
                u.username AS tenant_username,
                u.email AS tenant_email,
                u.phone AS tenant_phone,
                u.created_at AS tenant_since
            FROM apartment_settings a
            LEFT JOIN users u ON a.active_tenant_id = u.id
            WHERE a.id = 1
            LIMIT 1
        ");
        $data = $stmt->fetch();

        echo json_encode([
            'status' => 'success',
            'data' => $data
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tenant management operation failed: ' . $e->getMessage()
    ]);
}
