<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/verification_service.php';

echo "=== RUNNING STRICT OCR, ID TYPE, & FACE++ VERIFICATION UNIT TESTS ===\n\n";

$pass = 0;
$fail = 0;

function assertTest(string $name, bool $cond, string $details = '') {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $name\n";
        $pass++;
    } else {
        echo " [FAIL] $name - $details\n";
        $fail++;
    }
}

// -----------------------------------------------------------------------------
// 1. IMAGE PRE-PROCESSING TESTS (Grayscale, Contrast, Binarization)
// -----------------------------------------------------------------------------
$sourceImage = __DIR__ . '/lena.jpg';
$outProc = __DIR__ . '/unit_proc_lena.jpg';
if (file_exists($outProc)) @unlink($outProc);

$procResult = VerificationService::preprocessIdImage($sourceImage, $outProc);
assertTest("Pre-processed image file generated", file_exists($procResult) && filesize($procResult) > 0);
assertTest("Pre-processed image differs from source bytes", file_get_contents($procResult) !== file_get_contents($sourceImage));

// -----------------------------------------------------------------------------
// 2. STRICT ID TYPE KEYWORD VALIDATION TESTS (HARDCODED PHILIPPINE SIGNATURES)
// -----------------------------------------------------------------------------
// 2.1 Driver's License Signatures: "Land Transportation Office", "Driver's License"
$dlText = "REPUBLIC OF THE PHILIPPINES LAND TRANSPORTATION OFFICE DRIVER'S LICENSE\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12\nDL NO: N02-95-123456";
$dlCheck = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $dlText);
assertTest("Driver's License: detects 'Land Transportation Office' and 'Driver\'s License'", $dlCheck['matched'] === true && count($dlCheck['detected_keywords']) >= 2);

$dlTextOnlyLTO = "REPUBLIC OF THE PHILIPPINES LAND TRANSPORTATION OFFICE\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$dlCheckLTO = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $dlTextOnlyLTO);
assertTest("Driver's License: detects standalone 'Land Transportation Office'", $dlCheckLTO['matched'] === true);

$dlTextOnlyDL = "OFFICIAL DOCUMENT DRIVERS LICENSE\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$dlCheckDL = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $dlTextOnlyDL);
assertTest("Driver's License: detects standalone 'Driver\'s License' (also with dropped apostrophe)", $dlCheckDL['matched'] === true);

// 2.2 National ID (PhilSys) Signatures: "Republika ng Pilipinas", "National ID", "PhilSys"
$nidText1 = "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$nidCheck1 = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", $nidText1);
assertTest("National ID (PhilSys): detects 'Republika ng Pilipinas'", $nidCheck1['matched'] === true && in_array('Republika ng Pilipinas', $nidCheck1['detected_keywords']));

$nidText1b = "REPUBLIKA NG PILIPINAS\nPHILIPPINE IDENTIFICATION SYSTEM\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$nidCheck1b = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", $nidText1b);
assertTest("National ID (PhilSys): detects 'Republika ng Pilipinas' in official header", $nidCheck1b['matched'] === true && in_array('Republika ng Pilipinas', $nidCheck1b['detected_keywords']));

$nidText2 = "PHILIPPINES NATIONAL ID CARD\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$nidCheck2 = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", $nidText2);
assertTest("National ID (PhilSys): detects 'National ID'", $nidCheck2['matched'] === true && in_array('National ID', $nidCheck2['detected_keywords']));

$nidText3 = "PHILSYS CARD IDENTIFICATION\nNAME: JUAN DELA CRUZ\nDOB: 1995-04-12";
$nidCheck3 = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", $nidText3);
assertTest("National ID (PhilSys): detects 'PhilSys'", $nidCheck3['matched'] === true && in_array('PhilSys', $nidCheck3['detected_keywords']));

// 2.3 Passport Signatures: "Pasaporte", "Passport", "Department of Foreign Affairs"
$passText1 = "REPUBLIKA NG PILIPINAS DFA PASAPORTE\nSURNAME: DELA CRUZ\nGIVEN NAMES: JUAN\nDATE OF BIRTH: 12 APR 1995";
$passCheck1 = VerificationService::verifyIdTypeMatchesDocument("Passport", $passText1);
assertTest("Passport: detects 'Pasaporte'", $passCheck1['matched'] === true && in_array('Pasaporte', $passCheck1['detected_keywords']));

