<?php

declare(strict_types=1);

namespace ApiDocs;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use RuntimeException;

/** 只读解析控制器；不实例化 Controller，不调用任何业务接口或执行源码表达式。 */
final class SourceInspector
{
    private array $classes = [];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    /** 获取方法 AST，包含解析后的命名空间及原有 PHPDoc。 */
    public function method(string $class, string $method): Node\Stmt\ClassMethod
    {
        if (!isset($this->classes[$class])) {
            $file = (new ReflectionClass($class))->getFileName();
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($file));
            $traverser = new NodeTraverser(new NameResolver());
            $nodes = $traverser->traverse($nodes);
            foreach ($this->finder->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $item) {
                $this->classes[$class][$item->name->toString()] = $item;
            }
        }

        return $this->classes[$class][$method] ?? throw new RuntimeException("未找到方法：$class::$method");
    }

    /** 提取校验规则和直接读取的 query 字段；动态业务规则交由人工维护的契约补充。 */
    public function request(string $class, string $method, array $context, array $seen = []): array
    {
        if (in_array($method, $seen, true)) {
            return ['rules' => [], 'query' => [], 'unresolved' => []];
        }
        $seen[] = $method;
        $node = $this->method($class, $method);
        $result = ['rules' => [], 'query' => [], 'unresolved' => []];
        foreach ($this->finder->findInstanceOf($node->stmts, Node\Expr\MethodCall::class) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'request') {
                if ($name === 'validate') {
                    try {
                        $rules = $this->value($call->args[0]->value, $context);
                        $result['rules'] = array_replace($result['rules'], $rules);
                    } catch (RuntimeException $error) {
                        $result['unresolved'][] = "$class::$method: " . $error->getMessage();
                    }
                }
                if ($name === 'query' && isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_) {
                    $key = $call->args[0]->value->value;
                    $default = null;
                    if (isset($call->args[1])) {
                        try {
                            $default = $this->value($call->args[1]->value, $context);
                        } catch (RuntimeException) {
                        }
                    }
                    $result['query'][$key] = $default;
                }
            }
            if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
                && in_array($name, ['filters', 'dateFilters', 'submit'], true)) {
                $child = $context;
                if ($name === 'submit') {
                    $child['reprocess'] = $method === 'reprocess';
                }
                $nested = $this->request($class, $name, $child, $seen);
                foreach (['rules', 'query'] as $key) {
                    $result[$key] = array_replace($result[$key], $nested[$key]);
                }
                $result['unresolved'] = array_merge($result['unresolved'], $nested['unresolved']);
            }
        }

        return $result;
    }

    /** 白名单求值：仅支持声明式校验规则，不使用 eval，也不执行任意类方法。 */
    public function value(Node $node, array $context): mixed
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true, 'false' => false, 'null' => null,
                default => throw new RuntimeException('未支持的常量'),
            };
        }
        if ($node instanceof Node\Expr\Variable && array_key_exists($node->name, $context)) {
            return $context[$node->name];
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }
                $value = $this->value($item->value, $context);
                if ($item->unpack) {
                    $result = array_merge($result, $value);
                } elseif ($item->key === null) {
                    $result[] = $value;
                } else {
                    $result[$this->value($item->key, $context)] = $value;
                }
            }

            return $result;
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            return $this->value($node->left, $context) . $this->value($node->right, $context);
        }
        if ($node instanceof Node\Expr\BinaryOp\Plus) {
            return $this->value($node->left, $context) + $this->value($node->right, $context);
        }
        if ($node instanceof Node\Expr\Ternary) {
            return $this->value($node->cond, $context)
                ? $this->value($node->if, $context) : $this->value($node->else, $context);
        }
        if ($node instanceof Node\Expr\ClassConstFetch) {
            $name = $node->class->toString() . '::' . $node->name->toString();
            if (in_array($name, [
                'App\\Services\\ProcurementService::STATUSES',
                'App\\Services\\WarehouseService::STATUSES',
                'App\\Services\\OrderManagementService::CATEGORIES',
            ], true)) {
                return constant($name);
            }
        }
        if ($node instanceof Node\Expr\StaticCall) {
            $class = $node->class->toString();
            $method = $node->name->toString();
            if ($class === 'App\\Common\\PageResult' && $method === 'rules') {
                return \App\Common\PageResult::rules();
            }
            if ($class === 'Illuminate\\Validation\\Rule' && $method === 'in') {
                return 'in:' . implode(',', $this->value($node->args[0]->value, $context));
            }
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            $args = array_map(fn ($arg) => $this->value($arg->value, $context), $node->args);
            return match ($name) {
                'implode' => implode(...$args), 'array_keys' => array_keys(...$args),
                default => throw new RuntimeException('未支持的规则函数：' . $name),
            };
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            if ($node->name->toString() === 'toDateString') {
                return 'today';
            }
            if ($node->name->toString() === 'filled') {
                return true; // 仅用于展示“提供 startDate 时”的附加结束日期规则。
            }
        }

        throw new RuntimeException((new Standard())->prettyPrintExpr($node));
    }

    /** 输出去掉注释的方法体，供人工核对响应契约，不进入公开文档。 */
    public function body(string $class, string $method): string
    {
        return (new Standard())->prettyPrint($this->method($class, $method)->stmts);
    }
}
