<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "=== TESTING HTTP VERIFICATION API (STRICT VALIDATION, ID TYPE, & PRE-PROCESSING) ===\n\n";

$cookieJar = tempnam(sys_get_temp_dir(), 'rc_cookie_');
$baseUrl = 'http://localhost:8000';

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'tenant1'");
$stmt->execute();
if (!$stmt->fetch()) {
    $hash = password_hash('111111', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, name, email, password_hash, role, verification_status, birthday, move_in_date, is_active) VALUES ('tenant1', 'Juan Dela Cruz', 'tenant1@rentconnect.test', ?, 'tenant', 'unverified', '1995-04-12', '2026-10-01', 1)")->execute([$hash]);
}

// 1. Login as tenant1 (Juan Dela Cruz, bday: 1995-04-12)
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'tenant1',
        'password' => '111111'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$loginRes = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response HTTP Code: $loginCode\n";
echo " [PASS] Tenant1 login executed\n";

$lenaPath = __DIR__ . '/lena.jpg';
$messiPath = __DIR__ . '/messi.jpg';
$procLenaPath = __DIR__ . '/unit_proc_lena.jpg';

// TEST 1: Strict ID Type Mismatch (HTTP 422 Rejection)
// Selected "Driver's License", but document keywords represent a Student ID
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Wrong-Id-Type: 1'
    ]
]);
$res1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1 (ID Type Mismatch - HTTP $code1): $res1\n";
$data1 = json_decode((string)$res1, true);
$test1Passed = ($code1 === 422 && ($data1['verification_status'] ?? '') === 'unverified' && str_contains($data1['message'] ?? '', 'Verification Failed: ID type does not match'));
echo " [ " . ($test1Passed ? "PASS" : "FAIL") . " ] Strict ID Type validation rejects mismatched document keywords & status remains unverified\n";

// TEST 1B: National ID (PhilSys) - Selected National ID, but uploaded Driver's License (HTTP 422 Rejection)
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "National ID (PhilSys)",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Ocr-Raw: REPUBLIKA NG PILIPINAS LAND TRANSPORTATION OFFICE DRIVER\'S LICENSE JUAN DELA CRUZ 1995-04-12'
    ]
]);
$res1b = curl_exec($ch);
$code1b = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1B (National ID selected vs Driver's License uploaded - HTTP $code1b): $res1b\n";
$data1b = json_decode((string)$res1b, true);
$test1bPassed = ($code1b === 422 && ($data1b['verification_status'] ?? '') === 'unverified' && str_contains($data1b['message'] ?? '', 'Verification Failed: ID type does not match'));
echo " [ " . ($test1bPassed ? "PASS" : "FAIL") . " ] Strict ID Type validation rejects Driver's License uploaded for National ID\n";

// TEST 1B2 (User Prompt Requirement): PRC ID selected vs Voter's ID uploaded ("Commission on Elections") -> HTTP 422
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "PRC ID",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Ocr-Raw: REPUBLIKA NG PILIPINAS COMMISSION ON ELECTIONS VOTER\'S IDENTIFICATION CARD JUAN DELA CRUZ 1995-04-12'
    ]
]);
$res1b2 = curl_exec($ch);
$code1b2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1B2 (PRC ID selected vs Voter's ID uploaded - HTTP $code1b2): $res1b2\n";
$data1b2 = json_decode((string)$res1b2, true);
$test1b2Passed = ($code1b2 === 422 && ($data1b2['verification_status'] ?? '') === 'unverified' && str_contains($data1b2['message'] ?? '', 'Verification Failed: ID type does not match'));
echo " [ " . ($test1b2Passed ? "PASS" : "FAIL") . " ] Strict ID Type validation rejects Voter's ID uploaded for PRC ID\n";

// TEST 1C: National ID (PhilSys) - Valid Signatures ("Republika ng Pilipinas", "National ID", "PhilSys") -> HTTP 200
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "National ID (PhilSys)",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Ocr-Raw: REPUBLIKA NG PILIPINAS PHILSYS NATIONAL ID JUAN DELA CRUZ 1995-04-12'
    ]
]);
$res1c = curl_exec($ch);
$code1c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1C (National ID Valid Signatures - HTTP $code1c): $res1c\n";
$data1c = json_decode((string)$res1c, true);
$test1cPassed = ($code1c === 200 && ($data1c['verification_status'] ?? '') === 'verified');
echo " [ " . ($test1cPassed ? "PASS" : "FAIL") . " ] National ID (PhilSys) passes with designated signature keywords\n";

// Reset for next tests
$pdo->exec("UPDATE users SET verification_status = 'unverified', id_type = NULL, id_photo_filename = NULL, live_photo_filename = NULL, id_submitted_at = NULL, id_verified_at = NULL, verification_notes = NULL WHERE username = 'tenant1'");

