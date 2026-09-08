<?php

namespace App\Models;

/**
 * 附件模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 历史附件主键
 * @property string $entity_type 所属实体类型（多态标记）
 * @property int|null $entity_id 历史实体主键
 * @property int|null $owner_user_id 上传用户 ID，用于附件归属校验
 * @property string $file_path 受控存储路径；敏感
 * @property string|null $mime MIME 类型
 * @property int|null $size_bytes 文件字节数
 * @property string $sha256 文件完整性摘要
 * @property string $entity_uuid 新域稳定附件 UUID，唯一
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class Attachment extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'attachments';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];
}
