<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 审计日志模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int|null $user_id 操作用户 ID
 * @property string $action 操作类型（如 user.login / order.update）
 * @property string $entity_type 实体类型
 * @property string|null $entity_id 实体 ID（支持 UUID / bigint 字符串）
 * @property string|null $ip 客户端 IP
 * @property string|null $details JSON 格式操作详情
 * @property string|null $created_at 操作时间
 */
class AuditLog extends Model
{
    protected $connection = 'pgsql';

    /** @var string 数据表名 */
    protected $table = 'audit_logs';

    public $timestamps = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
