<?php
/**
 * Detailed Email System Diagnostics
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_service.php';

echo "=================================================================\n";
echo " EMAIL SYSTEM DEEP DIAGNOSTICS\n";
echo "=================================================================\n\n";

// 1. Check .env file directly on disk
$envPath = dirname(__DIR__) . '/.env';
echo "--- 1. .env File Status ---\n";
if (file_exists($envPath)) {
    echo "  .env file exists at: {$envPath}\n";
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (str_contains(strtolower($k), 'pass') || str_contains(strtolower($k), 'secret')) {
                $masked = strlen($v) > 4 ? substr($v, 0, 2) . str_repeat('*', strlen($v) - 4) . substr($v, -2) : '***';
                echo "  {$k} = {$masked} (Length: " . strlen($v) . ")\n";
            } else {
                echo "  {$k} = {$v}\n";
            }
        }
    }
} else {
    echo "  [WARNING] .env file DOES NOT EXIST at: {$envPath}!\n";
}
echo "\n";

// 2. Check MailConfig::get()
echo "--- 2. MailConfig::get() Evaluation ---\n";
$cfg = MailConfig::get(true);
$displayCfg = $cfg;
if (!empty($displayCfg['password'])) {
    $displayCfg['password'] = substr($displayCfg['password'], 0, 2) . '***' . substr($displayCfg['password'], -2) . ' (length ' . strlen($displayCfg['password']) . ')';
}
print_r($displayCfg);
echo "\n";

// 3. Check PHP extensions and SSL certificate settings
echo "--- 3. PHP Environment & SSL Settings ---\n";
echo "  PHP Version: " . PHP_VERSION . "\n";
echo "  OpenSSL extension loaded: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo "  openssl.cafile: " . (ini_get('openssl.cafile') ?: '(not set in php.ini)') . "\n";
echo "  curl.cainfo: " . (ini_get('curl.cainfo') ?: '(not set in php.ini)') . "\n";
echo "\n";

// 4. Test Network Reachability to Google SMTP
echo "--- 4. Network Reachability to smtp.gmail.com ---\n";
$fp587 = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 5);
if ($fp587) {
    echo "  [OK] Can connect to smtp.gmail.com on port 587 (STARTTLS).\n";
    $banner = fgets($fp587, 512);
    echo "  Banner: " . trim((string)$banner) . "\n";
    fclose($fp587);
} else {
    echo "  [FAIL] Cannot connect to smtp.gmail.com on port 587: {$errstr} ({$errno})\n";
}

$fp465 = @fsockopen('smtp.gmail.com', 465, $errno, $errstr, 5);
if ($fp465) {
    echo "  [OK] Can connect to smtp.gmail.com on port 465 (SSL/SMTPS).\n";
    fclose($fp465);
} else {
    echo "  [NOTE] Port 465 check: {$errstr} ({$errno})\n";
}
echo "\n";

// 5. Test SMTP Authentication via testSmtpConnection()
echo "--- 5. SMTP Connection & Auth Test (PHPMailer) ---\n";
$diag = EmailService::testSmtpConnection();
print_r($diag);
echo "\n";

// 6. Inspect Recent email_logs in Database
echo "--- 6. Recent email_logs in Database ---\n";
$pdo = Database::getConnection();
$logs = $pdo->query("
    SELECT id, recipient_user_id, recipient_email, template_type, status, error_message, sent_at 
    FROM email_logs 
    ORDER BY id DESC 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "  email_logs is empty.\n";
} else {
    foreach ($logs as $l) {
        echo "  [#{$l['id']}] {$l['template_type']} -> {$l['recipient_email']} (User ID: {$l['recipient_user_id']}) | Status: {$l['status']} | Time: {$l['sent_at']}\n";
        if ($l['error_message']) {
            echo "       Error: {$l['error_message']}\n";
        }
    }
}
echo "\n";

// 7. Test Attempting Actual Live Mail Send (with verbose debug output)
echo "--- 7. Live PHPMailer Handshake Test ---\n";
if (!empty($cfg['username']) && !empty($cfg['password'])) {
    echo "Attempting verbose debug handshake to Gmail SMTP...\n";
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) {
            echo "   [SMTP-DEBUG] {$str}\n";
        };
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = $cfg['auth'];
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $cfg['port'];
        $mail->Timeout = 10;
        
        $connected = $mail->smtpConnect();
        echo "   smtpConnect returned: " . ($connected ? 'TRUE' : 'FALSE') . "\n";
        $mail->smtpClose();
    } catch (Throwable $e) {
        echo "   [EXCEPTION] " . $e->getMessage() . "\n";
    }
} else {
    echo "   [SKIPPED] Cannot perform live handshake because SMTP_USER or SMTP_PASS is empty in .env.\n";
}
