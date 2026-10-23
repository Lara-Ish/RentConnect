<?php
/**
 * RentConnect - Verification Credentials & Configuration
 * Includes Face++ Biometric API and Dedicated OCR Configurations
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------
// 1. Face++ API Credentials (Hardcoded as specified)
// ----------------------------------------------------------------------------
define('FACEPP_API_KEY', 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb');
define('FACEPP_API_SECRET', 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29');
define('FACEPP_COMPARE_URL', 'https://api-us.faceplusplus.com/facepp/v3/compare');

// ----------------------------------------------------------------------------
// 2. Optical Character Recognition (OCR) Configuration
// ----------------------------------------------------------------------------
if (!defined('OCR_ENGINE')) {
    define('OCR_ENGINE', 'tesseract'); // Dedicated OCR (Tesseract.js frontend + Tesseract PHP/CLI backend)
}

if (!defined('TESSERACT_PATH')) {
    define('TESSERACT_PATH', getenv('TESSERACT_PATH') ?: '');
}

// Legacy constants safely defined for backward compatibility
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', '');
}
if (!defined('GEMINI_API_MODEL')) {
    define('GEMINI_API_MODEL', 'gemini-2.5-flash');
}
if (!defined('GEMINI_API_URL')) {
    define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_API_MODEL . ':generateContent');
}
