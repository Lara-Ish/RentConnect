<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/credentials.php';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => FACEPP_COMPARE_URL,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS => [
        'api_key' => FACEPP_API_KEY,
        'api_secret' => FACEPP_API_SECRET,
        'image_file1' => new CURLFile(__DIR__ . '/lena.jpg', 'image/jpeg', 'id.jpg'),
        'image_file2' => new CURLFile(__DIR__ . '/messi.jpg', 'image/jpeg', 'live.jpg'),
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
