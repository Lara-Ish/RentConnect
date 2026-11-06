<?php
/**
 * RentConnect - Email & SMTP Configuration
 * Securely loads Gmail SMTP credentials from environment variables or .env file.
 */

declare(strict_types=1);

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);

                // Remove surrounding single or double quotes
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }

                // Only set if not already defined or if current env is empty
                $currentVal = getenv($key);
                if ($currentVal === false || $currentVal === '') {
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                    putenv("{$key}={$val}");
                }
            }
        }
    }
}

// Load .env from project root if present
loadEnvFile(dirname(__DIR__) . '/.env');

class MailConfig
{
    public static function get(bool $reload = false): array
    {
        if ($reload) {
            loadEnvFile(dirname(__DIR__) . '/.env');
        }

        $host       = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? ($_SERVER['SMTP_HOST'] ?? 'smtp.gmail.com'));
        $port       = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? ($_SERVER['SMTP_PORT'] ?? 587)));
        $encryption = getenv('SMTP_ENCRYPTION') ?: ($_ENV['SMTP_ENCRYPTION'] ?? ($_SERVER['SMTP_ENCRYPTION'] ?? 'tls'));
        $auth       = filter_var(getenv('SMTP_AUTH') ?: ($_ENV['SMTP_AUTH'] ?? ($_SERVER['SMTP_AUTH'] ?? true)), FILTER_VALIDATE_BOOLEAN);
        $username   = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? ($_SERVER['SMTP_USER'] ?? (getenv('GMAIL_USER') ?: ($_ENV['GMAIL_USER'] ?? ''))));
        $password   = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? ($_SERVER['SMTP_PASS'] ?? (getenv('GMAIL_APP_PASSWORD') ?: ($_ENV['GMAIL_APP_PASSWORD'] ?? ''))));
        
        $username   = trim((string)$username);
        // Strip any spaces from 16-character App Password (e.g. 'abcd efgh ijkl mnop' -> 'abcdefghijklmnop')
        $password   = trim(str_replace(' ', '', (string)$password));

        $fromEmail  = getenv('SMTP_FROM_EMAIL') ?: ($_ENV['SMTP_FROM_EMAIL'] ?? ($_SERVER['SMTP_FROM_EMAIL'] ?? ''));
        // If fromEmail is empty or default domain placeholder, use authenticated Gmail address so Google SMTP does not reject the sender
        if (!empty($username) && (empty($fromEmail) || $fromEmail === 'notifications@rentconnect.ph' || str_contains($fromEmail, '@rentconnect.ph'))) {
            $fromEmail = $username;
        } elseif (empty($fromEmail)) {
            $fromEmail = 'notifications@rentconnect.ph';
        }

        $fromName   = getenv('SMTP_FROM_NAME') ?: ($_ENV['SMTP_FROM_NAME'] ?? ($_SERVER['SMTP_FROM_NAME'] ?? 'TaraRent'));
        
        // Resolve APP_URL dynamically if not set in environment
        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? ''));
        if (empty($appUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
            $hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $appUrl = $protocol . $hostName . '/RentConnect';
        }
        $appUrl = rtrim($appUrl, '/');

        // Check whether actual Gmail SMTP credentials have been provided
        $isConfigured = !empty($username) 
            && !empty($password) 
            && !str_contains($username, 'your_email@gmail.com') 
            && !str_contains($password, 'your_16_character');

        return [
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'auth'          => $auth,
            'username'      => $username,
            'password'      => $password,
            'from_email'    => $fromEmail,
            'from_name'     => $fromName,
            'app_url'       => $appUrl,
            'is_configured' => $isConfigured,
            'mock_mode'     => !$isConfigured || filter_var(getenv('MAIL_MOCK') ?: ($_ENV['MAIL_MOCK'] ?? false), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
