<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 订单实体模型，对应 orders 表。
 *
 * 与 saveb-erp / saveb-source 项目 /api/order-search 接口的契约保持一致：
 *   - 前端"Order ID"        → client_order_id （或 order_id 兜底）
 *   - 前端"PayPal Order ID" → paypal_order_id
 *   - 前端"Customer Name"   → customer_name
 *   - 前端"Website"         → source_site
 *   - 前端"Customer Service" → staff_code
 *   - 前端"PayPal Account"  → receiving_paypal
 *   - 前端"Order Status"    → order_status
 *   - 前端"Date Range"      → order_time 区间
 *
 * @property int         $id
 * @property string      $order_id            系统订单号（全局唯一）
 * @property string|null $client_order_id     客户端订单号（前端"Order ID"）
 * @property string|null $paypal_order_id     PayPal 订单号（前端"PayPal Order ID"）
 * @property int         $visible_order_id    业务可见订单号
 * @property string      $entity_uuid         跨系统对账 UUID
 * @property Carbon|null $order_time          下单时间
 * @property Carbon|null $source_created_at   来源创建时间
 * @property Carbon|null $payment_time        来源付款时间
 * @property Carbon|null $completed_time      来源完成时间
 * @property Carbon|null $source_updated_at   来源更新时间
 * @property Carbon|null $legacy_accounting_time 修正前的旧业务日期
 * @property string|null $customer_name       顾客姓名
 * @property string|null $source_site         来源网站
 * @property string|null $classification      归类
 * @property string|null $influencer_name     关联达人
 * @property string|null $receiving_paypal    收款 PayPal 账号
 * @property string|null  $amount_original     原始金额
 * @property string|null $currency            原始币种
 * @property string|null  $amount_usd          折算美元金额
 * @property int         $items_count         商品件数
 * @property string|null $product_name        商品名称
 * @property string|null $order_status        订单状态
 * @property string|null $staff_code          主负责客服
 * @property array       $raw                 原始 JSON 负载
 * @property int         $version             乐观锁版本号
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property Carbon|null $deleted_at
 */
class Order extends BaseModel
{
    /** @var string 表名 */
    protected $table = 'orders';

    /** @var array<int, string> 可批量赋值字段 */
    protected $fillable = [
        'order_id',
        'client_order_id',
        'paypal_order_id',
        'visible_order_id',
        'entity_uuid',
        'order_time',
        'source_created_at',
        'payment_time',
        'completed_time',
        'source_updated_at',
        'legacy_accounting_time',
        'customer_name',
        'source_site',
        'classification',
        'influencer_name',
        'receiving_paypal',
        'amount_original',
        'currency',
        'amount_usd',
        'items_count',
        'product_name',
        'order_status',
        'staff_code',
        'raw',
        'version',
    ];

    /**
     * 字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return [
            'order_time' => 'datetime',
            'source_created_at' => 'datetime',
            'payment_time' => 'datetime',
            'completed_time' => 'datetime',
            'source_updated_at' => 'datetime',
            'legacy_accounting_time' => 'datetime',
            'amount_original' => 'decimal:2',
            'amount_usd' => 'decimal:2',
            'items_count' => 'integer',
            'visible_order_id' => 'integer',
            'version' => 'integer',
            'raw' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 模型启动钩子：自动生成 entity_uuid / visible_order_id。
     *
     * 不在数据库 DEFAULT 里完成 entity_uuid 生成是为了在应用层可控，
     * 同时也避免 PostgreSQL 在某些环境未安装 pgcrypto 时迁移失败。
     *
     * @return void 无返回值；副作用见方法说明
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (empty($order->entity_uuid)) {
                $order->entity_uuid = (string) Str::uuid();
            }
            if (empty($order->visible_order_id)) {
                // 让 PG 序列触发默认值；如果失败回退到 null（让 DB DEFAULT 接管）
                try {
                    $row = DB::selectOne("SELECT nextval('saveb_visible_order_id_seq') AS v");
                    $order->visible_order_id = (int) ($row?->v ?? 0) ?: null;
                } catch (\Throwable $exception) {
                    $order->visible_order_id = null;
                }
            }
        });
    }

    /* ─── Helpers ──────────────────────────────────────────── */
    /**
     * 把 Order 模型序列化为 API 输出格式。
     *
     * 输出字段与前端 /api/order-search 表格列严格对应：
     *   - orderId          : 客户端订单号（前端"Order ID"）
     *   - paypalOrderId    : PayPal 订单号
     *   - customerFullName : 顾客姓名
     *   - clientSite       : 来源网站
     *   - recipientPaypal  : 收款 PayPal 账号
     *   - staff            : 主负责客服
     *   - classification   : 归类
     *   - paymentStatus    : 订单状态
     *   - amount           : 原始金额（按 currency 计价）
     *   - currency         : 币种
     *   - amountUsd        : 折算美元金额
     *   - items            : 商品件数
     *   - productName      : 商品名称
     *   - influencerName   : 关联达人
     *   - createTime       : 下单时间（ISO 8601 / Asia/Shanghai）
     *   - date             : 与 createTime 同源，保留供前端 date 字段直接索引
     *
     * @param  \App\Models\Order  $order  订单模型
     * @return array 输出数组
     */
    public static function present(Order $order): array
    {
        $createTime = $order->order_time ?: $order->created_at;

        return [
            'id' => $order->id,
            'entityUuid' => $order->entity_uuid,
            'visibleOrderId' => $order->visible_order_id,
            'orderId' => $order->client_order_id ?: $order->order_id,
            'clientOrderId' => $order->client_order_id,
            'paypalOrderId' => $order->paypal_order_id,
            'customerFullName' => $order->customer_name,
            'clientSite' => $order->source_site,
            'recipientPaypal' => $order->receiving_paypal,
            'staff' => $order->staff_code,
            'classification' => $order->classification,
            'topInfluencer' => $order->influencer_name,
            'paymentStatus' => $order->order_status,
            'amount' => $order->amount_original !== null ? (float) $order->amount_original : null,
            'currency' => $order->currency,
            'amountUsd' => $order->amount_usd !== null ? (float) $order->amount_usd : null,
            'items' => (int) $order->items_count,
            'productName' => $order->product_name,
            'createTime' => $createTime?->toIso8601String(),
            'date' => $createTime?->toDateString(),
            'sourceCreatedAt' => $order->source_created_at?->toIso8601String(),
            'paymentTime' => $order->payment_time?->toIso8601String(),
            'completedTime' => $order->completed_time?->toIso8601String(),
            'sourceUpdatedAt' => $order->source_updated_at?->toIso8601String(),
            'version' => $order->version,
            'createdAt' => $order->created_at,
            'updatedAt' => $order->updated_at,
        ];
    }

    /**
     * 兼容字段名映射常量：saveb-erp /api/order-search 接口使用的 key。
     *
     * @var array<string,string>
     */
    public const SEARCH_FIELD_MAP = [
        'orderId' => 'client_order_id',
        // 前端 "Order ID"
        'paypalOrderId' => 'paypal_order_id',
        'customerName' => 'customer_name',
        'customerService' => 'staff_code',
        'staff' => 'staff_code',
        'paypalAccount' => 'receiving_paypal',
        'paypal' => 'receiving_paypal',
        'website' => 'source_site',
        'orderStatus' => 'order_status',
        'status' => 'order_status',
    ];
}
