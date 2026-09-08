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
    /** 批量读取订单详情所需附件，避免每个商品单独查询数据库。 */
    public function findMany(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return Attachment::whereIn('id', array_unique(array_filter($ids)))->get()->keyBy('id');
    }

    /**
     * 按标识查询记录。
     */
    public function find(int $id): Attachment
    {
        return Attachment::findOrFail($id);
    }

    /**
     * 创建记录。
     */
    public function create(array $data): Attachment
    {
        return Attachment::create($data);
    }

    /**
     * 按附件 ID 顺序加锁，避免并发绑定同一附件。
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
