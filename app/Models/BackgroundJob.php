<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 后台任务队列，对应 background_jobs 表。
 *
 * @property string $id 主键
 * @property string $job_type invoice_ocr / batch_export / report_refresh（CHECK）
 * @property string $status queued / processing / succeeded / failed / cancelled（CHECK）
 * @property int $progress_percent 进度，0–100
 * @property string|null $queue_job_id 队列系统任务标识
 * @property string|null $input_attachment_uuid FK → attachments.entity_uuid
 * @property string|null $input_sha256 输入文件 SHA-256
 * @property string|null $engine 处理引擎
 * @property string|null $model_version 模型版本
 * @property array $input 任务输入参数
 * @property array|null $result 任务结果
 * @property string|null $error_code 失败错误码
 * @property string|null $error_message 脱敏错误信息
 * @property string $created_by_user_uuid FK → users.entity_uuid
 * @property bool $cancel_requested 是否请求取消
 * @property int $retry_count 重试次数
 * @property \Carbon\CarbonInterface|null $started_at 开始执行时间
 * @property \Carbon\CarbonInterface|null $finished_at 结束时间
 * @property \Carbon\CarbonInterface $expires_at 任务/结果到期时间
 * @property string|null $input_fingerprint 输入去重指纹
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class BackgroundJob extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'background_jobs';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
