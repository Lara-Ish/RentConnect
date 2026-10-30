<?php
/**
 * RentConnect - Payment & Receipt Upload API Endpoint
 * Handles tenant receipt uploads, landlord manual payment review (approval/rejection), and ledger audits
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

$uploadDir = __DIR__ . '/../uploads/receipts/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$pdo = Database::getConnection();

try {
    if ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim((string)($input['action'] ?? 'upload_receipt'));

        // ---------------------------------------------------------------------
        // 1. Landlord Manual Review: Approve or Reject Payment Receipt
        // ---------------------------------------------------------------------
        if ($action === 'verify_payment' || $action === 'review_payment') {
            if (!Auth::isLandlord()) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Unauthorized: Only the landlord can review and verify payment receipts.'
                ]);
                exit;
            }

            $paymentId = (int)($input['payment_id'] ?? 0);
            $decision = strtolower(trim((string)($input['decision'] ?? 'approved'))); // 'approved' or 'rejected'
            $notes = trim((string)($input['notes'] ?? ''));

            if ($paymentId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
                exit;
            }

            if (!in_array($decision, ['approved', 'rejected'], true)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Decision must be either "approved" or "rejected".']);
                exit;
            }

            // Check if payment exists
            $check = $pdo->prepare("SELECT id, tenant_id, billing_cycle, amount_due, amount_paid, receipt_filename FROM payments WHERE id = :id LIMIT 1");
            $check->execute([':id' => $paymentId]);
            $payment = $check->fetch();

            if (!$payment) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
                exit;
            }

            $isApproved = ($decision === 'approved');
            $statusVal = $isApproved ? 'approved' : 'rejected';
            $verifiedFlag = $isApproved ? 1 : 0;
            $defaultNotes = $isApproved ? 'Payment receipt approved & verified.' : 'Payment proof rejected.';
            $finalNotes = !empty($notes) ? $notes : $defaultNotes;

            $stmt = $pdo->prepare("
                UPDATE payments 
                SET status = :status,
                    verified_by_landlord = :v,
                    verification_notes = :notes,
                    verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $statusVal,
                ':v' => $verifiedFlag,
                ':notes' => $finalNotes,
                ':id' => $paymentId
            ]);

            // Notify Tenant of review outcome
            if (!empty($payment['tenant_id'])) {
                $monthStr = date('F Y', strtotime($payment['billing_cycle']));
                $notifTitle = $isApproved ? 'Payment Proof Verified' : 'Payment Proof Rejected';
                $notifMsg = $isApproved
                    ? "Your payment proof for {$monthStr} has been successfully verified and approved."
                    : "Your payment proof for {$monthStr} was rejected. Note: {$finalNotes}. Please review and re-submit.";
                NotificationService::notifyTenant(
                    $pdo,
                    (int)$payment['tenant_id'],
                    'payment',
                    $notifTitle,
                    $notifMsg,
                    'tenantProfile',
                    $paymentId,
                    $isApproved ? 'payment_approved' : 'payment_rejected'
                );
            }

            echo json_encode([
                'status' => 'success',
                'message' => $isApproved 
                    ? 'Payment receipt successfully verified and approved!' 
                    : 'Payment receipt rejected. Feedback logged for tenant.',
                'payment' => [
                    'id' => $paymentId,
                    'status' => $statusVal,
                    'verified_by_landlord' => $verifiedFlag,
                    'verification_notes' => $finalNotes,
                    'verified_at' => date('Y-m-d H:i:s')
                ]
            ]);
            exit;
        }

        // ---------------------------------------------------------------------
        // 2. Tenant Receipt Upload Workflow
        // ---------------------------------------------------------------------
        if ($action === 'upload_receipt') {
            $tenantUser = Auth::user();
            $tenantId = $tenantUser ? (int)$tenantUser['id'] : 2;
            $amountPaid = (float)($_POST['amount_paid'] ?? 15000.00);
            $billingCycle = trim((string)($_POST['billing_cycle'] ?? date('Y-m-01')));
            $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Bank Transfer'));

            $savedFilename = null;

            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt'];
                $maxBytes = 8 * 1024 * 1024; // 8MB

                if ($file['size'] > $maxBytes) {
                    http_response_code(413);
                    echo json_encode(['status' => 'error', 'message' => 'Receipt exceeds the 8MB size limit.']);
                    exit;
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
                    http_response_code(415);
                    echo json_encode(['status' => 'error', 'message' => 'Only PDF, PNG, JPG, and WEBP receipts are permitted.']);
                    exit;
                }

                $safeName = sprintf(
                    'receipt_%s_%s.%s',
                    str_replace('-', '', $billingCycle),
                    bin2hex(random_bytes(6)),
                    $ext
                );

                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Failed to save receipt file to storage.']);
                    exit;
                }

                $savedFilename = $safeName;
            }

            // Check if record exists for this billing cycle and tenant
            $checkStmt = $pdo->prepare("SELECT id, amount_due, amount_paid FROM payments WHERE billing_cycle = :cycle AND tenant_id = :tid LIMIT 1");
            $checkStmt->execute([':cycle' => $billingCycle, ':tid' => $tenantId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                // When uploading or re-uploading receipt, set status to pending review
                $updateStmt = $pdo->prepare("
                    UPDATE payments 
                    SET amount_paid = :paid,
                        payment_date = CURDATE(),
                        status = 'pending',
                        receipt_filename = COALESCE(:receipt, receipt_filename),
                        payment_method = :method,
                        verified_by_landlord = 0,
                        verification_notes = NULL,
                        verified_at = NULL
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':paid' => $amountPaid,
                    ':receipt' => $savedFilename,
                    ':method' => $paymentMethod,
                    ':id' => $existing['id']
                ]);
                $paymentId = (int)$existing['id'];
            } else {
                $unitId = $tenantUser && isset($tenantUser['unit_id']) ? (int)$tenantUser['unit_id'] : 1;
                $unitRentStmt = $pdo->prepare("SELECT monthly_rent FROM units WHERE id = :uid LIMIT 1");
                $unitRentStmt->execute([':uid' => $unitId]);
                $unitRow = $unitRentStmt->fetch();
                $amountDue = $unitRow ? (float)$unitRow['monthly_rent'] : 6000.00;

                $insertStmt = $pdo->prepare("
                    INSERT INTO payments 
                        (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, payment_date, status, payment_method, receipt_filename, verified_by_landlord, created_at)
                    VALUES 
                        (:tenant_id, :unit_id, :cycle, :due_date, :due, :paid, CURDATE(), 'pending', :method, :receipt, 0, NOW())
                ");
                $insertStmt->execute([
                    ':tenant_id' => $tenantId,
                    ':unit_id' => $unitId,
                    ':cycle' => $billingCycle,
                    ':due_date' => date('Y-m-05', strtotime($billingCycle)),
                    ':due' => $amountDue,
                    ':paid' => $amountPaid,
                    ':method' => $paymentMethod,
                    ':receipt' => $savedFilename
                ]);
                $paymentId = (int)$pdo->lastInsertId();
            }

            // Notify Landlords of submitted payment proof
            $tenantName = $tenantUser['name'] ?? 'Tenant';
            $cycleMonth = date('F Y', strtotime($billingCycle));
            NotificationService::notifyLandlords(
                $pdo,
                'payment',
                'Payment Proof Submitted',
                "{$tenantName} submitted payment proof for {$cycleMonth}. Payment verification is required.",
                'sectionPayments',
                $paymentId,
                'payment_pending'
            );

            echo json_encode([
                'status' => 'success',
                'message' => 'Payment receipt uploaded successfully! Submitted for landlord verification.',
                'payment' => [
                    'id' => $paymentId,
                    'amount_paid' => $amountPaid,
                    'billing_cycle' => $billingCycle,
                    'receipt_filename' => $savedFilename,
                    'status' => 'pending'
                ]
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
        exit;
    }

    if ($requestMethod === 'GET') {
        $stmt = $pdo->query("
            SELECT p.*, u.name AS tenant_name, u.username AS tenant_username, un.unit_number
            FROM payments p
            JOIN users u ON p.tenant_id = u.id
            JOIN units un ON p.unit_id = un.id
            ORDER BY p.billing_cycle DESC, p.id DESC
        ");
        $payments = $stmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'payments' => $payments
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Payment service error: ' . $e->getMessage()
    ]);
}