$passText2 = "REPUBLIC OF THE PHILIPPINES DFA PASSPORT\nSURNAME: DELA CRUZ\nGIVEN NAMES: JUAN\nDATE OF BIRTH: 12 APR 1995";
$passCheck2 = VerificationService::verifyIdTypeMatchesDocument("Passport", $passText2);
assertTest("Passport: detects 'Passport'", $passCheck2['matched'] === true && in_array('Passport', $passCheck2['detected_keywords']));

$passText3 = "DEPARTMENT OF FOREIGN AFFAIRS PASSPORT REPUBLIC OF THE PHILIPPINES";
$passCheck3 = VerificationService::verifyIdTypeMatchesDocument("Passport", $passText3);
assertTest("Passport: detects 'Department of Foreign Affairs'", $passCheck3['matched'] === true && in_array('Department of Foreign Affairs', $passCheck3['detected_keywords']));

// 2.4 UMID Signatures: "Unified Multi-Purpose ID", "UMID", "Republic of the Philippines"
$umidText1 = "REPUBLIKA NG PILIPINAS UNIFIED MULTI-PURPOSE ID\nCRN: 0123-4567890-1\nNAME: JUAN DELA CRUZ";
$umidCheck1 = VerificationService::verifyIdTypeMatchesDocument("UMID", $umidText1);
assertTest("UMID: detects 'Unified Multi-Purpose ID'", $umidCheck1['matched'] === true && in_array('Unified Multi-Purpose ID', $umidCheck1['detected_keywords']));

$umidText2 = "REPUBLIKA NG PILIPINAS UMID CARD\nCRN: 0123-4567890-1\nNAME: JUAN DELA CRUZ";
$umidCheck2 = VerificationService::verifyIdTypeMatchesDocument("UMID", $umidText2);
assertTest("UMID: detects 'UMID'", $umidCheck2['matched'] === true && in_array('UMID', $umidCheck2['detected_keywords']));

$umidText3 = "REPUBLIC OF THE PHILIPPINES IDENTIFICATION CARD";
$umidCheck3 = VerificationService::verifyIdTypeMatchesDocument("UMID", $umidText3);
assertTest("UMID: detects 'Republic of the Philippines'", $umidCheck3['matched'] === true && in_array('Republic of the Philippines', $umidCheck3['detected_keywords']));

// 2.4b SSS / GSIS ID: "Social Security System", "SSS", "Government Service Insurance System", "GSIS"
$sssCheck1 = VerificationService::verifyIdTypeMatchesDocument("SSS / GSIS ID", "SOCIAL SECURITY SYSTEM MEMBER CARD SSS ID");
assertTest("SSS / GSIS ID: detects 'Social Security System' & 'SSS'", $sssCheck1['matched'] === true && in_array('Social Security System', $sssCheck1['detected_keywords']));

$gsisCheck1 = VerificationService::verifyIdTypeMatchesDocument("SSS / GSIS ID", "GOVERNMENT SERVICE INSURANCE SYSTEM GSIS E-CARD");
assertTest("SSS / GSIS ID: detects 'Government Service Insurance System' & 'GSIS'", $gsisCheck1['matched'] === true && in_array('GSIS', $gsisCheck1['detected_keywords']));

// 2.4c PRC ID: "Professional Regulation Commission", "PRC", "Professional Identification Card"
$prcCheck1 = VerificationService::verifyIdTypeMatchesDocument("PRC ID", "PROFESSIONAL REGULATION COMMISSION PROFESSIONAL IDENTIFICATION CARD");
assertTest("PRC ID: detects 'Professional Regulation Commission'", $prcCheck1['matched'] === true && in_array('Professional Regulation Commission', $prcCheck1['detected_keywords']));

// 2.4d Postal ID: "Philippine Postal Corporation", "PHLPost", "Postal ID"
$postalCheck1 = VerificationService::verifyIdTypeMatchesDocument("Postal ID", "PHILIPPINE POSTAL CORPORATION PHLPOST POSTAL ID");
assertTest("Postal ID: detects 'Philippine Postal Corporation' & 'PHLPost'", $postalCheck1['matched'] === true && in_array('PHLPost', $postalCheck1['detected_keywords']));

