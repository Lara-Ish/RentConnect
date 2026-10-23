<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/credentials.php';

$blankJpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$tmpFile1 = tempnam(sys_get_temp_dir(), 'test_face1_') . '.jpg';
$tmpFile2 = tempnam(sys_get_temp_dir(), 'test_face2_') . '.jpg';
file_put_contents($tmpFile1, $blankJpg);
file_put_contents($tmpFile2, $blankJpg);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => FACEPP_COMPARE_URL,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS => [
        'api_key' => FACEPP_API_KEY,
        'api_secret' => FACEPP_API_SECRET,
        'image_file1' => new CURLFile($tmpFile1, 'image/jpeg', 'id.jpg'),
        'image_file2' => new CURLFile($tmpFile2, 'image/jpeg', 'live.jpg'),
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@unlink($tmpFile1);
@unlink($tmpFile2);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
