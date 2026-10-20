<?php

declare(strict_types=1);

/**
 * One-shot Ask AI 07a patch installer for Hostinger cron.
 */
$root = getenv('HOME') . '/domains/hometrees.in/public_html/api/backend';
$log = getenv('HOME') . '/domains/hometrees.in/public_html/api/a.txt';
$zipPath = '/tmp/askai-07a.zip';
$url = 'https://codeload.github.com/Vyasmohit28/askai-07a-patch/zip/main';

$bytes = @file_put_contents($zipPath, @file_get_contents($url));
if ($bytes === false || $bytes < 100) {
    file_put_contents($log, 'download_fail');
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    file_put_contents($log, 'zip_open_fail');
    exit(1);
}

$extract = '/tmp/askai-07a-extract';
if (is_dir($extract)) {
    // best-effort cleanup
    @system('rm -rf ' . escapeshellarg($extract));
}
@mkdir($extract, 0755, true);
$zip->extractTo($extract);
$zip->close();

$src = $extract . '/askai-07a-patch-main/app';
if (! is_dir($src)) {
    file_put_contents($log, 'missing_src');
    exit(1);
}

@system('cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($root . '/'));

$marker = $root . '/app/Services/AskAi/Query/AskAiAreaResolver.php';
$ok = is_file($marker) ? filesize($marker) : 0;
file_put_contents($log, 'deployed:' . $ok);

if (is_file($root . '/artisan')) {
    @system('cd ' . escapeshellarg($root) . ' && php artisan optimize:clear');
}
