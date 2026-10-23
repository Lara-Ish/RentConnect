<?php
/**
 * Test ID Type Classification and Active OCR Cross-Checking
 * Validates that selecting one ID type while uploading a document belonging to another ID type
 * (e.g. Driver's License vs PhilHealth ID or Student ID) triggers an immediate 422 rejection
 * with the exact error message: "Verification Failed: The uploaded document does not match the selected ID type."
 */

require_once __DIR__ . '/../includes/verification_service.php';

function assertTest(string $description, bool $condition, ?string $extra = null): void
{
    if ($condition) {
        echo " [PASS] $description\n";
    } else {
        echo " [FAIL] $description" . ($extra ? " ($extra)" : "") . "\n";
        exit(1);
    }
}

echo "=== TESTING STRICT ID TYPE MATCHING & ACTIVE OCR CROSS-CHECKING ===\n\n";

// Test Case 1: User selects "Driver's License", but uploads PhilHealth ID
$philHealthOcr = "REPUBLIKA NG PILIPINAS PHILIPPINE HEALTH INSURANCE CORPORATION PHILHEALTH ID 12-345678901-2";
$result1 = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $philHealthOcr);
assertTest(
    "Driver's License selected with PhilHealth ID text -> Rejected",
    $result1['matched'] === false
);
assertTest(
    "Driver's License selected with PhilHealth ID text -> Reason matches strict prompt message",
    $result1['reason'] === 'Verification Failed: The uploaded document does not match the selected ID type.'
);
assertTest(
    "Driver's License selected with PhilHealth ID text -> Conflicting type detected as PhilHealth ID",
    $result1['conflicting_type'] === 'PhilHealth ID'
);

// Test Case 2: User selects "Driver's License", but uploads Student ID
$studentOcr = "DE LA SALLE UNIVERSITY MANILA STUDENT IDENTIFICATION CARD STUDENT NO: 12012345 COLLEGE OF SCIENCE";
$result2 = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $studentOcr);
assertTest(
    "Driver's License selected with Student ID text -> Rejected",
    $result2['matched'] === false
);
assertTest(
    "Driver's License selected with Student ID text -> Reason matches strict prompt message",
    $result2['reason'] === 'Verification Failed: The uploaded document does not match the selected ID type.'
);
assertTest(
    "Driver's License selected with Student ID text -> Conflicting type detected as Student ID",
    $result2['conflicting_type'] === 'Student ID'
);

// Test Case 3: User selects "PhilSys / National ID", but uploads Driver's License
$driverOcr = "REPUBLIC OF THE PHILIPPINES DEPARTMENT OF TRANSPORTATION LAND TRANSPORTATION OFFICE DRIVER'S LICENSE NON-PROFESSIONAL";
$result3 = VerificationService::verifyIdTypeMatchesDocument("PhilSys / National ID", $driverOcr);
assertTest(
    "National ID selected with Driver's License text -> Rejected",
    $result3['matched'] === false
);
assertTest(
    "National ID selected with Driver's License text -> Reason matches strict prompt message",
    $result3['reason'] === 'Verification Failed: The uploaded document does not match the selected ID type.'
);
assertTest(
    "National ID selected with Driver's License text -> Conflicting type detected as Driver's License",
    $result3['conflicting_type'] === "Driver's License"
);

// Test Case 4: User selects "Passport", but uploads Voter's ID
$voterOcr = "REPUBLIC OF THE PHILIPPINES COMMISSION ON ELECTIONS COMELEC VOTER'S IDENTIFICATION CARD";
$result4 = VerificationService::verifyIdTypeMatchesDocument("Passport", $voterOcr);
assertTest(
    "Passport selected with Voter's ID text -> Rejected",
    $result4['matched'] === false
);
assertTest(
    "Passport selected with Voter's ID text -> Reason matches strict prompt message",
    $result4['reason'] === 'Verification Failed: The uploaded document does not match the selected ID type.'
);

// Test Case 5: Valid Match - User selects "Driver's License" and uploads Driver's License
$validDriver = "REPUBLIC OF THE PHILIPPINES LAND TRANSPORTATION OFFICE DRIVER'S LICENSE N01-23-456789";
$result5 = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $validDriver);
assertTest(
    "Driver's License selected with valid Driver's License text -> Approved",
    $result5['matched'] === true
);

// Test Case 6: Valid Match - User selects "Student ID" and uploads Student ID
$validStudent = "ATENEO DE MANILA UNIVERSITY STUDENT ID CARD 2024-2025";
$result6 = VerificationService::verifyIdTypeMatchesDocument("Student ID", $validStudent);
assertTest(
    "Student ID selected with valid Student ID text -> Approved",
    $result6['matched'] === true
);

// Test Case 7: Valid Match - User selects "PhilHealth ID" and uploads PhilHealth ID
$validPhilHealth = "PHILIPPINE HEALTH INSURANCE CORPORATION PHILHEALTH MEMBER ID";
$result7 = VerificationService::verifyIdTypeMatchesDocument("PhilHealth ID", $validPhilHealth);
assertTest(
    "PhilHealth ID selected with valid PhilHealth ID text -> Approved",
    $result7['matched'] === true
);

echo "\nAll 11 strict verification and cross-checking assertions PASSED!\n";
