<?php

namespace App\Dao;

use App\Models\Attachment;
use App\Models\InvoiceItem;
use App\Models\InvoiceOrder;

/**
 * 附件数据访问：封装模型查询与持久化操作。
 */
class AttachmentDao
{
    /**
     * 批量读取订单详情所需附件，避免每个商品单独查询数据库。
     *
     * @param  array  $ids  附件主键 ID 列表
     * @return \Illuminate\Database\Eloquent\Collection 附件查询或计算结果集合；无匹配时为空集合
     */
    public function findMany(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return Attachment::whereIn('id', array_unique(array_filter($ids)))->get()->keyBy('id');
    }

    /**
     * 按主键读取附件详情。
     *
     * @param  int  $id  附件记录主键 ID
     * @return Attachment 附件模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     */
    public function find(int $id): Attachment
    {
        return Attachment::findOrFail($id);
    }

    /**
     * 创建附件记录。
     *
     * @param  array  $data  经过 Controller 校验的业务字段
     * @return Attachment 附件模型实例
     */
    public function create(array $data): Attachment
    {
        return Attachment::create($data);
    }

    /**
     * 按附件 ID 顺序加锁，避免并发绑定同一附件。
     *
     * @param  array  $ids  附件主键 ID 列表
     * @return void 无返回值；副作用见方法说明
     */
    public function lockMany(array $ids): void
    {
        Attachment::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * 检查附件是否仍被有效 Invoice 或商品明细引用。
     *
     * @param  Attachment  $attachment  附件模型
     * @return bool 附件仍由有效 Invoice 或其商品引用时为 true
     */
    public function bound(Attachment $attachment): bool
    {
        if ($attachment->entity_type === 'invoice_screenshot') {
            return InvoiceOrder::whereKey($attachment->entity_id)
                ->where('invoice_screenshot_attachment_id', $attachment->id)
                ->exists();
        }
        if ($attachment->entity_type === 'invoice_item_image') {
            return InvoiceOrder::whereKey($attachment->entity_id)->exists() && InvoiceItem::where('invoice_id', $attachment->entity_id)
                ->where('image_attachment_id', $attachment->id)
                ->exists();
        }

        return false;
    }

    /**
     * 更新附件所属 Invoice 和用途。
     *
     * @param  Attachment  $attachment  附件模型
     * @param  string  $type  附件用途，取值须与绑定时的 entity_type 一致
     * @param  int  $invoice  目标 Invoice 订单主键 ID
     * @return void 无返回值；副作用见方法说明
     */
    public function bind(
        Attachment $attachment,
        string $type,
        int $invoice,
    ): void {
        $attachment
            ->fill([
                'entity_type' => $type,
                'entity_id' => $invoice,
            ])
            ->save();
    }
}
