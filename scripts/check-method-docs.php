<?php

/** 检查四层方法文档；可选传入整理前 app 目录，核验可执行代码未变化。 */
ob_start();
require __DIR__ . '/inspect-method-docs.php';
$methods = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$errors = [];
$files = [];
foreach ($methods as $method) {
    $location = $method['class'] . '::' . $method['name'];
    $doc = $method['doc'];
    $files[$method['file']] = true;
    if (!preg_match('/\*\s+[^@\s*][^\r\n]+/u', $doc)) {
        $errors[] = $location . ': 缺少方法用途说明';
    }
    foreach ($method['params'] as $parameter) {
        if (!preg_match('/@param\s+[^\r\n]+\$' . preg_quote($parameter['name'], '/') . '\s+\S+/u', $doc)) {
            $errors[] = $location . ': 缺少参数类型或含义 $' . $parameter['name'];
        }
    }
    if (!preg_match('/@return\s+[^\r\n]+\s+[\x{4e00}-\x{9fff}A-Za-z][^\r\n]*/u', $doc)) {
        $errors[] = $location . ': 缺少返回类型或说明';
    }
}

// 比较 PHP token，忽略注释和排版，仍保留字符串、运算符、方法签名和调用顺序。
$tokens = static function (string $source): array {
    $result = [];
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result[] = [$token[0], $token[0] === T_OPEN_TAG ? trim($token[1]) : $token[1]];
            }
        } else {
            $result[] = $token;
        }
    }

    return $result;
};
$checkedFiles = 0;
foreach (['Controllers', 'Services', 'Dao', 'Models'] as $directory) {
    foreach (glob(dirname(__DIR__) . '/app/' . $directory . '/*.php') as $file) {
        $current = $tokens(file_get_contents($file));
        if (isset($argv[1])) {
            $before = rtrim($argv[1], '/') . '/' . $directory . '/' . basename($file);
            if (!is_file($before) || $current !== $tokens(file_get_contents($before))) {
                $errors[] = $file . ': 可执行代码与备份不同';
            }
        }
        $checkedFiles++;
    }
}
echo json_encode([
    'methods' => count($methods),
    'documented_files' => count($files),
    'syntax_checked_files' => $checkedFiles,
    'behavior_baseline_checked' => isset($argv[1]),
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors ? 1 : 0);
