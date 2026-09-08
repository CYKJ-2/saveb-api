<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 所有业务实体的基类模型。
 *
 * 提供：
 *   - 软删除（deleted_at 列）
 *   - created_at / updated_at 序列化为 Unix 时间戳，节省 payload 体积
 *   - 默认连接 pgsql（可通过子类覆盖）
 */
abstract class BaseModel extends Model
{
    use SoftDeletes;

    /** @var string 数据库连接名 */
    protected $connection = 'pgsql';

    /** @var string 主键列名 */
    protected $primaryKey = 'id';

    /** @var bool 是否启用时间戳自动维护 */
    public $timestamps = true;

    /** @var string 数据库时间戳字面量格式 */
    protected $dateFormat = 'Y-m-d H:i:s';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    public const DELETED_AT = 'deleted_at';

    /**
     * 把日期字段序列化为 Unix 时间戳整数。
     *
     * @param  DateTimeInterface  $date  Eloquent 提供的日期对象
     * @return int|string                时间戳
     */
    protected function serializeDate(DateTimeInterface $date): int|string
    {
        return $date->getTimestamp();
    }
}
