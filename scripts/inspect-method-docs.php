<?php

// 离线读取源码，不启动 Laravel，也不连接数据库。
require dirname(__DIR__) . '/vendor/autoload.php';

$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$printer = new PhpParser\PrettyPrinter\Standard();
$finder = new PhpParser\NodeFinder();
$returnKeys = static function (array $nodes) use (&$returnKeys): array {
    $keys = [];
    foreach ($nodes as $node) {
        if (!$node instanceof PhpParser\Node || $node instanceof PhpParser\Node\FunctionLike) {
            continue;
        }
        if ($node instanceof PhpParser\Node\Stmt\Return_ && $node->expr instanceof PhpParser\Node\Expr\Array_) {
            foreach ($node->expr->items as $item) {
                if ($item?->key instanceof PhpParser\Node\Scalar\String_) {
                    $keys[] = $item->key->value;
                }
            }
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            $keys = [...$keys, ...$returnKeys(is_array($value) ? $value : [$value])];
        }
    }

    return array_values(array_unique($keys));
};
$result = [];
foreach (['Controllers', 'Services', 'Dao', 'Models'] as $directory) {
    foreach (glob(dirname(__DIR__) . '/app/' . $directory . '/*.php') as $file) {
        $source = file_get_contents($file);
        $ast = $parser->parse($source);
        foreach ($finder->findInstanceOf($ast, PhpParser\Node\Stmt\ClassLike::class) as $class) {
            foreach ($class->getMethods() as $method) {
                $doc = $method->getDocComment();
                $params = [];
                foreach ($method->params as $param) {
                    $params[] = [
                        'name' => $param->var->name,
                        'type' => $param->type ? $printer->prettyPrint([$param->type]) : 'mixed',
                        'default' => $param->default ? $printer->prettyPrintExpr($param->default) : null,
                        'byRef' => $param->byRef,
                    ];
                }
                $body = substr($source, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1);
                $validationRules = [];
                foreach ($finder->findInstanceOf($method->stmts ?? [], PhpParser\Node\Expr\MethodCall::class) as $call) {
                    if (!$call->name instanceof PhpParser\Node\Identifier || $call->name->toString() !== 'validate') {
                        continue;
                    }
                    $rules = $call->args[0]->value ?? null;
                    if ($rules instanceof PhpParser\Node\Expr\Array_) {
                        foreach ($rules->items as $item) {
                            if ($item?->key instanceof PhpParser\Node\Scalar\String_) {
                                $validationRules[$item->key->value] = $printer->prettyPrintExpr($item->value);
                            }
                        }
                    }
                }
                $result[] = [
                    'file' => 'app/' . $directory . '/' . basename($file),
                    'class' => (string) $class->name,
                    'name' => (string) $method->name,
                    'line' => $method->getStartLine(),
                    'start' => $method->getStartFilePos(),
                    'docStart' => $doc?->getStartFilePos(),
                    'docEnd' => $doc?->getEndFilePos(),
                    'doc' => $doc?->getText() ?? '',
                    'params' => $params,
                    'returnType' => $method->returnType ? $printer->prettyPrint([$method->returnType]) : ($method->name->toString() === '__construct' ? 'void' : 'mixed'),
                    'body' => $body,
                    'validationRules' => $validationRules,
                    'returnKeys' => $returnKeys($method->stmts ?? []),
                ];
            }
        }
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
