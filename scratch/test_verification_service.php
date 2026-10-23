<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/verification_service.php';

echo "=== TESTING RELAXED VERIFICATION SERVICE & OCR EXTRACTION ===\n\n";

$passCount = 0;
$failCount = 0;

function check(string $label, bool $condition, string $msg = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $label\n";
        $passCount++;
    } else {
        echo " [FAIL] $label - $msg\n";
        $failCount++;
    }
}

// -----------------------------------------------------------------------------
// Test 1: compareIdTextWithAccount (Relaxed Forgiving Validation)
// -----------------------------------------------------------------------------
$account1 = ['name' => 'Juan Dela Cruz', 'birthday' => '1995-04-12'];

// Case 1: Exact name & birthday match -> Approved
$res1 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '1995-04-12'],
    $account1
);
check("Exact name & birthday match is approved", $res1['match'] === true && $res1['name_matched'] === true && $res1['birthday_matched'] === true);

// Case 2: Permuted name format ("DELA CRUZ, JUAN") & matching birthday -> Approved
$res2 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'DELA CRUZ, JUAN', 'birthday' => '1995-04-12'],
    $account1
);
check("Permuted name with comma is approved", $res2['match'] === true && $res2['name_matched'] === true);

// Case 3: Relaxed rule: Full Name matches, but Birthday is MISMATCHED -> Still Approved!
$res3 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '1990-01-01'],
    $account1
);
check(
    "Relaxed logic: Name matches but birthday mismatched is approved",
    $res3['match'] === true && $res3['name_matched'] === true && $res3['birthday_matched'] === false
);

// Case 4: Relaxed rule: Full Name matches, but Birthday is MISSING -> Still Approved!
$res4 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => ''],
    $account1
);
check(
    "Relaxed logic: Name matches but birthday missing is approved",
    $res4['match'] === true && $res4['name_matched'] === true && $res4['birthday_matched'] === false
);

// Case 5: CRITICAL SECURITY FIX: Different person's name with SAME birthday -> STRICTLY REJECTED!
$res5a = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Maria Santos', 'birthday' => '1995-04-12'],
    $account1
);
check(
    "Critical Security: Different person's ID (Maria Santos for Juan) with same birthday is strictly rejected",
    $res5a['match'] === false && $res5a['name_matched'] === false && str_contains($res5a['reason'], 'Strict identity validation failed')
);

// Case 5b: Missing name on ID (even with matching birthday) -> STRICTLY REJECTED!
$res5b = VerificationService::compareIdTextWithAccount(
    ['full_name' => '', 'birthday' => '1995-04-12'],
    $account1
);
check(
    "Critical Security: ID with missing name is strictly rejected",
    $res5b['match'] === false && $res5b['name_matched'] === false && str_contains($res5b['reason'], 'Strict identity validation failed')
);

// Case 6: BOTH Name AND Birthday Mismatched -> REJECTED!
$res6 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Maria Santos', 'birthday' => '1980-01-01'],
    $account1
);
check(
    "Rejection: Both Name and Birthday mismatch is rejected",
    $res6['match'] === false && $res6['name_matched'] === false && $res6['birthday_matched'] === false
);

// Case 7: Extraneous unrelated cardholder -> REJECTED!
$res7 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Pedro Penduko', 'birthday' => '1970-12-25'],
    $account1
);
check(
    "Rejection: Unrelated cardholder is rejected",
    $res7['match'] === false && str_contains($res7['reason'], 'Strict identity validation failed')
);

// -----------------------------------------------------------------------------
// Test 2: compareFacesWithFacePlusPlus (Live Scan Biometric Verification)
// -----------------------------------------------------------------------------
$lenaPath = __DIR__ . '/lena.jpg';
$messiPath = __DIR__ . '/messi.jpg';
$boardPath = __DIR__ . '/board.jpg';

// Same face (Lena vs Lena) -> High Confidence Match
$faceRes1 = VerificationService::compareFacesWithFacePlusPlus($lenaPath, $lenaPath);
check(
    "Face++ same face comparison returns match >= 70%",
    $faceRes1['success'] === true && $faceRes1['is_match'] === true && $faceRes1['confidence'] >= 70.0,
    "Got: " . json_encode($faceRes1)
);