// TEST 1D: Passport - Valid Signatures ("Pasaporte", "Passport") -> HTTP 200
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Passport",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Ocr-Raw: REPUBLIKA NG PILIPINAS DFA PASAPORTE PASSPORT JUAN DELA CRUZ 1995-04-12'
    ]
]);
$res1d = curl_exec($ch);
$code1d = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1D (Passport Valid Signatures - HTTP $code1d): $res1d\n";
$data1d = json_decode((string)$res1d, true);
$test1dPassed = ($code1d === 200 && ($data1d['verification_status'] ?? '') === 'verified');
echo " [ " . ($test1dPassed ? "PASS" : "FAIL") . " ] Passport passes with designated signature keywords\n";

// Reset for next tests
$pdo->exec("UPDATE users SET verification_status = 'unverified', id_type = NULL, id_photo_filename = NULL, live_photo_filename = NULL, id_submitted_at = NULL, id_verified_at = NULL, verification_notes = NULL WHERE username = 'tenant1'");

// TEST 1E: UMID - Valid Signatures ("Unified Multi-Purpose ID", "UMID") -> HTTP 200
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "UMID",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12',
        'X-Mock-Ocr-Raw: REPUBLIKA NG PILIPINAS UNIFIED MULTI-PURPOSE ID UMID JUAN DELA CRUZ 1995-04-12'
    ]
]);
$res1e = curl_exec($ch);
$code1e = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 1E (UMID Valid Signatures - HTTP $code1e): $res1e\n";
$data1e = json_decode((string)$res1e, true);
$test1ePassed = ($code1e === 200 && ($data1e['verification_status'] ?? '') === 'verified');
echo " [ " . ($test1ePassed ? "PASS" : "FAIL") . " ] UMID passes with designated signature keywords\n";

// Reset for next tests
$pdo->exec("UPDATE users SET verification_status = 'unverified', id_type = NULL, id_photo_filename = NULL, live_photo_filename = NULL, id_submitted_at = NULL, id_verified_at = NULL, verification_notes = NULL WHERE username = 'tenant1'");

// TEST 2: Strict Name Mismatch - Different person's ID (HTTP 422 Rejection)
// ID belongs to Maria Santos, tenant is Juan Dela Cruz
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Maria Santos',
        'X-Mock-Ocr-Bday: 1995-04-12'
    ]
]);
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 2 (Strict Name Mismatch - HTTP $code2): $res2\n";
$data2 = json_decode((string)$res2, true);
$test2Passed = ($code2 === 422 && ($data2['verification_status'] ?? '') === 'unverified' && str_contains($data2['message'] ?? '', 'Verification Failed: Account information does not match the ID.'));
echo " [ " . ($test2Passed ? "PASS" : "FAIL") . " ] Strict Name validation rejects different cardholder & status remains unverified\n";

// TEST 3: Strict Birthday Mismatch (HTTP 422 Rejection)
// Name matches, but ID Birthday is 1980-05-20 instead of 1995-04-12 -> MUST BE STRICTLY REJECTED!
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1980-05-20'
    ]
]);
$res3 = curl_exec($ch);
$code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 3 (Strict Birthday Mismatch - HTTP $code3): $res3\n";
$data3 = json_decode((string)$res3, true);
$test3Passed = ($code3 === 422 && ($data3['verification_status'] ?? '') === 'unverified' && str_contains($data3['message'] ?? '', 'Verification Failed: Account information does not match the ID.'));
echo " [ " . ($test3Passed ? "PASS" : "FAIL") . " ] Strict Birthday validation immediately rejects mismatched birthday & status remains unverified\n";

// TEST 4: Strict Birthday Missing (HTTP 422 Rejection)
// Name matches, but Birthday is missing -> MUST BE STRICTLY REJECTED!
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: missing'
    ]
]);
$res4 = curl_exec($ch);
$code4 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 4 (Strict Birthday Missing - HTTP $code4): $res4\n";
$data4 = json_decode((string)$res4, true);
$test4Passed = ($code4 === 422 && ($data4['verification_status'] ?? '') === 'unverified' && str_contains($data4['message'] ?? '', 'Verification Failed: Account information does not match the ID.'));
echo " [ " . ($test4Passed ? "PASS" : "FAIL") . " ] Strict Birthday validation rejects document with missing birthday & status remains unverified\n";

// TEST 5: Strict Face++ Biometrics Mismatch (HTTP 422 Rejection)
// Text matches, but webcam face (Messi) does not match ID face (Lena) -> MUST BE REJECTED!
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($messiPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12'
    ]
]);
$res5 = curl_exec($ch);
$code5 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 5 (Face++ Biometrics Mismatch - HTTP $code5): $res5\n";
$data5 = json_decode((string)$res5, true);
$test5Passed = ($code5 === 422 && ($data5['verification_status'] ?? '') === 'unverified' && str_contains($data5['message'] ?? '', 'Verification Failed: Face scan does not match the ID photo.'));
echo " [ " . ($test5Passed ? "PASS" : "FAIL") . " ] Face++ API strictly blocks verification when webcam face does not match ID & status remains unverified\n";

