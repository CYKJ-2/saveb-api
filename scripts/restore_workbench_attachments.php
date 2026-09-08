<?php

// Recover missing files only from byte-identical inline images already imported
// with the business data. Default is a dry run; --apply writes missing files.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root = realpath(config('business.attachments_root'));
if (!$root) {
    throw new RuntimeException('Attachment root unavailable');
}
$apply = in_array('--apply', $argv, true);
$pending = [];
$recovered = 0;
$candidates = 0;
foreach (App\Models\Attachment::cursor() as $a) {
    $relative = preg_replace('#^/data/attachments/#', '', $a->file_path);
    if (!preg_match('#^\d{4}/\d{2}/[a-f0-9-]+\.(?:jpg|jpeg|png|webp)$#i', $relative)) {
        continue;
    }
    if (file_exists($root . '/' . $relative)) {
        continue;
    }
    $pending[$a->sha256][] = $relative;
}
$missing = array_sum(array_map('count', $pending));
$visit = function ($value) use (&$visit, &$pending, &$recovered, &$candidates, $root, $apply) {
    if (is_array($value)) {
        foreach ($value as $v) {
            $visit($v);
        }

return;
    }
    if (!is_string($value) || !preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s', $value, $m)) {
        return;
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false) {
        return;
    }
    $hash = hash('sha256', $bytes);
    if (!isset($pending[$hash])) {
        return;
    }
    if (!getimagesizefromstring($bytes)) {
        return;
    }
    foreach ($pending[$hash] as $relative) {
        $candidates++;
        if (!$apply) {
            continue;
        }
        $target = $root . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 02770, true);
            chgrp(dirname($dir), filegroup($root));
            chgrp($dir, filegroup($root));
            chmod(dirname($dir), 02770);
            chmod($dir, 02770);
        }
        $resolved = realpath($dir);
        if (!$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid attachment directory');
        }
        if (file_exists($target)) {
            if (!hash_equals($hash, hash_file('sha256', $target))) {
                throw new RuntimeException('Existing attachment digest mismatch');
            }
            $recovered++;
            continue;
        }
        $out = fopen($target, 'x'); // Never replace an existing original.
        if (!$out) {
            throw new RuntimeException('Cannot create attachment');
        }
        try {
            if (fwrite($out, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Incomplete attachment write');
            }
        } finally {
            fclose($out);
        }
        chgrp($target, filegroup($root));
        chmod($target, 0640);
        if (!hash_equals($hash, hash_file('sha256', $target))) {
            throw new RuntimeException('Attachment digest mismatch');
        }
        $recovered++;
    }
    unset($pending[$hash]);
};
foreach (App\Models\InvoiceOrder::withTrashed()->select(['id','raw'])->lazyById(10) as $row) {
    $visit($row->raw);
}
foreach (App\Models\Order::withTrashed()->select(['id','raw'])->lazyById(50) as $row) {
    $visit($row->raw);
}
foreach (array_slice($argv, 1) as $source) {
    if ($source !== '--apply') {
        $decoded = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $visit($decoded);
        unset($decoded);
    }
}
echo json_encode(['apply' => $apply,'missingBefore' => $missing,'recoverable' => $candidates,'restored' => $recovered,'unmatched' => array_sum(array_map('count',$pending))]) . "\n";