// 2.4e Voter's ID: "Commission on Elections", "COMELEC", "Voter's Identification Card"
$voterCheck1 = VerificationService::verifyIdTypeMatchesDocument("Voter's ID", "COMMISSION ON ELECTIONS COMELEC VOTER'S IDENTIFICATION CARD");
assertTest("Voter's ID: detects 'Commission on Elections' & 'COMELEC'", $voterCheck1['matched'] === true && in_array('Commission on Elections', $voterCheck1['detected_keywords']));

// 2.4f PhilHealth ID: "Philippine Health Insurance Corporation", "PhilHealth"
$philHealthCheck1 = VerificationService::verifyIdTypeMatchesDocument("PhilHealth ID", "PHILIPPINE HEALTH INSURANCE CORPORATION PHILHEALTH CARD");
assertTest("PhilHealth ID: detects 'Philippine Health Insurance Corporation' & 'PhilHealth'", $philHealthCheck1['matched'] === true && in_array('PhilHealth', $philHealthCheck1['detected_keywords']));

// 2.4g Student ID: "Student", "University", "College", "School", "Institute", "Campus"
$studentCheck1 = VerificationService::verifyIdTypeMatchesDocument("Student ID", "DE LA SALLE UNIVERSITY MANILA CAMPUS STUDENT IDENTIFICATION");
assertTest("Student ID: detects educational keywords ('Student', 'University', 'Campus')", $studentCheck1['matched'] === true && in_array('Student', $studentCheck1['detected_keywords']));

// Driver's License with LTO
$dlLtoCheck = VerificationService::verifyIdTypeMatchesDocument("Driver's License", "LAND TRANSPORTATION OFFICE (LTO) DRIVER'S LICENSE");
assertTest("Driver's License: detects 'LTO'", $dlLtoCheck['matched'] === true && in_array('LTO', $dlLtoCheck['detected_keywords']));

// 2.5 Strict Mismatch Rejection Tests
// Case 2.5a1: If tenant selects "PRC ID" but OCR scans "Commission on Elections" (Voter's ID), strictly reject!
$mismatchPrcVoter = VerificationService::verifyIdTypeMatchesDocument("PRC ID", "REPUBLIKA NG PILIPINAS COMMISSION ON ELECTIONS VOTER'S IDENTIFICATION CARD");
assertTest(
    "Strict Protocol: User selects 'PRC ID', OCR scans 'Commission on Elections' -> immediately BLOCKED with conflicting_type = Voter's ID",
    $mismatchPrcVoter['matched'] === false
    && $mismatchPrcVoter['conflicting_type'] === "Voter's ID"
    && $mismatchPrcVoter['reason'] === "Verification Failed: ID type does not match the uploaded document."
);

// Case 2.5a: User selected "PhilSys / National ID", but OCR detects "Land Transportation Office" (Driver's License)
$promptExampleCheck = VerificationService::verifyIdTypeMatchesDocument("PhilSys / National ID", "REPUBLIC OF THE PHILIPPINES LAND TRANSPORTATION OFFICE DRIVER'S LICENSE");
assertTest(
    "Prompt Example: User selects 'PhilSys / National ID', OCR detects 'Land Transportation Office' -> immediately REJECTED",
    $promptExampleCheck['matched'] === false 
    && $promptExampleCheck['reason'] === "Verification Failed: ID type does not match the uploaded document."
    && $promptExampleCheck['conflicting_type'] === "Driver's License"
);