// TEST 6: All Valid - Full Name, Birthday, ID Type Keywords, and Face++ Biometrics Match + Client Canvas Pre-Processed Image (HTTP 200 SUCCESS)
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'id_photo_processed' => new CURLFile($procLenaPath, 'image/jpeg', 'proc_id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 1995-04-12'
    ]
]);
$res6 = curl_exec($ch);
$code6 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 6 (Complete Strict Match with Pre-Processed Image - HTTP $code6): $res6\n";
$data6 = json_decode((string)$res6, true);
$test6Passed = ($code6 === 200 && ($data6['verification_status'] ?? '') === 'verified' && ($data6['status'] ?? '') === 'success');
echo " [ " . ($test6Passed ? "PASS" : "FAIL") . " ] All strict requirements satisfied: Account successfully VERIFIED!\n";

// TEST 7: Smart Name Normalization & Fuzzy Matching via HTTP API (HTTP 200 SUCCESS)
// User sends all caps with minor OCR typo '1' for 'l' ("JUAN DE1A CRUZ")
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: JUAN DE1A CRUZ',
        'X-Mock-Ocr-Bday: 1995-04-12'
    ]
]);
$res7 = curl_exec($ch);
$code7 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 7 (Smart Fuzzy Name Match 'JUAN DE1A CRUZ' - HTTP $code7): $res7\n";
$data7 = json_decode((string)$res7, true);
$test7Passed = ($code7 === 200 && ($data7['verification_status'] ?? '') === 'verified' && ($data7['status'] ?? '') === 'success');
echo " [ " . ($test7Passed ? "PASS" : "FAIL") . " ] Smart Fuzzy Name matching succeeds with minor OCR typo & normalization\n";

// TEST 8: Universal Date Format Parsing for Birthday (Prompt Guarantee: "01/08/2006", "January 08, 2006", and "2006-01-08")
// Update tenant1 birthday in DB to 2006-01-08
$pdo->exec("UPDATE users SET birthday = '2006-01-08', verification_status = 'unverified' WHERE username = 'tenant1'");

// Test 8A: OCR returns "January 08, 2006"
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Passport",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: January 08, 2006'
    ]
]);
$res8a = curl_exec($ch);
$code8a = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 8A (Universal Birthday 'January 08, 2006' vs DB '2006-01-08' - HTTP $code8a): $res8a\n";
$data8a = json_decode((string)$res8a, true);
$test8aPassed = ($code8a === 200 && ($data8a['verification_status'] ?? '') === 'verified');
echo " [ " . ($test8aPassed ? "PASS" : "FAIL") . " ] Universal Date Parsing equates 'January 08, 2006' with '2006-01-08'\n";

// Test 8B: OCR returns "01/08/2006"
sleep(2);
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "UMID",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'id.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live.jpg'),
    ],
    CURLOPT_HTTPHEADER => [
        'X-Mock-Ocr-Name: Juan Dela Cruz',
        'X-Mock-Ocr-Bday: 01/08/2006'
    ]
]);
$res8b = curl_exec($ch);
$code8b = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nTest 8B (Universal Birthday '01/08/2006' vs DB '2006-01-08' - HTTP $code8b): $res8b\n";
$data8b = json_decode((string)$res8b, true);
$test8bPassed = ($code8b === 200 && ($data8b['verification_status'] ?? '') === 'verified');
echo " [ " . ($test8bPassed ? "PASS" : "FAIL") . " ] Universal Date Parsing equates '01/08/2006' with '2006-01-08'\n";

// Verify database status
$stmt = $pdo->query("SELECT verification_status, id_verified_at, verification_notes FROM users WHERE username = 'tenant1'");
$dbUser = $stmt->fetch();
$dbVerified = ($dbUser['verification_status'] === 'verified' && !empty($dbUser['id_verified_at']));
echo " [ " . ($dbVerified ? "PASS" : "FAIL") . " ] Database record updated to 'verified' with timestamp\n";

// Reset tenant1 to unverified for clean UI demo state
$pdo->exec("UPDATE users SET verification_status = 'unverified', birthday = '1995-04-12', id_type = NULL, id_photo_filename = NULL, live_photo_filename = NULL, id_submitted_at = NULL, id_verified_at = NULL, verification_notes = NULL WHERE username = 'tenant1'");
echo "\nTenant1 reset to unverified for clean UI demo state.\n";

@unlink($cookieJar);

$allPassed = $test1Passed && $test1bPassed && $test1cPassed && $test1dPassed && $test1ePassed && $test2Passed && $test3Passed && $test4Passed && $test5Passed && $test6Passed && $test7Passed && $test8aPassed && $test8bPassed && $dbVerified;
echo "\nOverall Result: " . ($allPassed ? "ALL HTTP API VERIFICATION TESTS PASSED!" : "FAILURES DETECTED!") . "\n";
if (!$allPassed) exit(1);
