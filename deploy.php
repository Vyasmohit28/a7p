<?php

declare(strict_types=1);

$root = getenv('HOME') . '/domains/hometrees.in/public_html/api/backend';
$log = getenv('HOME') . '/domains/hometrees.in/public_html/api/a.txt';
file_put_contents($log, 'start');

$zipPath = '/tmp/askai-07a.zip';
$url = 'https://codeload.github.com/Vyasmohit28/a7p/zip/main';
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
@mkdir($extract, 0755, true);
$zip->extractTo($extract);
$zip->close();

$src = $extract . '/a7p-main/app';
if (! is_dir($src)) {
    file_put_contents($log, 'missing_src');
    exit(1);
}

$dest = $root . '/app';
$copied = askai_copy_tree($src, $dest);
$marker = $dest . '/Services/AskAi/Query/AskAiAreaResolver.php';
$ok = is_file($marker) ? filesize($marker) : 0;
file_put_contents($log, 'deployed:' . $ok . ':copied:' . $copied);

if (is_file($root . '/artisan')) {
    @exec('cd ' . escapeshellarg($root) . ' && php artisan optimize:clear');
}

function askai_copy_tree(string $src, string $dest): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (! is_dir($target)) {
                mkdir($target, 0755, true);
            }
            continue;
        }
        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (@copy($item->getPathname(), $target)) {
            $count++;
        }
    }

    return $count;
}
