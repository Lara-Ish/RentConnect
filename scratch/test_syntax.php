<?php
$content = file_get_contents(__DIR__ . '/../tenant_portal.php');
if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $content, $m)) {
    echo 'Total script tags: ' . count($m[1]) . PHP_EOL;
    foreach ($m[1] as $i => $code) {
        $opens = substr_count($code, '{');
        $closes = substr_count($code, '}');
        echo "Script #$i: {=$opens, }=$closes" . PHP_EOL;
        if ($opens !== $closes) {
            echo "MISMATCH in Script #$i!" . PHP_EOL;
        }
    }
}