// Different face (Lena vs Messi) -> Mismatch rejected
$faceRes2 = VerificationService::compareFacesWithFacePlusPlus($lenaPath, $messiPath);
check(
    "Face++ different face returns mismatch < 70%",
    $faceRes2['success'] === true && $faceRes2['is_match'] === false && $faceRes2['confidence'] < 70.0,
    "Got: " . json_encode($faceRes2)
);

// No face in image1 (Board vs Lena) -> Detected and rejected
$faceRes3 = VerificationService::compareFacesWithFacePlusPlus($boardPath, $lenaPath);
check(
    "Face++ no face on ID is caught and rejected",
    $faceRes3['is_match'] === false && str_contains($faceRes3['error'] ?? '', 'No human face detected'),
    "Got: " . json_encode($faceRes3)
);

// -----------------------------------------------------------------------------
// Test 3: OCR Extraction & Validation End-to-End
// -----------------------------------------------------------------------------
$samplePhilIdText = "REPUBLIC OF THE PHILIPPINES\nPHILIPPINE IDENTIFICATION SYSTEM\nSURNAME: DELA CRUZ\nGIVEN NAMES: JUAN\nDATE OF BIRTH: 1995-04-12\nCRN: 1234-5678-9012-3456";
$ocrRes1 = VerificationService::extractIdDataWithOCR($lenaPath, $samplePhilIdText, $account1);
check("OCR extracts Full Name and Birthday from PhilSys ID format", $ocrRes1['success'] === true && $ocrRes1['full_name'] === 'JUAN DELA CRUZ' && $ocrRes1['birthday'] === '1995-04-12');

// Comparison of OCR extracted data against DB account
$matchRes1 = VerificationService::compareIdTextWithAccount($ocrRes1, $account1);
check("OCR extracted PhilSys ID strictly matches registered account", $matchRes1['match'] === true);

// Driver's License format
$sampleDlText = "REPUBLIC OF THE PHILIPPINES\nDEPARTMENT OF TRANSPORTATION\nLAND TRANSPORTATION OFFICE\nDELA CRUZ, JUAN\nDOB: April 12, 1995\nLICENSE NO: N02-95-123456";
$ocrRes2 = VerificationService::extractIdDataWithOCR($lenaPath, $sampleDlText, $account1);
check("OCR extracts Last, First and formatted DOB from Driver's License", $ocrRes2['success'] === true && $ocrRes2['full_name'] === 'DELA CRUZ, JUAN' && $ocrRes2['birthday'] === '1995-04-12');
$matchRes2 = VerificationService::compareIdTextWithAccount($ocrRes2, $account1);
check("OCR extracted Driver's License matches registered account", $matchRes2['match'] === true);

// ID with Name Match but Missing Birthday (e.g. Student ID)
$sampleStudentIdText = "UNIVERSITY OF SANTO TOMAS\nSTUDENT IDENTIFICATION\nNAME: JUAN DELA CRUZ\nSTUDENT NO: 2020-001234";
$ocrRes3 = VerificationService::extractIdDataWithOCR($lenaPath, $sampleStudentIdText, $account1);
$matchRes3 = VerificationService::compareIdTextWithAccount($ocrRes3, $account1);
check("Student ID with Name match but missing birthday is approved under relaxed validation", $matchRes3['match'] === true && $matchRes3['name_matched'] === true && $matchRes3['birthday_matched'] === false);

// Completely unmatched cardholder
$sampleUnmatchedText = "REPUBLIC OF THE PHILIPPINES\nSURNAME: SANTOS\nGIVEN NAMES: MARIA\nDATE OF BIRTH: 1980-05-10";
$ocrRes4 = VerificationService::extractIdDataWithOCR($lenaPath, $sampleUnmatchedText, $account1);
$matchRes4 = VerificationService::compareIdTextWithAccount($ocrRes4, $account1);
check("Completely mismatched OCR cardholder (both name and birthday wrong) is rejected", $matchRes4['match'] === false);

echo "\nSummary: $passCount passed, $failCount failed.\n";
