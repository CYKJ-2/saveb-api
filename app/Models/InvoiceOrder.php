<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice 订单模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 历史内部主键
 * @property string|null $legacy_id 旧系统记录标识，唯一
 * @property string $order_number Invoice 订单号，唯一
 * @property string $invoice_date Invoice 日期
 * @property string|null $customer_full_name 客户全名；敏感
 * @property string|null $customer_email 客户邮箱；敏感
 * @property string|null $phone_number 电话；敏感
 * @property string|null $country 国家/地区
 * @property string|null $country_source 国家识别来源
 * @property string|null $address 地址；敏感
 * @property string|null $invoice_link Invoice 链接
 * @property string $invoice_status Invoice 状态
 * @property bool $expedited_shipping 是否加急运输
 * @property string|null $fixed_discount 固定金额折扣
 * @property string|null $percentage_discount 百分比折扣
 * @property string $gift_box 礼盒/包装状态
 * @property string $amount_usd Invoice 美元金额
 * @property string|null $recipient_paypal 收款 PayPal；敏感
 * @property int|null $created_by 创建用户（历史 bigint）
 * @property array|null $raw OCR/导入原始快照
 * @property string|null $order_date Date Ordered
 * @property int|null $invoice_screenshot_attachment_id Invoice 截图附件 ID
 * @property string $entity_uuid 新域稳定 UUID，唯一
 * @property int $version 乐观锁版本
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceStaffAllocation> $allocations
 */
class InvoiceOrder extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'invoice_orders';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return ['raw' => 'array'];
    }

    /**
     * 关联商品明细。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\InvoiceItem, $this> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    /**
     * 关联客服分摊。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\InvoiceStaffAllocation, $this> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InvoiceStaffAllocation::class, 'invoice_id');
    }
}
