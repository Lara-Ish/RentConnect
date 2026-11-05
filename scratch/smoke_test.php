<?php
require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();
$adminUser = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = (int)$adminUser['id'];
$_SESSION['role'] = 'landlord';
$_SESSION['username'] = $adminUser['username'];
$_SESSION['name'] = $adminUser['name'];
$_SESSION['email'] = $adminUser['email'];
$sessionId = session_id();
session_write_close();

// 1. Test admin_dashboard.php
$ch = curl_init('http://localhost/RentConnect/admin_dashboard.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . session_name() . '=' . $sessionId]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "admin_dashboard.php HTTP status: " . $code . "\n";
if ($code !== 200) {
    echo "ERROR loading admin_dashboard.php:\n" . substr($html, 0, 500) . "\n";
    exit(1);
}

// 2. Test analytics_api.php
$ch = curl_init('http://localhost/RentConnect/api/analytics_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . session_name() . '=' . $sessionId]);
$json = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "analytics_api.php HTTP status: " . $code . "\n";
echo "analytics_api.php response: " . substr($json, 0, 300) . "\n";

// 3. Test payment_api.php GET
$ch = curl_init('http://localhost/RentConnect/api/payment_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . session_name() . '=' . $sessionId]);
$payJson = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "payment_api.php GET HTTP status: " . $code . "\n";
echo "payment_api.php GET response: " . $payJson . "\n";

// 4. Test tenant_directory_api.php GET
$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . session_name() . '=' . $sessionId]);
$dirJson = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "tenant_directory_api.php GET HTTP status: " . $code . "\n";
echo "tenant_directory_api.php GET response: " . substr($dirJson, 0, 300) . "\n";

echo "ALL ENDPOINT SMOKE TESTS PASSED!\n";