// Case 2.5b: User selected "Driver's License", but uploaded PhilSys / National ID
$mismatchDlPhilSys = VerificationService::verifyIdTypeMatchesDocument("Driver's License", "REPUBLIKA NG PILIPINAS PHILSYS CARD IDENTIFICATION");
assertTest(
    "Cross-Verification: User selects 'Driver\'s License', OCR detects 'PhilSys' -> immediately REJECTED",
    $mismatchDlPhilSys['matched'] === false && $mismatchDlPhilSys['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5c: User selected "Passport", but uploaded UMID
$mismatchPassUmid = VerificationService::verifyIdTypeMatchesDocument("Passport", "REPUBLIKA NG PILIPINAS UNIFIED MULTI-PURPOSE ID UMID");
assertTest(
    "Cross-Verification: User selects 'Passport', OCR detects 'Unified Multi-Purpose ID' -> immediately REJECTED",
    $mismatchPassUmid['matched'] === false && $mismatchPassUmid['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5d: User selected "UMID", but uploaded Passport
$mismatchUmidPass = VerificationService::verifyIdTypeMatchesDocument("UMID", "REPUBLIKA NG PILIPINAS DFA PASAPORTE PASSPORT");
assertTest(
    "Cross-Verification: User selects 'UMID', OCR detects 'Pasaporte' -> immediately REJECTED",
    $mismatchUmidPass['matched'] === false && $mismatchUmidPass['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5e: User selected National ID (PhilSys), but uploaded Driver's License
$mismatchNidDl = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", $dlText);
assertTest(
    "Strict ID Type Mismatch: National ID selected, but Driver's License uploaded is REJECTED",
    $mismatchNidDl['matched'] === false && $mismatchNidDl['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5f: User selected Driver's License, but uploaded Passport
$mismatchDlPass = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $passText1);
assertTest(
    "Strict ID Type Mismatch: Driver's License selected, but Passport uploaded is REJECTED",
    $mismatchDlPass['matched'] === false && $mismatchDlPass['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5g: User selected Passport, but uploaded LTO Driver's License
$mismatchPassDl = VerificationService::verifyIdTypeMatchesDocument("Passport", $dlText);
assertTest(
    "Strict ID Type Mismatch: Passport selected, but Driver's License uploaded is REJECTED",
    $mismatchPassDl['matched'] === false && $mismatchPassDl['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5h: User selected UMID, but uploaded Driver's License
$mismatchUmidDl = VerificationService::verifyIdTypeMatchesDocument("UMID", $dlText);
assertTest(
    "Strict ID Type Mismatch: UMID selected, but Driver's License uploaded is REJECTED",
    $mismatchUmidDl['matched'] === false && $mismatchUmidDl['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5i: User selected Driver's License, but uploaded Student ID with no DL signatures
$studText = "UNIVERSITY OF THE PHILIPPINES\nSTUDENT IDENTIFICATION CARD\nNAME: JUAN DELA CRUZ\nSEMESTER: 1ST SEM";
$mismatchDlStud = VerificationService::verifyIdTypeMatchesDocument("Driver's License", $studText);
assertTest(
    "Strict ID Type Mismatch: Driver's License selected, but Student ID uploaded is REJECTED",
    $mismatchDlStud['matched'] === false && $mismatchDlStud['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// Case 2.5j: Uploaded image text has no signatures at all
$emptyTextCheck = VerificationService::verifyIdTypeMatchesDocument("National ID (PhilSys)", "RANDOM TEXT WITHOUT ANY OFFICIAL ID SIGNATURES");
assertTest(
    "Strict ID Type Mismatch: Missing signature keywords is immediately REJECTED",
    $emptyTextCheck['matched'] === false && $emptyTextCheck['reason'] === 'Verification Failed: ID type does not match the uploaded document.'
);

// -----------------------------------------------------------------------------
// 3. STRICT OCR TEXT & IDENTITY VALIDATION (Name AND Birthday Absolute Match)
// -----------------------------------------------------------------------------
$accountJuan = [
    'name' => 'Juan Dela Cruz',
    'birthday' => '1995-04-12'
];

// Case 3a: Exact Name and Exact Birthday -> Approved
$res1 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Strict match: Exact Name and Exact Birthday is approved", $res1['match'] === true && $res1['name_matched'] === true && $res1['birthday_matched'] === true);

// Case 3b: String Normalization - Uppercase, punctuation, and extra whitespace
$res2 = VerificationService::compareIdTextWithAccount(
    ['full_name' => '   JUAN   DELA   CRUZ,   ', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("String normalization handles uppercase and extra spaces", $res2['match'] === true && $res2['name_matched'] === true);

// Case 3c: Permuted tokens with comma ("DELA CRUZ, JUAN")
$res3 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'DELA CRUZ, JUAN', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Permuted name token match ('DELA CRUZ, JUAN') is approved", $res3['match'] === true && $res3['name_matched'] === true);

// Case 3d: Middle Initial variation ("JUAN M. DELA CRUZ")
$res4 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'JUAN M. DELA CRUZ', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Middle initial variation is handled gracefully", $res4['match'] === true && $res4['name_matched'] === true);

// Case 3e: User Prompt Specification - Lara Janelle Lucero (uppercase vs mixed case)
$accountLara = [
    'name' => 'Lara Janelle Lucero',
    'birthday' => '1998-11-25'
];
$resLara = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'LARA JANELLE LUCERO', 'birthday' => '1998-11-25'],
    $accountLara
);
assertTest("User Prompt: 'LARA JANELLE LUCERO' matches DB 'Lara Janelle Lucero'", $resLara['match'] === true && $resLara['name_matched'] === true);

// Case 3f: Name Matches, but Birthday is MISMATCHED -> MUST BE REJECTED with concise message!
$res5 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '1990-01-01'],
    $accountJuan
);
assertTest(
    "Strict Requirement: Name matches, but Birthday MISMATCHED is REJECTED with concise message",
    $res5['match'] === false && $res5['name_matched'] === true && $res5['birthday_matched'] === false && $res5['reason'] === 'Verification Failed: Account information does not match the ID.'
);

// Case 3g: Name Matches, but Birthday is MISSING/EMPTY -> REJECTED with concise message!
$res6 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => ''],
    $accountJuan
);
assertTest(
    "Strict Requirement: Name matches, but Birthday MISSING is REJECTED with concise message",
    $res6['match'] === false && $res6['name_matched'] === true && $res6['birthday_matched'] === false && $res6['reason'] === 'Verification Failed: Account information does not match the ID.'
);

// Case 3h: Different Person's Name with same birthday -> REJECTED with concise message!
$res7 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Maria Santos', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest(
    "Strict Requirement: Different person's name ('Maria Santos') is REJECTED with concise message",
    $res7['match'] === false && $res7['name_matched'] === false && $res7['reason'] === 'Verification Failed: Account information does not match the ID.'
);

// Case 3i: Both Name and Birthday Mismatched -> REJECTED with concise message!
$res8 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Pedro Penduko', 'birthday' => '1980-05-20'],
    $accountJuan
);
assertTest(
    "Strict Requirement: Both Name and Birthday mismatched is REJECTED with concise message",
    $res8['match'] === false && $res8['name_matched'] === false && $res8['birthday_matched'] === false && $res8['reason'] === 'Verification Failed: Account information does not match the ID.'
);

// -----------------------------------------------------------------------------
// 4. SMART NAME NORMALIZATION & FUZZY MATCHING (Requirement 1)
// -----------------------------------------------------------------------------
// 4a. Title stripping: "Mr.", "Dr.", "Atty.", "Engr."
$resTitle1 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Mr. Juan Dela Cruz', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Fuzzy Name: Strips title 'Mr.' and matches perfectly", $resTitle1['match'] === true && $resTitle1['name_matched'] === true);

$resTitle2 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Atty. Juan Dela Cruz', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Fuzzy Name: Strips title 'Atty.' and matches perfectly", $resTitle2['match'] === true && $resTitle2['name_matched'] === true);

$resTitle3 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Dr. Juan Dela Cruz Jr.', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Fuzzy Name: Strips title 'Dr.' & handles 'Jr.' suffix", $resTitle3['match'] === true && $resTitle3['name_matched'] === true);

// 4b. Minor OCR Typo: e.g. '1' or 'l' or 'I'
$resTypo1 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan De1a Cruz', 'birthday' => '1995-04-12'], // '1' instead of 'l'
    $accountJuan
);
assertTest("Fuzzy Name: Minor OCR typo ('1' for 'l' in De1a) exceeds 80% threshold and matches", $resTypo1['match'] === true && $resTypo1['name_matched'] === true && ($resTypo1['name_similarity'] ?? 0) >= 80.0);

$resTypo2 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Crvz', 'birthday' => '1995-04-12'], // 'v' instead of 'u'
    $accountJuan
);
assertTest("Fuzzy Name: Minor OCR typo ('v' for 'u' in Crvz) exceeds 80% threshold and matches", $resTypo2['match'] === true && $resTypo2['name_matched'] === true && ($resTypo2['name_similarity'] ?? 0) >= 80.0);

// 4c. Partial name / Different person: Must fail below 80% threshold
$resDiff1 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Santos', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Fuzzy Name: Different surname ('Juan Santos') scores below 80% and fails", $resDiff1['match'] === false && $resDiff1['name_matched'] === false && ($resDiff1['name_similarity'] ?? 100) < 80.0);

$resDiff2 = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Pedro Dela Cruz', 'birthday' => '1995-04-12'],
    $accountJuan
);
assertTest("Fuzzy Name: Different first name ('Pedro Dela Cruz') scores below 80% and fails", $resDiff2['match'] === false && $resDiff2['name_matched'] === false && ($resDiff2['name_similarity'] ?? 100) < 80.0);

// -----------------------------------------------------------------------------
// 5. UNIVERSAL DATE FORMAT PARSING FOR BIRTHDAY (Requirement 2)
// Guaranteed equivalence: "01/08/2006", "January 08, 2006", and "2006-01-08"
// -----------------------------------------------------------------------------
$accountBdayPrompt = [
    'name' => 'Juan Dela Cruz',
    'birthday' => '2006-01-08' // Standard Y-m-d in DB
];

// 5a. Prompt Example: "01/08/2006" matches "2006-01-08"
$resBdaySlash = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '01/08/2006'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: '01/08/2006' matches DB '2006-01-08'", $resBdaySlash['match'] === true && $resBdaySlash['birthday_matched'] === true && $resBdaySlash['standardized_id_birthday'] === '2006-01-08');

// 5b. Prompt Example: "January 08, 2006" matches "2006-01-08"
$resBdayMonthName = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => 'January 08, 2006'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: 'January 08, 2006' matches DB '2006-01-08'", $resBdayMonthName['match'] === true && $resBdayMonthName['birthday_matched'] === true && $resBdayMonthName['standardized_id_birthday'] === '2006-01-08');

// 5c. Prompt Example: "2006-01-08" matches "2006-01-08"
$resBdayIso = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '2006-01-08'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: '2006-01-08' matches DB '2006-01-08'", $resBdayIso['match'] === true && $resBdayIso['birthday_matched'] === true && $resBdayIso['standardized_id_birthday'] === '2006-01-08');

// 5d. Abbreviated month and day: "8 Jan 2006"
$resBdayDayMonth = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '8 Jan 2006'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: '8 Jan 2006' matches DB '2006-01-08'", $resBdayDayMonth['match'] === true && $resBdayDayMonth['birthday_matched'] === true && $resBdayDayMonth['standardized_id_birthday'] === '2006-01-08');

// 5e. Slash year-first format: "2006/01/08"
$resBdayYearSlash = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '2006/01/08'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: '2006/01/08' matches DB '2006-01-08'", $resBdayYearSlash['match'] === true && $resBdayYearSlash['birthday_matched'] === true && $resBdayYearSlash['standardized_id_birthday'] === '2006-01-08');

// 5f. European hyphen format: "08-01-2006"
$resBdayEuroHyphen = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '08-01-2006'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: '08-01-2006' matches DB '2006-01-08'", $resBdayEuroHyphen['match'] === true && $resBdayEuroHyphen['birthday_matched'] === true);

// 5g. Genuine birthday mismatch: "1990-05-20" vs "2006-01-08" -> Rejection
$resBdayMismatch = VerificationService::compareIdTextWithAccount(
    ['full_name' => 'Juan Dela Cruz', 'birthday' => '1990-05-20'],
    $accountBdayPrompt
);
assertTest("Universal Birthday: Genuine mismatch ('1990-05-20') is strictly rejected", $resBdayMismatch['match'] === false && $resBdayMismatch['birthday_matched'] === false);

echo "\nSummary: Passed: $pass, Failed: $fail\n";
if ($fail > 0) exit(1);
