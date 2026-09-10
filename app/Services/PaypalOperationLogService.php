<?php

namespace App\Services;

use App\Dao\PaypalOperationLogDao;
use Carbon\CarbonImmutable;

/** PayPal 修改日志：列表与导出共用账号名补全、操作人及中英文展示规则。 */
class PaypalOperationLogService
{
    /**
     * 注入日志查询对象。
     *
     * @param PaypalOperationLogDao $paypalOperationLogDao 日志与关联身份数据访问对象
     * @return void 完成依赖初始化
     */
    public function __construct(private PaypalOperationLogDao $paypalOperationLogDao)
    {
    }

    /**
     * 查询修改日志当前页，默认每页 20 条。
     *
     * @param array $filters 已校验的 page、per_page 和 locale，语言默认 zh-CN
     * @return array 包含 list、total、page、per_page、last_page 的分页结果
     */
    public function page(array $filters): array
    {
        $page = $this->paypalOperationLogDao->page((int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 20));
        $context = $this->identities();

        return [
            'list' => array_map(fn ($log) => $this->present($log, $context, $filters['locale'] ?? 'zh-CN'), $page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * 导出完整日志，字段值和排序与对应语言的分页列表一致。
     *
     * @param string $locale 展示语言 zh-CN 或 en-US
     * @return iterable<array> 逐条生成的导出记录；不截取当前页
     */
    public function export(string $locale = 'zh-CN'): iterable
    {
        $context = $this->identities();
        foreach ($this->paypalOperationLogDao->all() as $log) {
            yield $this->present($log, $context, $locale);
        }
    }

    /**
     * 一次性建立账户和操作人索引，避免每条日志单独查询。
     *
     * @return array 包含 accountsById、accountsByEmail 和 operators 的身份索引
     */
    private function identities(): array
    {
        $accountsById = [];
        $accountsByEmail = [];
        foreach ($this->paypalOperationLogDao->accounts() as $account) {
            $identity = ['email' => $account->email, 'name' => trim((string) $account->account_name)];
            $accountsById[(string) $account->id] = $identity;
            $accountsByEmail[strtolower(trim($account->email))] = $identity;
        }
        $operators = [];
        foreach ($this->paypalOperationLogDao->operators() as $operator) {
            $operators[(string) $operator->id] = trim((string) $operator->display_name) ?: trim((string) $operator->username);
        }

        return compact('accountsById', 'accountsByEmail', 'operators');
    }

    /**
     * 整理单条本地或历史日志，历史快照优先，缺失账号名按邮箱补齐。
     *
     * @param \stdClass $log DAO 返回的日志 ID、时间及 JSON 快照
     * @param array $context 本次查询共用的账户和操作人身份索引
     * @param string $locale 展示语言 zh-CN 或 en-US
     * @return array 时间、字段、操作、账号名、邮箱、修改前后值、变更量和操作人的统一记录
     */
    private function present(\stdClass $log, array $context, string $locale): array
    {
        $payload = json_decode($log->payload, true) ?? [];
        $local = str_starts_with($log->id, 'local:');
        $before = $local ? ($payload['before'] ?? []) : [];
        $after = $local ? ($payload['after'] ?? []) : $payload;
        $account = $local ? ($context['accountsById'][(string) ($payload['entity_id'] ?? '')] ?? []) : [];
        $email = $this->firstText([$after['email'] ?? null, $before['email'] ?? null, $account['email'] ?? null]);
        $emailAccount = $context['accountsByEmail'][strtolower(trim($email))] ?? [];
        $accountName = $this->firstText([
            $after['accountName'] ?? null, $after['account_name'] ?? null,
            $before['accountName'] ?? null, $before['account_name'] ?? null,
            $emailAccount['name'] ?? null, $account['name'] ?? null,
        ]);
        $action = (string) ($payload['action'] ?? '');
        $field = $local ? ($action === 'review' ? 'reviews' : 'balance') : (string) ($payload['field'] ?? '');
        $review = in_array(strtolower($field), ['reviews', 'number of reviews'], true);
        $previous = $local ? ($before[$field] ?? null) : ($payload['previousValue'] ?? null);
        $current = $local ? ($after[$field] ?? ($review ? ($after['value'] ?? null) : null)) : ($payload['newValue'] ?? null);
        $delta = $local && is_numeric($previous) && is_numeric($current)
            ? round((float) $current - (float) $previous, 2) : ($payload['delta'] ?? null);
        $actorId = (string) ($payload['actor_user_id'] ?? '');
        $actor = $local ? ($context['operators'][$actorId] ?? '') : trim((string) ($payload['updatedBy'] ?? ''));
        if ($local && $actor === '' && $actorId !== '') {
            $actor = '#' . $actorId;
        }

        return [
            'id' => $log->id,
            'time' => $log->sort_time ? CarbonImmutable::parse($log->sort_time)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '',
            'field' => $this->label($field, $locale),
            'action' => $this->label($action === 'balance' ? 'correction' : $action, $locale),
            'accountName' => $accountName,
            'email' => $email,
            'previous' => $this->value($previous, $review),
            'current' => $this->value($current, $review),
            'delta' => $this->value($delta, $review),
            'actor' => $actor,
        ];
    }

    /**
     * 选取首个非空文本，空字符串快照不能阻止后续账号名补全。
     *
     * @param array $values 按优先级排列的历史快照或账户资料字段
     * @return string 第一个有效文本；全部缺失时返回空字符串
     */
    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 格式化快照值，金额保留两位小数，缺失的历史值不推算。
     *
     * @param mixed $value 历史或本地日志保存的数值或说明文本
     * @param bool $review 是否为审核次数，次数按整数显示
     * @return string 可直接用于表格和 CSV 的相同文本
     */
    private function value(mixed $value, bool $review): string
    {
        if (is_numeric($value)) {
            return number_format((float) $value, $review ? 0 : 2, '.', '');
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * 翻译旧系统与新系统的字段及操作名称，未知值保持原文。
     *
     * @param string $value 日志中的原始字段名称或操作标识
     * @param string $locale 展示语言 zh-CN 或 en-US
     * @return string 对应语言的字段或操作名称
     */
    private function label(string $value, string $locale): string
    {
        $labels = [
            'current balance' => ['当前余额', 'Current Balance'],
            'balance' => ['当前余额', 'Current Balance'],
            'number of reviews' => ['审核次数', 'Number of Reviews'],
            'reviews' => ['审核次数', 'Number of Reviews'],
            'review' => ['更新审核次数', 'Update Review Count'],
            'withdrawal' => ['提款', 'Withdrawal'],
            'correction' => ['纠正金额', 'Correction'],
            'imported initial balance' => ['导入初始余额', 'Imported Initial Balance'],
            'baseline repair' => ['修复余额基线', 'Baseline Repair'],
            'custom_account' => ['新增账号', 'Create Account'],
            'create' => ['新增账号', 'Create Account'],
        ];

        return $labels[strtolower(trim($value))][$locale === 'en-US' ? 1 : 0] ?? $value;
    }
}
