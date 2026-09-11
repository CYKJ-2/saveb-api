<?php

// 仅在迁移辅助容器中使用：/old 只读，/new 为新附件卷，/work 为私有材料目录。
// 按数据库记录的 SHA256 复制，不覆盖冲突，不跟随越出附件卷的路径或目标符号链接。
[$script, $mode, $manifestPath, $reportPath] = $argv + [null, null, null, null];
if (!in_array($mode, ['check', 'copy'], true) || !$manifestPath || !$reportPath) {
    exit(64);
}
$manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$report = ['mode' => $mode, 'files' => [], 'errors' => 0, 'copied' => 0, 'already_present' => 0];
$seen = [];
foreach ($manifest as $entry) {
    $temporary = null;
    $relative = (string) ($entry['file_path'] ?? '');
    try {
        $relative = preg_replace('#^/data/attachments/#', '', $relative);
        $hash = strtolower((string) ($entry['sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || $relative === '' || str_contains($relative, "\0")
            || str_contains($relative, '\\') || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.{0,2}(/|$)#', $relative)) {
            throw new RuntimeException('invalid_path_or_hash');
        }
        if (isset($seen[$relative]) && $seen[$relative] !== $hash) {
            throw new RuntimeException('conflicting_manifest_path');
        }
        $seen[$relative] = $hash;
        $source = realpath('/old/'.$relative);
        if (!$source || !str_starts_with($source, '/old/') || !is_file($source)) {
            throw new RuntimeException('source_missing_or_outside_volume');
        }
        if (!hash_equals($hash, hash_file('sha256', $source))) {
            throw new RuntimeException('source_hash_mismatch');
        }
        $target = '/new/'.$relative;
        $parent = '/new';
        foreach (explode('/', dirname($relative)) as $part) {
            if ($part === '.') {
                continue;
            }
            $parent .= '/'.$part;
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
                throw new RuntimeException('unsafe_target_directory');
            }
            if ($mode === 'copy' && !is_dir($parent)) {
                if (!mkdir($parent, 02770) || !chown($parent, 'www-data') || !chgrp($parent, 'www-data')) {
                    throw new RuntimeException('cannot_create_target_directory');
                }
            }
        }
        if (is_link($target)) {
            throw new RuntimeException('target_symlink');
        }
        if (file_exists($target)) {
            if (!is_file($target) || !hash_equals($hash, hash_file('sha256', $target))) {
                throw new RuntimeException('target_content_conflict');
            }
            $report['already_present']++;
            $status = 'already_present';
        } elseif ($mode === 'copy') {
            $temporary = dirname($target).'/.saveb-import-'.bin2hex(random_bytes(12));
            $in = fopen($source, 'rb');
            $out = fopen($temporary, 'xb');
            if (!$in || !$out || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('copy_failed');
            }
            fclose($in);
            fclose($out);
            if (!hash_equals($hash, hash_file('sha256', $temporary))) {
                throw new RuntimeException('source_changed_during_copy');
            }
            if (!chmod($temporary, 0660) || !chown($temporary, 'www-data') || !chgrp($temporary, 'www-data')) {
                throw new RuntimeException('cannot_set_file_permissions');
            }
            // 硬链接发布不会覆盖已存在的路径；同卷临时文件保证可用。
            if (!link($temporary, $target)) {
                throw new RuntimeException('target_appeared_during_copy');
            }
            unlink($temporary);
            $temporary = null;
            $report['copied']++;
            $status = 'copied';
        } else {
            $status = 'ready_to_copy';
        }
        $report['files'][] = ['path' => $relative, 'status' => $status];
    } catch (Throwable $error) {
        if ($temporary !== null && is_file($temporary)) {
            unlink($temporary);
        }
        $report['errors']++;
        $report['files'][] = ['path' => $relative, 'status' => 'error', 'reason' => $error->getMessage()];
    }
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['files' => count($manifest), 'errors' => $report['errors'], 'copied' => $report['copied'], 'already_present' => $report['already_present']]).PHP_EOL;
exit($report['errors'] ? 1 : 0);
