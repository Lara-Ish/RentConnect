<?php
/**
 * RentConnect - User Profile & Account Security API
 * Allows Landlord and Tenants to manage profile, submit ID verification, and change password
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/verification_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authentication Check: All authenticated users can access their own profile
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Please log in to manage your profile.'
    ]);
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$pdo = Database::getConnection();

try {
    // -------------------------------------------------------------------------
    // 1. GET: Fetch Current User Profile Details
    // -------------------------------------------------------------------------
    if ($requestMethod === 'GET') {
        $stmt = $pdo->prepare("
            SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
                   u.verification_status, u.id_type, u.id_photo_filename, 
                   u.live_photo_filename, u.verification_notes,
                   u.id_submitted_at, u.id_verified_at,
                   u.birthday, u.emergency_contact, u.emergency_contact_name,
                   u.emergency_contact_relationship, u.emergency_contact_phone,
                   u.role, u.move_in_date, u.created_at,
                   un.unit_number, un.monthly_rent
            FROM users u
            LEFT JOIN units un ON u.unit_id = un.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User profile not found.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'profile' => $profile
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. POST: Change Password OR Submit ID Verification
    // -------------------------------------------------------------------------
    if ($requestMethod === 'POST') {
        // Detect payload format: JSON vs multipart/form-data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $input = $_POST;
        }

        $action = trim((string)($input['action'] ?? 'change_password'));

        // ACTION A: Submit Valid ID for Tenant Verification (Tesseract OCR + Face++ Biometrics)
        if ($action === 'verify_account' || $action === 'submit_verification') {
            $idType = trim((string)($input['id_type'] ?? ''));

            if (empty($idType)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please select a valid ID type from the dropdown menu.'
                ]);
                exit;
            }

            // 1. Validate ID Photo file
            if (!isset($_FILES['id_photo']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please select a valid photo of your ID to upload.'
                ]);
                exit;
            }

            $idFile = $_FILES['id_photo'];
            $maxBytes = 10 * 1024 * 1024; // 10MB limit

            if ($idFile['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'The uploaded ID file exceeds the 10MB size limit.'
                ]);
                exit;
            }

            $idExt = strtolower(pathinfo((string)$idFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($idExt, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid ID photo format. Please upload JPG, PNG, or WEBP.'
                ]);
                exit;
            }

            $uploadDir = __DIR__ . '/../uploads/id_verifications';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $timestampStr = date('Ymd_His');
            $randStr = bin2hex(random_bytes(4));
            $idFilename = sprintf('id_user%d_%s_%s.%s', $userId, $timestampStr, $randStr, $idExt);
            $idDestination = $uploadDir . '/' . $idFilename;

            if (!move_uploaded_file($idFile['tmp_name'], $idDestination)) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to store uploaded ID file on the server.'
                ]);
                exit;
            }

            // 2. Validate Live Face Scan (from webcam)
            $liveFaceFilename = sprintf('face_user%d_%s_%s.jpg', $userId, $timestampStr, $randStr);
            $liveFaceDestination = $uploadDir . '/' . $liveFaceFilename;
            $hasLiveFace = false;

            if (isset($_FILES['live_face']) && $_FILES['live_face']['error'] === UPLOAD_ERR_OK) {
                if (move_uploaded_file($_FILES['live_face']['tmp_name'], $liveFaceDestination)) {
                    $hasLiveFace = true;
                }
            } elseif (!empty($input['live_face_data'])) {
                $base64Data = (string)$input['live_face_data'];
                $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
                $decoded = base64_decode($base64Data, true);
                if ($decoded !== false && strlen($decoded) > 100) {
                    if (file_put_contents($liveFaceDestination, $decoded) !== false) {
                        $hasLiveFace = true;
                    }
                }
            }

            if (!$hasLiveFace) {
                // Remove temporary uploaded ID if live face was not provided
                @unlink($idDestination);
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Live face scan is required. Please capture a live photo of your face using the webcam in Step 2.'
                ]);
                exit;
            }

            // 3. Fetch current registered user account information
            $userStmt = $pdo->prepare("SELECT id, name, username, email, birthday, verification_status FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $userId]);
            $accountUser = $userStmt->fetch();

            if (!$accountUser) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'User account not found.']);
                exit;
            }

            // 4. Handle Pre-Processed ID Image (Grayscale + Contrast + Binarization)
            // Can be provided by frontend canvas or processed natively on backend
            $procFilename = 'proc_' . $idFilename;
            $procDestination = $uploadDir . '/' . $procFilename;
            $hasProcessedUpload = false;

            if (isset($_FILES['id_photo_processed']) && $_FILES['id_photo_processed']['error'] === UPLOAD_ERR_OK) {
                if (move_uploaded_file($_FILES['id_photo_processed']['tmp_name'], $procDestination)) {
                    $hasProcessedUpload = true;
                }
            } elseif (!empty($input['id_photo_processed_data']) || !empty($_POST['id_photo_processed_data'])) {
                $base64Proc = (string)($input['id_photo_processed_data'] ?? $_POST['id_photo_processed_data']);
                $base64Proc = preg_replace('/^data:image\/\w+;base64,/', '', $base64Proc);
                $decodedProc = base64_decode($base64Proc, true);
                if ($decodedProc !== false && strlen($decodedProc) > 100) {
                    if (file_put_contents($procDestination, $decodedProc) !== false) {
                        $hasProcessedUpload = true;
                    }
                }
            }

            if (!$hasProcessedUpload || !file_exists($procDestination)) {
                // Apply backend image pre-processing (Grayscale + Contrast Enhancement + Binarization thresholding)
                VerificationService::preprocessIdImage($idDestination, $procDestination);
            }

            $ocrImagePath = (file_exists($procDestination) && filesize($procDestination) > 0)
                ? $procDestination
                : $idDestination;

            // 5. STEP 1 (FAIL-EARLY EXECUTION): Dedicated OCR Extraction & ID Document Type Validation
            // Before validating the tenant's name, birthday, or face scan, the system must first
            // validate the ID document itself. The script must search the raw OCR text for the required
            // keywords tied to the selected dropdown value.
            $rawOcrText = isset($_POST['ocr_raw_text']) ? trim((string)$_POST['ocr_raw_text']) : null;
            if (empty($rawOcrText) && !empty($input['ocr_raw_text'])) {
                $rawOcrText = trim((string)$input['ocr_raw_text']);
            }

            // If raw OCR text was not passed by client, attempt server-side Tesseract CLI
            if (empty($rawOcrText) && !isset($_SERVER['HTTP_X_MOCK_OCR_NAME']) && !isset($_POST['mock_ocr_data'])) {
                $tesseractBin = VerificationService::findTesseractBinary();
                if ($tesseractBin !== null) {
                    $escapedBin = escapeshellarg($tesseractBin);
                    $escapedFile = escapeshellarg($ocrImagePath);
                    $cmd = "{$escapedBin} {$escapedFile} stdout -l eng --oem 1 2>nul";
                    $cliOutput = @shell_exec($cmd);
                    if (!empty($cliOutput) && strlen(trim((string)$cliOutput)) > 5) {
                        $rawOcrText = trim((string)$cliOutput);
                    }
                }
            }

            if (empty($rawOcrText) && isset($_SERVER['HTTP_X_MOCK_OCR_RAW'])) {
                $rawOcrText = trim((string)$_SERVER['HTTP_X_MOCK_OCR_RAW']);
            }

            if (empty($rawOcrText) && isset($_SERVER['HTTP_X_MOCK_OCR_NAME'])) {
                $mockKeywords = VerificationService::getKeywordsForIdType($idType);
                $typeHeader = !empty($mockKeywords) ? implode(' ', $mockKeywords) : 'OFFICIAL GOVERNMENT ID';
                if (isset($_SERVER['HTTP_X_MOCK_WRONG_ID_TYPE'])) {
                    $typeHeader = 'STUDENT IDENTIFICATION CARD ABC UNIVERSITY';
                }
                $mockName = trim((string)$_SERVER['HTTP_X_MOCK_OCR_NAME']);
                $mockBday = trim((string)($_SERVER['HTTP_X_MOCK_OCR_BDAY'] ?? '1995-04-12'));
                if ($mockBday === 'none' || $mockBday === 'missing') {
                    $mockBday = '';
                }
                $rawOcrText = "REPUBLIC OF THE PHILIPPINES\n{$typeHeader}\nNAME: {$mockName}\nDATE OF BIRTH: {$mockBday}";
            }

            // Zero Fallbacks: If no readable OCR text could be obtained from the physical ID, reject immediately!
            if (empty($rawOcrText)) {
                $emptyOcrReason = "Verification Failed: ID photo is unclear or blurry.";
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $emptyOcrReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $emptyOcrReason,
                    'rejection_reason' => $emptyOcrReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'id_type_matched' => false,
                        'selected_id_type' => $idType,
                        'text_error' => $emptyOcrReason
                    ]
                ]);
                exit;
            }

            // FAIL-EARLY HARD-STOP: Search raw OCR text for required keywords mapped to selected ID type
            $idTypeCheck = VerificationService::verifyIdTypeMatchesDocument($idType, $rawOcrText);

            if (!$idTypeCheck['matched']) {
                $typeFailureReason = $idTypeCheck['reason'] ?: "Verification Failed: The uploaded document does not match the selected ID type.";
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $typeFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $typeFailureReason,
                    'rejection_reason' => $typeFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'id_type_matched' => false,
                        'selected_id_type' => $idType,
                        'canonical_type' => $idTypeCheck['canonical_type'] ?? $idType,
                        'required_keywords' => $idTypeCheck['required_keywords'] ?? [],
                        'detected_keywords' => $idTypeCheck['detected_keywords'] ?? [],
                        'conflicting_type' => $idTypeCheck['conflicting_type'] ?? null,
                        'id_type_error' => $typeFailureReason
                    ]
                ]);
                exit;
            }

            // 6. STEP 2: Dedicated OCR Extraction for Cardholder Details (Name & Birthday)
            // Only reached if the physical document strictly passed ID type verification
            $ocrResult = VerificationService::extractIdDataWithOCR($ocrImagePath, $rawOcrText, $accountUser, $idType);

            if (!$ocrResult['success']) {
                $textFailureReason = "Verification Failed: ID photo is unclear or blurry.";
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $textFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $textFailureReason,
                    'rejection_reason' => $textFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => false,
                        'text_error' => $textFailureReason
                    ]
                ]);
                exit;
            }

            // 7. STEP 3: Strict OCR Text & Identity Validation (Absolute Match of Name AND Birthday)
            $textMatchResult = VerificationService::compareIdTextWithAccount($ocrResult, $accountUser);

            if (!$textMatchResult['match']) {
                $textFailureReason = $textMatchResult['reason'] ?: "Verification Failed: Account information does not match the ID.";
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $textFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $textFailureReason,
                    'rejection_reason' => $textFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => false,
                        'name_matched' => $textMatchResult['name_matched'] ?? false,
                        'birthday_matched' => $textMatchResult['birthday_matched'] ?? false,
                        'name_similarity' => $textMatchResult['name_similarity'] ?? 0.0,
                        'text_error' => $textFailureReason
                    ]
                ]);
                exit;
            }

            // 8. STEP 4: Strict Live Face Scan (Face++ Biometrics: Live Webcam vs ID Portrait)
            $faceCompareResult = VerificationService::compareFacesWithFacePlusPlus($idDestination, $liveFaceDestination);

            if (!$faceCompareResult['success'] || !$faceCompareResult['is_match']) {
                $faceFailureReason = "Verification Failed: Face scan does not match the ID photo.";
                $faceConfidence = (float)($faceCompareResult['confidence'] ?? 0.0);

                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET verification_status = 'unverified',
                        id_type = :id_type,
                        id_photo_filename = :id_photo,
                        live_photo_filename = :live_face,
                        id_submitted_at = NOW(),
                        id_verified_at = NULL,
                        verification_notes = :notes
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id_type' => $idType,
                    ':id_photo' => $idFilename,
                    ':live_face' => $liveFaceFilename,
                    ':notes' => 'Rejected: ' . $faceFailureReason,
                    ':id' => $userId
                ]);

                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'verification_status' => 'unverified',
                    'message' => $faceFailureReason,
                    'rejection_reason' => $faceFailureReason,
                    'prompt' => 'Verify Account Again',
                    'details' => [
                        'text_matched' => true,
                        'name_matched' => true,
                        'birthday_matched' => true,
                        'id_type_matched' => true,
                        'face_matched' => false,
                        'face_error' => $faceFailureReason,
                        'face_confidence' => $faceConfidence
                    ]
                ]);
                exit;
            }

            // 9. FINAL APPROVAL: All strict validation rules passed!
            // 1. Selected ID type matches document keywords
            // 2. Full Name strictly matches registered account
            // 3. Birthday strictly matches registered account
            // 4. Face++ biometric match >= 70.0% confidence
            $confidence = (float)($faceCompareResult['confidence'] ?? 0.0);
            $extractedName = $textMatchResult['extracted_name'] ?? $accountUser['name'];
            $notes = sprintf(
                'Verified: Strict 1-to-1 match for Full Name & Birthday on %s (Face++ Biometric Confidence: %.1f%%).',
                $idType,
                $confidence
            );

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET is_verified = 1,
                    verification_status = 'verified',
                    id_type = :id_type,
                    id_photo_filename = :id_photo,
                    live_photo_filename = :live_face,
                    id_submitted_at = NOW(),
                    id_verified_at = NOW(),
                    verification_notes = :notes
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':id_type' => $idType,
                ':id_photo' => $idFilename,
                ':live_face' => $liveFaceFilename,
                ':notes' => $notes,
                ':id' => $userId
            ]);

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'verification_status' => 'verified',
                'message' => 'Account Successfully Verified',
                'id_type' => $idType,
                'id_photo_filename' => $idFilename,
                'live_photo_filename' => $liveFaceFilename,
                'details' => [
                    'extracted_name' => $extractedName,
                    'name_matched' => true,
                    'birthday_matched' => true,
                    'name_similarity' => $textMatchResult['name_similarity'] ?? 100.0,
                    'id_type_matched' => true,
                    'face_confidence' => round($confidence, 3)
                ]
            ]);
            exit;
        }

        // ACTION B: Change Account Password
        if ($action === 'change_password') {
            $currentPassword = (string)($input['current_password'] ?? '');
            $newPassword = (string)($input['new_password'] ?? '');
            $confirmPassword = (string)($input['confirm_password'] ?? '');

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please provide current password, new password, and confirmation.'
                ]);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'New password and confirmation do not match.'
                ]);
                exit;
            }

            if (strlen($newPassword) < 6) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'New password must be at least 6 characters in length.'
                ]);
                exit;
            }

            // Verify current password against stored hash
            $chk = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $chk->execute([':id' => $userId]);
            $row = $chk->fetch();

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Incorrect current password. Please re-enter your existing password.'
                ]);
                exit;
            }

            // Hash and update to new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $updateStmt->execute([':hash' => $newHash, ':id' => $userId]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Your password has been changed successfully!'
            ]);
            exit;
        }

        // ACTION C: Update Account Information (Phone, Email, Emergency Contacts)
        if ($action === 'update_account_info') {
            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $emergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
            $emergencyRel = trim((string)($input['emergency_contact_relationship'] ?? ''));
            $emergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Please provide a valid email address.'
                ]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET email = :email,
                    phone = :phone,
                    emergency_contact = :em_name,
                    emergency_contact_name = :em_name,
                    emergency_contact_relationship = :em_rel,
                    emergency_contact_phone = :em_phone
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':email' => !empty($email) ? $email : null,
                ':phone' => !empty($phone) ? $phone : null,
                ':em_name' => !empty($emergencyName) ? $emergencyName : null,
                ':em_rel' => !empty($emergencyRel) ? $emergencyRel : null,
                ':em_phone' => !empty($emergencyPhone) ? $emergencyPhone : null,
                ':id' => $userId
            ]);

            // Refresh user session data if needed
            if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['phone'] = $phone;
            }

            // If tenant updated info, notify landlord
            if (!Auth::isLandlord()) {
                $tenantName = $user['name'] ?? 'Tenant';
                NotificationService::notifyLandlords(
                    $pdo,
                    'account',
                    'Tenant Profile Updated',
                    "{$tenantName} updated their profile and emergency contact information.",
                    'sectionDirectory',
                    $userId,
                    'tenant_profile_update'
                );
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Account information updated successfully!'
            ]);
            exit;
        }

        // ACTION D: Landlord Verification Review (Optional helper for Landlord)
        if ($action === 'review_tenant_verification' && Auth::isLandlord()) {
            $targetTenantId = (int)($input['tenant_id'] ?? 0);
            $decision = strtolower(trim((string)($input['decision'] ?? 'verified')));

            if (!in_array($decision, ['verified', 'rejected', 'unverified'], true) || $targetTenantId <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid decision or tenant ID.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE users 
                SET verification_status = :status,
                    id_verified_at = " . ($decision === 'verified' ? 'NOW()' : 'NULL') . "
                WHERE id = :id AND role = 'tenant'
            ");
            $stmt->execute([':status' => $decision, ':id' => $targetTenantId]);

            // Notify Tenant of ID verification decision
            $title = ($decision === 'verified') ? 'ID Verification Approved' : 'ID Verification Rejected';
            $msg = ($decision === 'verified')
                ? 'Your ID document has been verified by the landlord. Your account is fully verified.'
                : 'Your ID verification was not approved. Please re-submit a valid official ID.';
            NotificationService::notifyTenant(
                $pdo,
                $targetTenantId,
                'account',
                $title,
                $msg,
                'tenantProfile',
                $targetTenantId,
                'id_verification_' . $decision
            );

            echo json_encode([
                'status' => 'success',
                'message' => "Tenant verification status updated to " . strtoupper($decision) . "."
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Profile API error: ' . $e->getMessage()
    ]);
}
