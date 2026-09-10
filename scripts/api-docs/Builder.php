<?php

declare(strict_types=1);

namespace ApiDocs;

use Illuminate\Routing\Route;
use RuntimeException;

/** 从路由和只读源码分析构建 OpenAPI；不连接业务数据库。 */
final class Builder
{
    public array $schemas;

    private SourceInspector $inspector;

    private array $descriptions;

    public function __construct()
    {
        $this->schemas = schemas();
        $this->inspector = new SourceInspector();
        $this->descriptions = descriptions();
    }

    public function build(iterable $routes): array
    {
        $paths = [];
        $tags = [];
        $count = 0;
        foreach ($routes as $route) {
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $verb) {
                $operation = $this->operation($route, $verb);
                $paths['/' . $route->uri()][strtolower($verb)] = $operation;
                $tags[$operation['tags'][0]] = true;
                $count++;
            }
        }
        ksort($paths);

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SAVEB API 接口说明', 'version' => date('Y-m-d'),
                'description' => '根据当前 Laravel 路由、参数校验及业务返回结构整理。全部示例均为虚构，不包含真实用户、令牌或业务数据。仅说明实际已注册接口，包含从本地 XLSX 副本采集的 Analysis 采购成交价格分析。',
            ],
            'servers' => [['url' => 'http://localhost:8080', 'description' => '本地 Docker Nginx'], ['url' => '/', 'description' => '文档所在服务（同源）']],
            'tags' => array_map(fn ($name) => ['name' => $name], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => '通过 POST /api/auth/login 获取的不透明 Bearer token，不是 JWT']],
                'schemas' => $this->schemas,
            ],
            'x-route-count' => $count,
            'x-error-codes' => $this->errorCodes(),
            'x-general-notes' => [
                '除登录外的业务接口需要 Authorization: Bearer <token>。请求声明 Accept: application/json；JSON 写入使用 Content-Type: application/json，附件使用 multipart/form-data。',
                '权限代码中逗号表示全部满足（AND），竖线表示任选一个（OR）；超级管理员有效权限中的 * 通过权限门禁。部分接口在 Service 内还有操作对象保护和附件归属检查。',
                '统一成功结构为 success/code/message/data，message 默认“操作成功。”。用户分页的元数据在响应顶层；业务列表多为 data.list；日志是 data.data；采集任务是 data.items。请逐接口查看。',
                '日期通常为北京时间 YYYY-MM-DD，月份为 YYYY-MM。直接返回 BaseModel 的 created_at/updated_at 为秒级 Unix 时间戳；显式格式化的统计时间为字符串，详见字段说明。页面默认筛选不一定等于 API 默认筛选。',
                '金额统计保留两位小数；PHP 模型的 decimal 属性可能序列化成字符串。响应中金额、币种、空值的含义以字段说明为准。',
                'SystemException 使用统一失败结构；Laravel 校验失败为 message/errors，abort/HttpException 通常为 message。不能假设所有失败都包含 success/code/data。',
                '写入含 version 的业务记录时，提交最近读取的版本；409 后应重新读取并核对，不应直接覆盖。异步任务返回 202 后还需查询状态。',
                'CSV 使用 UTF-8 BOM，导出语言由 locale=zh-CN/en-US 控制，默认中文。导出不是分页列表；部分旧导出接口仍是全量目录，具体筛选范围见对应接口。',
                '响应头 X-Trace-Id 为请求追踪标识。可以通过 X-Trace-Id/X-Request-Id/X-Correlation-Id 或 trace_id 输入追踪种子，服务端会规范化为 MD5，返回值不一定与输入相同。',
            ],
        ];

        return $this->normalizeEmptySchemas($spec);
    }

    private function operation(Route $route, string $verb): array
    {
        [$class, $method] = explode('@', $route->getActionName());
        $class = ltrim($class, '\\');
        $controller = str_replace(['App\\Controllers\\', 'Controller'], '', $class);
        $contract = contract($controller, $method, $verb, $route->defaults);
        $context = ['id' => str_contains($route->uri(), '{id}') ? 1 : null, 'reprocess' => $method === 'reprocess'];
        $input = $this->inspector->request($class, $method, $context);
        if ($input['unresolved'] && empty($contract['resolvedDynamic'])) {
            throw new RuntimeException(implode("\n", $input['unresolved']));
        }
        $rules = array_replace($input['rules'], $contract['rules']);
        $isCsv = $contract['response'] === 'csv';
        $isImage = $contract['response'] === 'image';
        if ($isCsv) {
            $rules['locale'] = 'nullable|in:zh-CN,en-US';
        }
        $parameters = [];
        preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
        foreach ($matches[1] as $name) {
            $schema = $controller === 'CollectorManagement' ? ['type' => 'string', 'format' => 'uuid'] : ['type' => 'integer', 'minimum' => 1];
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $controller === 'CollectorManagement' ? '采集任务 UUID' : ($this->descriptions[$name] ?? '资源 ID'), 'schema' => $schema];
        }
        foreach ($input['query'] as $name => $default) {
            if (isset($rules[$name])) {
                continue;
            }
            $kind = in_array($name, ['page', 'per_page', 'parent_id'], true) ? 'integer' : ($name === 'active' ? 'boolean' : 'string');
            $schema = ['type' => $kind];
            if ($default !== null) {
                $schema['default'] = $default;
            }
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false,
                'description' => ($this->descriptions[$name] ?? $name) . '；此字段由代码直接读取/转换，未使用 validate 规则。', 'schema' => $schema];
        }
        $body = ['type' => 'object', 'properties' => []];
        $flat = [];
        foreach ($rules as $name => $rule) {
            $rule = is_array($rule) ? implode('|', $rule) : $rule;
            $field = $this->rule($name, $rule);
            $required = preg_match('/(?:^|\|)(required|present)(?:\||$)/', $rule) === 1 && !str_contains($rule, 'sometimes');
            $flat[] = ['name' => $name, 'required' => $required, 'rules' => $rule, 'schema' => $field];
            if ($verb === 'GET') {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => $required, 'description' => $field['description'], 'schema' => $field];
            } else {
                $this->putField($body, explode('.', $name), $field, $required);
            }
        }
        foreach ($contract['queryRules'] ?? [] as $name => $rule) {
            $schema = $this->rule($name, $rule);
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $schema['description'], 'schema' => $schema];
        }
        if (!empty($contract['bodyAnyOf'])) {
            $body['anyOf'] = $contract['bodyAnyOf'];
        }
        $middleware = $route->gatherMiddleware();
        $permission = array_values(array_filter($middleware, fn ($item) => str_starts_with($item, 'permission:')));
        $permission = array_map(fn ($item) => substr($item, 11), $permission);
        $status = in_array($controller, ['User', 'Role', 'Permission', 'Order'], true) && $method === 'store' ? 201 : 200;
        if (($controller === 'Collector' && $method === 'today') || ($controller === 'CollectorManagement' && in_array($method, ['collect', 'reprocess'], true)) || ($controller === 'Logistics' && $method === 'refresh')) {
            $status = 202;
        }
        $authenticated = in_array('auth.api', $middleware, true);
        $responses = [];
        if ($isCsv || $isImage) {
            $content = $isCsv ? ['text/csv' => ['schema' => ['type' => 'string', 'format' => 'binary']]] : array_fill_keys(['image/jpeg', 'image/png', 'image/webp', 'image/gif'], ['schema' => ['type' => 'string', 'format' => 'binary']]);
            $responses[(string) $status] = ['description' => $isCsv ? 'CSV 文件下载（UTF-8 BOM），无 JSON 包装' : '图片二进制，无 JSON 包装', 'content' => $content];
            if ($isCsv) {
                $responses[(string) $status]['headers']['Content-Disposition'] = ['schema' => ['type' => 'string'], 'description' => 'attachment，包含下载文件名'];
            }
        } else {
            $data = is_string($contract['response']) ? type($contract['response']) : $contract['response'];
            $envelope = shape('success:b:成功时为 true;code:i:成功业务码为 0;message:s:操作结果提示');
            $envelope['properties']['success']['const'] = true;
            $envelope['properties']['code']['const'] = 0;
            $envelope['properties']['data'] = $data + ['description' => '接口业务数据'];
            $envelope['properties'] += $contract['extra'];
            $envelope['required'] = array_keys($envelope['properties']);
            $responses[(string) $status] = ['description' => $status === 202 ? '任务已受理，请继续查询任务状态' : '成功', 'content' => ['application/json' => ['schema' => $envelope, 'example' => $this->example($envelope)]]];
        }
        if ($authenticated) {
            $responses['401'] = $this->error('登录令牌缺失、无效或过期', 'ApiError');
            $responses['403'] = $this->error('账户禁用、缺少权限或对象操作受保护', 'ApiError', 'HttpError');
        } else {
            $responses['401'] = $this->error('用户名或密码错误', 'ApiError');
            $responses['403'] = $this->error('账户禁用或受限', 'ApiError');
        }
        if ($matches[1]) {
            $responses['404'] = $this->error('资源或附件不存在', 'ApiError', 'HttpError');
        }
        if ($rules || $verb !== 'GET') {
            $responses['422'] = $this->error('参数校验或业务规则不通过', 'ValidationError', 'HttpError', 'ApiError');
        }
        if ($verb !== 'GET' && ($matches[1] || $controller === 'Influencer')) {
            $responses['409'] = $this->error('版本冲突或业务归属冲突', 'HttpError', 'ApiError');
        }
        if (in_array($controller, ['Collector', 'CollectorManagement', 'Logistics', 'InvoiceOcr'], true)) {
            $responses['503'] = $this->error('依赖服务未配置、不可用或超时', 'HttpError');
        }
        $node = $this->inspector->method($class, $method);
        $doc = $node->getDocComment()?->getText() ?? '';
        preg_match('/@return\s+\S+\s+([^\r\n]+)/', $doc, $returnDoc);
        $operationId = $controller . '_' . $method . '_' . strtolower($verb) . ($route->defaults ? '_' . implode('_', $route->defaults) : '');
        $operation = [
            'operationId' => str_replace('-', '_', $operationId), 'tags' => [$contract['group']],
            'summary' => $contract['title'], 'description' => implode("\n\n", $contract['notes']),
            'security' => $authenticated ? [['bearerAuth' => []]] : [],
            'parameters' => $parameters, 'responses' => $responses,
            'x-permissions' => $permission,
            'x-source' => 'app/Controllers/' . $controller . 'Controller.php:' . $node->getStartLine(),
            'x-handler' => $controller . 'Controller::' . $method,
            'x-request-fields' => $flat,
            'x-return-description' => $returnDoc[1] ?? '',
        ];
        if ($verb !== 'GET' && $body['properties']) {
            $bodyType = $controller === 'Attachment' && $method === 'upload' ? 'multipart/form-data' : 'application/json';
            $operation['requestBody'] = ['required' => !empty($body['required']) || !empty($body['anyOf']), 'content' => [$bodyType => ['schema' => $body]]];
            if ($bodyType === 'application/json') {
                $operation['requestBody']['content'][$bodyType]['example'] = $this->example($body, request: true);
            }
        }
        if ($isCsv) {
            $operation['x-export-columns'] = $this->csvColumns($class, $method);
            if ($controller === 'SaSales') {
                $operation['description'] .= "\n\n导出为多个分区：汇总、员工排行榜、Invoice 员工排行榜、渠道汇总、每日汇总、订单明细。每个分区采用独立表头。";
                $operation['x-export-sections'] = $this->salesCsvSections();
            }
        }

        return $operation;
    }

    private function error(string $message, string ...$schemas): array
    {
        return ['description' => $message, 'content' => ['application/json' => ['schema' => count($schemas) === 1 ? ref($schemas[0]) : ['anyOf' => array_map(__NAMESPACE__ . '\\ref', $schemas)]]]];
    }

    /** 将 Laravel 的字段校验规则映射为 OpenAPI 类型和约束，并保留原规则供核对。 */
    private function rule(string $name, string $rules): array
    {
        $parts = explode('|', $rules);
        $has = fn ($rule) => in_array($rule, $parts, true);
        $kind = $has('integer') ? 'integer' : ($has('numeric') ? 'number' : ($has('boolean') ? 'boolean' : ($has('array') ? 'array' : 'string')));
        $schema = ['type' => $kind, 'description' => $this->descriptions[$name] ?? throw new RuntimeException('缺少参数中文说明：' . $name), 'x-laravel-rules' => $rules];
        if ($has('array')) {
            $schema['items'] = [];
        }
        if ($has('file')) {
            $schema['format'] = 'binary';
        }
        foreach ($parts as $part) {
            [$rule, $value] = array_pad(explode(':', $part, 2), 2, null);
            if ($value === null) {
                continue;
            }
            if ($rule === 'in') {
                $schema['enum'] = array_map(fn ($value) => $kind === 'integer' ? (int) $value : $value, explode(',', $value));
            }
            if (in_array($rule, ['min', 'max', 'gt'], true) && is_numeric($value)) {
                if ($has('file')) {
                    $schema['x-max-kib'] = (int) $value;
                } else {
                    $key = match ($kind) {
                        'array' => $rule === 'min' ? 'minItems' : 'maxItems',
                        'string' => $rule === 'min' ? 'minLength' : 'maxLength',
                        default => $rule === 'gt' ? 'exclusiveMinimum' : ($rule === 'min' ? 'minimum' : 'maximum'),
                    };
                    $schema[$key] = (float) $value;
                }
            }
            if ($rule === 'lte' && is_numeric($value)) {
                $schema['maximum'] = (float) $value;
            }
            if ($rule === 'date_format') {
                if ($value === 'Y-m-d') {
                    $schema['format'] = 'date';
                } elseif ($value === 'Y-m') {
                    $schema['pattern'] = '^\\d{4}-(0[1-9]|1[0-2])$';
                }
            }
        }
        foreach (['email', 'uuid'] as $format) {
            if ($has($format)) {
                $schema['format'] = $format;
            }
        }
        if ($name === 'page' || $name === 'per_page') {
            $schema['default'] = $name === 'page' ? 1 : 20;
        }
        if ($name === 'threshold') {
            $schema['default'] = 5000;
        }
        if ($name === 'granularity') {
            $schema['default'] = 'day';
        }
        if ($has('prohibited')) {
            $schema['description'] .= '；此接口禁止传入此字段。';
        }
        if ($has('nullable')) {
            $schema['type'] = [$kind, 'null'];
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }

        return $schema;
    }

    private function putField(array &$parent, array $parts, array $field, bool $required): void
    {
        $name = array_shift($parts);
        if ($name === '*') {
            $parent['type'] = isset($parent['type']) && is_array($parent['type']) ? ['array', 'null'] : 'array';
            $parent['items'] ??= [];
            if ($parts) {
                $this->putField($parent['items'], $parts, $field, $required);
            } else {
                $parent['items'] = $field;
            }

            return;
        }
        $parent['type'] ??= 'object';
        $parent['properties'] ??= [];
        if ($parts) {
            $parent['properties'][$name] ??= [];
            $this->putField($parent['properties'][$name], $parts, $field, $required);
        } else {
            $parent['properties'][$name] = array_replace($parent['properties'][$name] ?? [], $field);
            if ($required) {
                $parent['required'] = array_values(array_unique([...($parent['required'] ?? []), $name]));
            }
        }
    }

    /** 示例全部由契约合成，绝不读取真实订单、个人信息或账户密钥。 */
    public function example(array $schema, string $key = '', int $depth = 0, bool $request = false): mixed
    {
        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }
        if (isset($schema['const'])) {
            return $schema['const'];
        }
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);
            if ($depth > 30) {
                throw new RuntimeException('示例结构递归过深：' . $name);
            }

            return $this->example($this->schemas[$name], $key, $depth + 1, $request);
        }
        if (isset($schema['anyOf'])) {
            $branch = array_values(array_filter($schema['anyOf'], fn ($item) => ($item['type'] ?? null) !== 'null'))[0] ?? $schema['anyOf'][0];
            // request body 的 anyOf 可能只声明必选字段组合，不应替换主体对象。
            if (!isset($schema['properties'])) {
                return $this->example($branch, $key, $depth, $request);
            }
        }
        if (isset($schema['allOf'])) {
            $result = [];
            foreach ($schema['allOf'] as $branch) {
                $result = array_merge($result, (array) $this->example($branch, $key, $depth, $request));
            }

            return (object) $result;
        }
        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        $kind = $schema['type'] ?? 'object';
        if (is_array($kind)) {
            $kind = $kind[0];
        }
        if ($kind === 'array') {
            if (in_array($key, ['children', 'detail', 'warnings', 'history'], true) || $depth > 18) {
                return [];
            }

            return [$this->example($schema['items'] ?? [], '', $depth + 1, $request)];
        }
        if ($kind === 'object') {
            $result = [];
            foreach ($schema['properties'] ?? [] as $name => $child) {
                if ($request && str_contains($child['x-laravel-rules'] ?? '', 'prohibited')) {
                    continue;
                }
                $result[$name] = $this->example($child, $name, $depth + 1, $request);
            }
            if (!$result && is_array($schema['additionalProperties'] ?? null)) {
                $result['example'] = $this->example($schema['additionalProperties'], '', $depth + 1, $request);
            }

            return (object) $result;
        }
        if ($kind === 'null') {
            return null;
        }
        if ($kind === 'boolean') {
            return !in_array($key, ['hidden', 'refund', 'must_change_password'], true);
        }
        if ($kind === 'integer' || $kind === 'number') {
            if (in_array($key, ['percent', 'share'], true)) {
                return 100;
            }
            if ($key === 'shareRatio' || $key === 'share_ratio') {
                return 1;
            }
            $min = $schema['minimum'] ?? ($schema['exclusiveMinimum'] ?? 0) + 1;

            return $kind === 'integer' ? (int) max(1, $min) : max(12.34, $min);
        }
        if ($key === 'entity_uuid') {
            return '11111111-1111-4111-8111-111111111111';
        }
        if (($schema['format'] ?? '') === 'date' || preg_match('/^(startDate|endDate|start|end|date|.*_date)$/', $key)) {
            return '2026-09-09';
        }
        if (($schema['format'] ?? '') === 'uuid' || preg_match('/(Uuid|jobId|JobId|requestId)$/', $key)) {
            return '11111111-1111-4111-8111-111111111111';
        }
        if (($schema['format'] ?? '') === 'email' || preg_match('/email|paypal/i', $key)) {
            return 'sample@example.com';
        }
        if (preg_match('/(At|_at|Time)$/', $key)) {
            return '2026-09-09T10:00:00+08:00';
        }
        return match ($key) {
            'token' => 'example-token-not-valid', 'username' => 'demo_user', 'password' => 'ExamplePassword123!',
            'message' => '操作成功。', 'month' => '2026-09', 'timezone' => 'Asia/Shanghai', 'locale' => 'zh-CN',
            'url', 'invoice_link' => 'https://example.com/invoice/example',
            'website', 'clientSite', 'sourceSite', 'domain' => 'example.com',
            'staffCode', 'staff_code', 'primaryStaffCode' => 'DEMO', 'sourceKey' => 'order:1',
            'role_code' => 'viewer', 'currency' => 'USD', 'src' => null,
            default => '示例',
        };
    }

    /** JSON Schema 的空对象不能被 PHP 编码成空数组；数组列表本身保持数组。 */
    private function normalizeEmptySchemas(array $value, string $parent = ''): array
    {
        foreach ($value as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            $isSchema = in_array((string) $key, ['schema', 'items', 'additionalProperties', 'properties', 'not'], true)
                || in_array($parent, ['properties', 'schemas', 'anyOf', 'allOf', 'oneOf'], true);
            $value[$key] = $child === [] && $isSchema ? new \stdClass() : $this->normalizeEmptySchemas($child, (string) $key);
        }

        return $value;
    }

    private function csvColumns(string $class, string $method): array
    {
        if ($class === 'App\\Controllers\\AnalysisController' && $method === 'export') {
            $columns = \App\Services\AnalysisService::COLUMNS;

            return array_map(static fn (string $key, array $names): array => ['field' => $key, 'zh' => $names[0], 'en' => $names[1]], array_keys($columns), array_values($columns));
        }
        $finder = new \PhpParser\NodeFinder();
        $node = $this->inspector->method($class, $method);
        foreach ($finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\StaticCall::class) as $call) {
            if ($call->class instanceof \PhpParser\Node\Name && $call->class->toString() === 'App\\Common\\CsvResponse' && $call->name->toString() === 'download') {
                try {
                    $columns = $this->inspector->value($call->args[1]->value, ['english' => true]);

                    return array_map(fn ($key, $label) => ['field' => $key, 'en' => $label, 'zh' => \App\Common\ExportHeaders::translate($label, 'zh-CN')], array_keys($columns), array_values($columns));
                } catch (RuntimeException $error) {
                    throw new RuntimeException('导出表头无法解析：' . $error->getMessage());
                }
            }
        }

        return [];
    }

    /** 多段 SA 导出顺序，与 SaSalesExportService::lines() 的 cells 字段对应。 */
    private function salesCsvSections(): array
    {
        $sections = [
            ['Summary', ['orders', 'refundOrders', 'netSales', 'totalCommission', 'averageOrderValue', 'activeDays', 'dailyAverage', 'topSeller'], ['Orders', 'Refund Orders', 'Net Sales USD', 'Total Commission USD', 'Average Order Value USD', 'Active Days', 'Daily Average USD', 'Top Seller']],
            ['Employee Ranking', ['rank', 'name', 'orders', 'refundOrders', 'netSales', 'commission', 'dailyAverage', 'activeDays'], ['Rank', 'Employee', 'Orders', 'Refund Orders', 'Net Sales USD', 'Commission USD', 'Daily Average USD', 'Active Days']],
            ['Invoice Employee Ranking', ['rank', 'name', 'orders', 'refundOrders', 'netSales', 'commission', 'dailyAverage', 'activeDays'], ['Rank', 'Employee', 'Orders', 'Refund Orders', 'Net Sales USD', 'Commission USD', 'Daily Average USD', 'Active Days']],
            ['Channel Summary', ['name', 'orders', 'sales', 'refunds', 'netSales', 'sharePercent'], ['Channel', 'Orders', 'Sales USD', 'Refunds USD', 'Net Sales USD', 'Positive Sales Share %']],
            ['Daily Summary', ['date', 'orders', 'refundOrders', 'sales', 'refunds', 'netSales'], ['Date', 'Orders', 'Refund Orders', 'Sales USD', 'Refunds USD', 'Net Sales USD']],
            ['Order Detail', ['identity', 'date', 'customer', 'amountUsd', 'staff', 'channel', 'paymentMethod', 'account', 'refund'], ['Stable Identity', 'Date Ordered', 'Customer', 'Amount USD', 'Employee', 'Channel', 'Payment Method', 'Payment Account', 'Refund']],
        ];

        return array_map(fn ($section) => [
            'title' => \App\Common\ExportHeaders::translate($section[0], 'zh-CN'),
            'columns' => array_map(fn ($field, $label) => [
                'field' => $field, 'en' => $label, 'zh' => \App\Common\ExportHeaders::translate($label, 'zh-CN'),
            ], $section[1], $section[2]),
        ], $sections);
    }

    private function errorCodes(): array
    {
        $constants = (new \ReflectionClass(\App\Common\RespDef::class))->getConstants();
        $rows = [];
        foreach ($constants as $name => $code) {
            if (str_starts_with($name, 'CODE_')) {
                $rows[] = ['code' => $code, 'name' => $name, 'message' => $constants['MSG_' . substr($name, 5)] ?? '以接口实际 message 为准'];
            }
        }

        return $rows;
    }
}
