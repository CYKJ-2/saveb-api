<?php

declare(strict_types=1);

namespace ApiDocs;

/** OpenAPI 引用；字段结构由下方集中维护，避免各接口复制不同版本。 */
function ref(string $name): array
{
    return ['$ref' => '#/components/schemas/' . $name];
}

function type(string $name): array
{
    if (str_starts_with($name, '?')) {
        return ['anyOf' => [type(substr($name, 1)), ['type' => 'null']]];
    }
    if (str_starts_with($name, '[]')) {
        return ['type' => 'array', 'items' => type(substr($name, 2))];
    }
    if (str_starts_with($name, '#')) {
        return ['anyOf' => [
            ['type' => 'object', 'additionalProperties' => type(substr($name, 1))],
            ['type' => 'array', 'maxItems' => 0],
        ], 'description' => '动态键值映射；当前 PHP 实现无数据时可能返回空数组 []'];
    }

    return match ($name) {
        's' => ['type' => 'string'], 'i' => ['type' => 'integer'],
        'n' => ['type' => 'number'], 'b' => ['type' => 'boolean'],
        'null' => ['type' => 'null'], 'any' => [],
        'obj' => ['type' => 'object', 'additionalProperties' => true],
        'id' => ['type' => ['integer', 'string']],
        'money' => ['type' => ['number', 'string'], 'description' => '金额；部分模型的 decimal 转换返回两位小数字符串'],
        'date' => ['type' => 'string', 'format' => 'date'],
        'time' => ['type' => 'string', 'description' => '日期时间字符串；以接口实际时区偏移为准'],
        default => ref($name),
    };
}

/** 字段语法：字段名:类型:中文说明；前缀 ~ 表示字段可缺省，? 类型表示值可为 null。 */
function shape(string $fields, array $extra = []): array
{
    $properties = [];
    $required = [];
    foreach (explode(';', $fields) as $field) {
        if (trim($field) === '') {
            continue;
        }
        [$name, $kind, $description] = explode(':', trim($field), 3);
        if (!str_starts_with($name, '~')) {
            $required[] = $name;
        }
        $name = ltrim($name, '~');
        $properties[$name] = type($kind) + ['description' => $description];
        $properties[$name]['description'] = $description;
    }

    return ['type' => 'object', 'properties' => $properties, ...($required ? ['required' => $required] : []), ...$extra];
}

function page(string $item, string $extra = '', bool $lastPage = true): array
{
    return shape('list:[]' . $item . ':本页记录;total:i:筛选记录总数;page:i:当前页，从 1 开始;per_page:i:每页条数'
        . ($lastPage ? ';last_page:i:最后一页页码' : '') . ';' . $extra);
}

/** 与已核对的 Controller、Service、Dao 输出一致；动态历史 JSON 保留开放字段。 */
function schemas(): array
{
    $s = [];
    $s['Range'] = shape('startDate:date:开始日期，含当天;endDate:date:结束日期，含当天');
    $s['RoleBrief'] = shape('id:i:角色 ID;code:s:角色代码;name:s:英文名称;name_zh:?s:中文名称;~description:?s:角色说明;~status:i:状态，1 启用、0 禁用');
    $s['PermissionBrief'] = shape('id:i:权限 ID;code:s:权限代码;name:s:英文名称;name_zh:?s:中文名称;type:s:menu 菜单或 action 操作;action:?s:操作名称');
    $s['Permission'] = shape('id:i:权限 ID;parent_id:i:父节点，0 为根;code:s:权限代码;name:s:英文名称;name_zh:?s:中文名称;type:s:menu 或 action;path:?s:前端路径;icon:?s:图标;component:?s:组件路径;action:?s:操作名称;resource:?s:资源标识;level:i:菜单层级;is_menu_visible:b:是否可显示菜单;hidden:b:是否隐藏;sort:i:排序;status:i:1 启用、0 禁用;description:?s:英文说明;description_zh:?s:中文说明;created_at:?time:创建时间;updated_at:?time:更新时间;children:[]Permission:下级节点，平铺查询时为空数组');
    $s['AuthPermission'] = shape('id:i:权限 ID;parent_id:i:父节点;code:s:权限代码;name:s:英文名称;name_zh:?s:中文名称;type:s:menu 或 action;action:?s:操作名称;path:?s:路由;icon:?s:图标;component:?s:组件;level:i:层级;sort:i:排序;status:i:状态;hidden:b:隐藏标记;is_menu_visible:b:菜单可见标记;i18n:PermissionI18n:双语名称与说明;children:[]AuthPermission:有效下级权限树');
    $s['Bilingual'] = shape('en:?s:英文;zh:?s:中文');
    $s['PermissionI18n'] = shape('name:Bilingual:双语名称;description:Bilingual:双语说明');
    $s['RoleI18n'] = shape('code:s:角色代码;name:Bilingual:角色双语名称;description:Bilingual:双语说明');
    $s['User'] = shape('id:i:用户 ID;username:s:登录名;display_name:?s:显示名称;role:?RoleBrief:兼容主角色;staff_code:?s:员工代码;active:b:是否启用;must_change_password:b:是否要求改密;created_at:?time:创建时间;updated_at:?time:更新时间;roles:[]RoleBrief:关联角色');
    $s['AuthUser'] = shape('id:i:用户 ID;username:s:登录名;display_name:?s:显示名;role_code:s:主角色代码;role_codes:[]s:全部有效角色代码;staff_code:?s:员工代码;must_change_password:b:是否要求改密;roles_i18n:[]RoleI18n:角色双语资料');
    $s['Login'] = shape('token:s:Bearer 明文令牌，仅登录返回;expires_at:?time:到期时间;user:AuthUser:当前用户;permissions:[]AuthPermission:完整有效菜单和操作权限树');
    $s['TokenInfo'] = shape('id:i:令牌 ID;name:?s:令牌名称;last_used_at:?time:最近使用时间;expires_at:?time:过期时间');
    $s['Me'] = shape('user:AuthUser:当前用户;token:TokenInfo:令牌元数据，不包含明文;permissions:[]AuthPermission:当前有效权限树');
    $s['Role'] = shape('id:i:角色 ID;code:s:代码;name:s:英文名称;name_zh:?s:中文名称;description:?s:英文说明;description_zh:?s:中文说明;status:i:1 启用、0 禁用;is_system:b:内置角色标记;sort:i:排序;permissions:[]PermissionBrief:已分配权限;created_at:?time:创建时间;updated_at:?time:更新时间');
    $s['RolePage'] = page('Role', '', false);
    $s['UserRoles'] = shape('user_id:i:用户 ID;username:s:登录名;roles:[]RoleBrief:角色列表;~primary_role:?RoleBrief:兼容主角色，仅读取列表返回;~removed_role:RoleBrief:本次移除的角色');
    $s['UserRole'] = shape('user_id:i:用户 ID;role:?RoleBrief:主角色，没有角色时为 null;~message:s:已分配等提示');
    $s['Message'] = shape('message:s:操作结果提示');
    $s['Deleted'] = shape('deleted:b:删除成功时为 true');
    $s['Version'] = shape('id:i:记录 ID;version:i:保存后的版本，下次修改需提交此值');
    $s['Order'] = shape('id:i:订单数据库 ID;entityUuid:s:稳定 UUID;visibleOrderId:id:展示流水号;orderId:s:页面订单号;clientOrderId:?s:来源客户订单号;paypalOrderId:?s:PayPal 或来源交易编号;customerFullName:?s:顾客全名;clientSite:?s:来源网站;recipientPaypal:?s:收款 PayPal;staff:?s:客服;classification:?s:销售渠道分类;topInfluencer:?s:达人;paymentStatus:?s:订单状态;amount:?n:原币金额;currency:?s:原币币种;amountUsd:?n:USD 金额，缺汇率时可为 null;items:i:展示商品数量;productName:?s:商品名称;createTime:?time:下单时间;date:?date:业务日期;sourceCreatedAt:?time:来源创建时间;paymentTime:?time:付款时间;completedTime:?time:完成时间;sourceUpdatedAt:?time:来源更新时间;version:i:版本;createdAt:?time:本地创建时间;updatedAt:?time:本地更新时间');
    $s['Product'] = shape('name:s:商品名称;~quantity:i:商品数量;~url:s:商品链接;~image:s:图片地址;~productId:id:来源商品 ID', ['additionalProperties' => true]);
    $s['StaffShare'] = shape('staffCode:s:员工代码;shareRatio:n:分摊比例，0 至 1');
    $s['ManagedOrder'] = shape('id:id:普通订单为数字，Invoice 为 invoice:ID;kind:s:order 或 invoice;orderId:s:展示订单号;paypalOrderId:?s:与原订单管理一致的来源交易编号;customerFullName:?s:顾客全名;clientSite:?s:来源网站;classification:s:销售分类;topInfluencer:?s:达人;recipientPaypal:?s:收款账户;paymentStatus:s:统一状态;amount:?n:原币金额;amountUsd:?n:USD 金额;currency:?s:币种;items:i:商品展示数量;productName:?s:商品名称;~products:[]Product:逐件商品名称、数量及原始商品页链接 url；普通订单从采集快照提取，缺少明细时使用 productName 展示;createTime:time:下单时间;date:date:北京时间业务日期;staff:?s:参与订单的全部客服，以逗号分隔，包含主客服和协同客服;staffAllocations:[]StaffShare:客服销售分摊;~primaryStaffCode:s:主客服，普通订单返回;~sourceIdentity:s:来源去重标识，普通订单返回;version:i:并发版本', ['additionalProperties' => true]);
    $s['OrderPage'] = page('Order', 'criteria:obj:实际采用的订单筛选条件');
    $s['ManagedOrderPage'] = page('ManagedOrder');
    $s['Totals'] = shape('orders:n:订单数，客服分摊时可为小数;items:n:商品数量;amountUsd:n:USD 销售额;missingRates:i:缺失汇率的订单数');
    $s['OverviewTotals'] = shape('orders:n:完成的普通订单数;items:n:商品数量;amountUsd:n:USD 销售额;missingRates:i:缺失汇率数量;averageOrderValue:n:已知金额订单的客单价 USD');
    $s['StatisticsGroup'] = shape('key:s:分组键，可为日期、月份、币种、渠道、达人或员工;orders:n:订单数或分摊订单数;items:n:商品数量或分摊数量;amountUsd:n:USD 金额;~amountOriginal:n:原币金额，非同币种分组不适合直接比较;~series:#n:渠道代码到 USD 金额;~orderSeries:#n:渠道代码到订单数;share:n:销售额占比，百分数');
    $s['Statistics'] = shape('list:[]StatisticsGroup:统计分组;totals:Totals:筛选范围汇总');
    $s['DashboardOverview'] = shape('current:OverviewTotals:本期，排除 Invoice;previous:OverviewTotals:上一等长周期;previousRange:Range:比较周期;changes:#?n:orders/items/amountUsd/averageOrderValue 的变化百分比，上一期为零时 null;statuses:#i:全部来源按订单状态计数;allOrders:i:全部来源订单数');
    $s['RecentOrders'] = shape('list:[]RecentOrder:最多十条最近订单;total:i:筛选范围订单总数');
    $s['RecentOrder'] = shape('id:id:记录 ID;kind:s:订单来源;orderId:s:订单号;customerFullName:?s:客户;clientSite:?s:来源;classification:s:销售分类;staff:?s:客服;amountUsd:?n:USD 金额;currency:?s:币种;items:i:数量;createTime:time:下单时间;paymentStatus:s:状态');
    $s['DashboardPaypalRow'] = shape('email:s:收款邮箱;accountName:?s:账户名;orders:i:收款订单数;received:n:期间收款 USD;withdrawn:n:期间提款 USD');
    $s['DashboardPaypal'] = shape('list:[]DashboardPaypalRow:最多十个账户;activeAccounts:i:启用账户数;periodAccounts:i:本期涉及账户数;received:n:本期收款 USD;withdrawn:n:本期提款 USD');
    $s['Rate'] = shape('currency:s:币种;rateToUsd:n:换算至 USD 的系数;effectiveDate:date:生效日期');
    $s['Rates'] = shape('list:[]Rate:截至筛选结束日的有效汇率;asOf:date:查询截止日');
    $s['DataStatus'] = shape('firstDate:?date:最早业务日期;dataThrough:?date:最新业务日期;lastDatabaseUpdate:?time:最近数据库更新时间;orderRecords:i:订单记录数;invoiceRecords:i:Invoice 记录数;collectorState:null:兼容占位字段;backfillProgress:null:兼容占位字段');
    $s['Spreadsheet'] = shape('id:i:记录 ID;~source_key:s:来源键;department:s:部门代码;provider:s:协作平台;title_zh:s:中文标题;title_en:s:英文标题;description_zh:?s:中文说明;description_en:?s:英文说明;url:s:协作页面 HTTPS 地址');
    $s['Spreadsheets'] = shape('list:[]Spreadsheet:启用协作页面');
    $s['Image'] = shape('id:?i:附件 ID;src:?s:可直接显示的图片 Base64 Data URL，没有图片时为 null;status:s:ready/empty/missing/forbidden/unavailable/integrity_failed');
    $s['Image']['example'] = ['id' => null, 'src' => null, 'status' => 'empty'];
    // 原生模型字段根据既有 @property 注释提取；关系及详情专属字段显式声明。
    foreach (['InvoiceOrder', 'InvoiceItem', 'InvoiceStaffAllocation', 'InfluencerDomain'] as $model) {
        $class = 'App\\Models\\' . $model;
        $doc = (new \ReflectionClass($class))->getDocComment() ?: '';
        $fields = [];
        preg_match_all('/@property(?:-read)?\s+([^\s]+)\s+\$(\w+)\s+([^\r\n]*)/', $doc, $matches, PREG_SET_ORDER);
        foreach ($matches as $row) {
            if (in_array($row[2], ['raw', 'metadata', 'items', 'allocations', 'creator'], true)) {
                continue;
            }
            $phpType = $row[1];
            $kind = str_contains($phpType, 'int') ? 'i' : (str_contains($phpType, 'bool') ? 'b' : (str_contains($phpType, 'array') ? 'any' : 's'));
            if (in_array($row[2], ['created_at', 'updated_at', 'deleted_at'], true)) {
                $kind = 'i'; // BaseModel::serializeDate() 返回秒级 Unix 时间戳。
                $row[3] .= '，Unix 时间戳（秒）';
            }
            $nullable = str_contains($phpType, 'null') || str_starts_with($phpType, '?');
            $fields[] = '~' . $row[2] . ':' . ($nullable ? '?' : '') . $kind . ':' . trim($row[3]);
        }
        $s[$model] = shape(implode(';', $fields));
        foreach (['amount_usd', 'price', 'fixed_discount', 'percentage_discount', 'commission_percent', 'share_ratio'] as $money) {
            if (isset($s[$model]['properties'][$money])) {
                $s[$model]['properties'][$money]['example'] = $money === 'share_ratio' ? '1.0000' : '12.34';
            }
        }
        foreach (['created_at', 'updated_at'] as $time) {
            if (isset($s[$model]['properties'][$time])) {
                $s[$model]['properties'][$time]['example'] = 1788919200;
            }
        }
        if (isset($s[$model]['properties']['deleted_at'])) {
            $s[$model]['properties']['deleted_at']['example'] = null;
        }
    }
    $s['InvoiceOrder']['properties']['invoice_date']['description'] = '添加日期 YYYY-MM-DD，编辑保留原值';
    $s['InvoiceOrder']['properties']['order_date']['description'] = '订单业务日期 YYYY-MM-DD';
    $s['InvoiceOrder']['properties']['invoice_status']['example'] = 'Paid';
    $s['InvoiceOrder']['properties']['gift_box']['example'] = 'Has';
    $s['InvoiceOrder']['properties'] += [
        'items' => type('[]InvoiceItem') + ['description' => 'Invoice 商品明细'],
        'allocations' => type('[]InvoiceStaffAllocation') + ['description' => '客服分摊；share_ratio 为 0 至 1'],
    ];
    $s['InvoiceDetailItem'] = $s['InvoiceItem'];
    $s['InvoiceDetailItem']['properties']['image'] = ref('Image') + ['description' => '商品图片信息，直接显示 src'];
    $s['InvoiceDetail'] = $s['InvoiceOrder'];
    $s['InvoiceDetail']['properties']['items'] = type('[]InvoiceDetailItem') + ['description' => '商品明细及各商品图片'];
    $s['InvoiceDetail']['properties']['invoice_screenshot_image'] = ref('Image') + ['description' => '截图图片信息，直接显示 src，无需再次请求附件接口'];
    $s['InvoicePage'] = page('InvoiceOrder');
    $s['InvoiceOptions'] = shape('staffCodes:[]s:客服代码;ratesToUsd:#n:币种到 USD 换算系数');
    $s['ParsedItem'] = shape('product_name:s:产品名称;description:s:Invoice 原始产品名称;quantity:i:Invoice 数量;~price:n:成功识别的 USD 价格；非 USD 或未识别时缺省;~notes:s:备注');
    $s['ParsedFields'] = shape('~customer_full_name:s:识别出的收件人;~customer_email:s:客户邮箱;~recipient_paypal:s:收款邮箱;~phone_number:s:电话;~country:s:国家;~address:s:地址;~order_date:date:订单日期;~invoice_link:s:Invoice 链接;~amount_usd:n:USD 金额;~invoice_status:s:识别的付款状态;~items:[]ParsedItem:全部商品，前端须逐行填入;~expedited_shipping:b:加急配送;~gift_box:s:Has 或 None;~fixed_discount:n:固定优惠;~percentage_discount:n:百分比优惠', ['additionalProperties' => true]);
    $s['ParsedInvoice'] = shape('fields:ParsedFields:可以回填表单的字段，未识别的字段不返回;currency:?s:识别的币种;sourceAmount:?n:原币金额;paymentStatus:?s:付款状态;warnings:[]s:例如 currency_conversion_required');
    $s['OcrBlock'] = shape('text:s:识别文字;~confidence:n:置信度;~box:any:文字框坐标', ['additionalProperties' => true]);
    $s['Ocr'] = shape('fields:ParsedFields:可回填的表单字段;currency:?s:币种;sourceAmount:?n:原币金额;paymentStatus:?s:付款状态;warnings:[]s:识别提示;text:s:完整识别文字;suggestions:obj:旧调用方兼容建议，email/date/amountUsd;blocks:[]OcrBlock:OCR 文字块;engine:s:使用的识别引擎');
    $s['Attachment'] = shape('id:i:上传附件 ID，用于识别或绑定订单;mime:s:实际图片 MIME 类型');
    $s['Bounds'] = shape('refreshedAt:?time:数据刷新时间;firstDate:?date:最早业务日期;dataThrough:?date:最新业务日期');
    $s['SalesMetrics'] = shape('orders:i:成交订单数;refundOrders:i:退款订单数;positiveSales:n:成交销售额 USD;refundAmount:n:退款额 USD;netSales:n:净销售额 USD;totalCommission:n:提成 USD;activeDays:i:有业务的天数;dailyAverage:n:日均 USD;averageOrderValue:n:客单价 USD;topSeller:s:销售第一的员工;missingRates:i:缺汇率订单数');
    $s['SalesDimension'] = shape('name:s:维度名称;orders:i:成交订单数;refundOrders:i:退款订单数;sales:n:成交销售额 USD;refunds:n:退款额 USD;netSales:n:净销售额 USD;sharePercent:n:成交销售占比百分数');
    $s['SalesEmployee'] = shape('name:s:员工代码，Unassigned 表示未分配;orders:i:参与成交订单数;refundOrders:i:参与退款订单数;sales:n:分摊销售额 USD;refunds:n:分摊退款 USD;netSales:n:分摊净销售 USD;rank:i:排名;activeDays:i:活跃日期数;commission:n:提成金额 USD;dailyAverage:n:活跃日均净销售 USD;relativePercent:n:相对最高员工净销售的百分比');
    $s['SalesDaily'] = shape('date:date:业务日期;orders:i:成交订单数;refundOrders:i:退款订单数;sales:n:成交金额 USD;refunds:n:退款 USD;netSales:n:净销售 USD');
    $s['SalesDetail'] = shape('id:s:组合记录 ID;identity:s:来源稳定标识;date:date:日期;orderId:s:订单号;customer:?s:客户;amountUsd:?n:净额 USD，退款为负;website:?s:来源网站;classification:s:销售分类;staff:s:参与客服;channel:s:销售渠道;paymentMethod:s:支付方式;account:s:收款账户;status:s:状态;staffAllocations:[]StaffShare:销售分摊;refund:b:是否退款');
    $s['SalesBreakdowns'] = shape('channel:[]SalesDimension:渠道;employee:[]SalesEmployee:员工;paymentMethod:[]SalesDimension:支付方式;paymentAccount:[]SalesDimension:收款账户');
    $s['SalesSummary'] = shape('metrics:SalesMetrics:汇总指标;employees:[]SalesEmployee:员工排行榜;channels:[]SalesDimension:渠道统计;daily:[]SalesDaily:每日趋势;paymentMethods:[]SalesDimension:支付方式统计;paymentAccounts:[]SalesDimension:账户统计;breakdowns:SalesBreakdowns:同组统计的兼容组织形式;detail:[]SalesDetail:当前 report 接口固定为空数组，明细需独立请求');
    $s['SalesReport'] = $s['SalesSummary'];
    $s['SalesReport']['properties'] += shape('invoiceSales:SalesSummary:Invoice 独立销售统计;source:Bounds:数据覆盖信息;range:Range:查询日期')['properties'];
    $s['SalesOrders'] = page('SalesDetail', 'totalAmount:n:全部筛选订单的净金额 USD');
    $s['Procurement'] = shape('id:?i:采购任务 ID，未创建任务时为 null;sourceKey:s:来源键，order:ID / invoice:ID / task:ID;orderId:s:订单号;~customer:?s:客户;date:date:日期;~site:?s:来源网站;~amount:?money:USD 金额;~amountOriginal:?n:原币金额;~currency:?s:币种;~createTime:time:下单时间;~paypalOrderId:?s:PayPal 订单号;productName:s:商品名称;quantity:i:数量;purchaseStatus:s:采购状态;version:i:任务版本，来源候选为 0;products:[]Product:商品明细;~supplier:?s:供应商;~cost:?money:采购成本;~eta:?s:预计到货日期;~trackingNumber:?s:运单号;~trackingCarrier:?s:承运商;~trackingPhone:?s:物流电话;~notes:?s:备注;~warehouseId:?i:仓库记录 ID;~deliveryStatus:s:物流状态', ['additionalProperties' => true]);
    $s['ProcurementPage'] = page('Procurement');
    $s['WarehouseItem'] = shape('name:s:商品名称;quantity:i:商品数量;~inspection:s:pending/passed/failed;~shippedQuantity:i:已发货数量;~outboundTracking:s:出库运单;~notes:s:商品备注', ['additionalProperties' => true]);
    $s['WarehouseHistory'] = shape('at:time:操作时间;actor:i:操作用户 ID;status:s:操作后状态;notes:s:操作备注');
    $s['Warehouse'] = shape('id:i:仓库记录 ID;version:i:版本;orderId:s:订单号;customer:s:客户;productName:s:商品名称;supplier:?s:供应商;trackingNumber:?s:入库运单;date:date:业务日期;status:s:履约状态;items:[]WarehouseItem:商品履约信息;history:[]WarehouseHistory:操作历史');
    $s['WarehousePage'] = page('Warehouse', 'statuses:#i:状态计数，不受 status 筛选影响;allTotal:i:全部状态记录数');
    $s['InfluencerDomainRow'] = shape('id:?i:已登记域名 ID，旧规则为 null;domain:s:来源域名;confirmed:b:是否确认;updatedAt:?time:更新时间');
    $s['InfluencerGroup'] = shape('name:s:达人名称;tier:s:top/mid 或空字符串;domains:[]InfluencerDomainRow:该达人的完整网站列表');
    $s['InfluencerDirectory'] = page('InfluencerGroup', 'summary:InfluencerDirectorySummary:全部达人目录汇总，不受关键词影响');
    $s['InfluencerDirectorySummary'] = shape('influencers:i:达人总数;websites:i:网站总数;updatedAt:?time:最近更新时间');
    $s['InfluencerSales'] = shape('name:s:达人;amountUsd:n:完成订单 USD 销售额;orders:i:订单数;items:n:商品展示数量;sampleQuantity:any:历史样品数量，未维护时 null;sampleValue:?money:样品金额，未维护时 null;commissionAmount:?money:历史提成金额，未维护时 null');
    $s['InfluencerSalesPage'] = page('InfluencerSales');
    $s['InfluencerReport'] = page('InfluencerSales', 'month:s:查询月份 YYYY-MM;totals:InfluencerTotals:整月汇总;meta:obj:数据更新时间及 queriedAt;chart:[]InfluencerSales:前 30 名图表数据，不受分页影响');
    $s['InfluencerTotals'] = shape('amountUsd:n:USD 金额;orders:i:订单数;items:n:商品数量');
    $s['PaypalAccount'] = shape('id:i:账户 ID;email:s:收款邮箱;accountName:s:账户名称;addedDate:?s:账户创建日期;version:i:修改版本;balance:n:监控计算余额 USD;received:n:累计入账 USD;withdrawn:n:累计提款 USD;reviews:i:审核次数;latestIncomingAt:s:最近入账订单时间，没有记录时空字符串;updatedAt:?time:余额基线更新时间');
    $s['PaypalPage'] = page('PaypalAccount', 'summary:PaypalSummary:关键词筛选后的汇总，不仅本页');
    $s['PaypalOperationLog'] = shape('id:s:稳定日志键，local:ID 或 legacy:序号;time:s:北京时间 YYYY-MM-DD HH:mm:ss，历史缺失时为空;field:s:按 locale 翻译的字段名称;action:s:按 locale 翻译的操作名称;accountName:s:优先历史快照，缺失时按邮箱或账户 ID 补全账号名;email:s:PayPal 邮箱;previous:s:修改前快照，金额两位小数、审核次数整数，历史缺失为空;current:s:修改后快照，与导出一致;delta:s:变更量，与导出一致，未知为空;actor:s:历史修改人或本地用户姓名，缺少姓名时显示登录账号或用户 ID');
    $s['PaypalOperationLogPage'] = page('PaypalOperationLog');
    $s['PaypalSummary'] = shape('accounts:i:账户数;balance:n:余额总计 USD;aboveThreshold:i:达到 threshold 的账户数');
    $s['PaypalOrder'] = shape('id:id:数据库 ID 或 legacy:日期:序号;orderId:s:订单号;clientOrderId:s:来源客户订单号;paypalOrderId:s:来源交易编号;customerFullName:?s:顾客姓名;clientSite:?s:来源网站;classification:?s:销售分类或旧渠道名称;paymentStatus:?s:订单状态;recipientPaypal:s:收款邮箱;amountUsd:n:USD 金额;amount:n:原币金额;currency:?s:币种;createTime:s:下单时间，统一为可解析的日期时间字符串;date:s:用于入账归集的日期');
    $s['PaypalOrders'] = page('PaypalOrder');
    $s['Withdrawal'] = shape('id:id:本地或历史来源 ID;date:s:提款日期 YYYY-MM-DD，历史累计占位可为空;accountName:s:账户名;email:s:邮箱;amount:n:提款 USD;source:?s:来源/备注;~imported:b:历史累计占位标记', ['additionalProperties' => true]);
    $s['WithdrawalPage'] = page('Withdrawal', 'count:i:全部筛选条数;amount:n:全部筛选提款金额 USD');
    $s['WithdrawalPeriod'] = shape('period:s:YYYY-MM-DD 或 YYYY-MM;amount:n:该日或月的提款金额 USD');
    $s['Department'] = shape('code:s:部门代码;name_zh:s:中文名;name_en:s:英文名;count:i:全部目录中的部门数量');
    $s['Operations'] = shape('rows:[]Spreadsheet:筛选后的工作巡查入口;departments:[]Department:部门选项及计数;total:i:全部有效入口数;matched:i:筛选匹配数');
    $s['CollectorStatus'] = shape('state:s:unknown/running/failed/stale/success;lastSuccessAt:?time:最近成功时间;lastError:?s:最近错误;currentDate:date:北京时间今天;nextAttemptAt:?time:下次调度时间;staleWarningMinutes:i:过期提醒分钟;intervalMinutes:i:采集间隔分钟;schedulerHealthy:b:调度器是否正常;schedulerLastAttemptAt:?time:上次调度检查;activeJobId:?s:运行任务 ID;jobStatus:?s:任务状态;lastSuccessJobId:?s:上次成功任务 ID;completedChunks:i:完成分片数;totalChunks:i:总分片数;lastProgressAt:?time:最近进展时间;canTrigger:b:是否已配置手动触发能力，仍需接口权限');
    $s['CollectorSchedule'] = shape('intervalMinutes:i:自动采集间隔，默认 30;nextRunAt:?time:下次运行时间;updatedAt:?time:更新时间;updatedBy:?s:修改者来源标识');
    $s['CollectorJob'] = shape('jobId:s:任务 UUID;mode:s:today/history/missing/reprocess 等模式;status:s:队列执行状态;actor:s:触发者来源;error:?s:任务错误;params:obj:start/end/source_job_id/dry_run 等任务参数;publication:s:preview/saveb-api/shadow;createdAt:?time:创建时间;completedAt:?time:终态更新时间;total:i:总分片数;completed:i:成功分片数');
    $s['CollectorChunk'] = shape('id:i:分片 ID;scope:obj:范围，day 或 order_id 等;status:s:执行状态;attempts:i:尝试次数;error:?s:错误;counts:#i:处理结果计数;committed_at:?time:提交时间');
    $s['CollectorJobPage'] = shape('items:[]CollectorJob:当前页任务;total:i:任务总数;page:i:当前页;pageSize:i:每页条数');
    $s['CollectorChunkPage'] = shape('items:[]CollectorChunk:当前页分片;total:i:分片总数;page:i:当前页;pageSize:i:每页条数');
    $s['CollectorDetail'] = $s['CollectorJob'];
    $s['CollectorDetail']['properties']['chunks'] = ref('CollectorChunkPage') + ['description' => '任务分片分页'];
    $s['CollectorAccepted'] = shape('jobId:s:任务 UUID;status:s:当前队列状态;date:date:本次采集日期');
    $s['LogisticsJob'] = shape('jobId:s:物流任务 UUID;id:s:任务 UUID;status:s:任务状态;error:?s:错误;created_at:?time:创建时间;updated_at:?time:更新时间;total:i:总分片数;completed:i:完成分片数;failed:i:失败分片数;updated:i:更新任务数量;skipped:i:跳过任务数量');
    $s['LogisticsStatus'] = shape('configured:b:是否配置物流服务;provider:?s:物流提供方;autoEnabled:b:是否自动查询;intervalMinutes:i:自动查询间隔分钟;batchSize:i:每批任务上限;providers:#b:aftership/kuaidi100 是否已配置;job:?LogisticsJob:指定或最近物流任务');
    $s['PaginatorLink'] = shape('url:?s:分页链接;label:s:页码/上一页/下一页文本;active:b:是否当前页;~page:?i:页码');
    $s['LogPage'] = shape('current_page:i:当前页;data:[]BusinessOperationLog:当前页操作日志;first_page_url:s:首页地址;from:?i:本页开始序号;last_page:i:最后页码;last_page_url:s:最后页地址;links:[]PaginatorLink:分页链接;next_page_url:?s:下一页;path:s:分页基址;per_page:i:每页数量;prev_page_url:?s:上一页;to:?i:本页结束序号;total:i:日志总数');
    $s['BusinessOperationLog'] = shape('id:i:日志 ID;module:s:业务模块;entity_id:s:业务记录 ID;action:s:操作类型;actor_user_id:i:操作用户 ID;before:any:操作前快照，创建时可为 null;after:any:操作后快照，删除时可为 null;created_at:?time:创建时间 ISO 8601;updated_at:?time:更新时间 ISO 8601');
    $s['InvoiceLog'] = ['allOf' => [ref('BusinessOperationLog'), shape('operationTime:s:北京时间 YYYY-MM-DD HH:mm:ss，与导出一致;operator:s:操作人名称，优先历史记录，其次显示名称、登录账号和用户 ID;actionLabel:s:按 locale 翻译的操作名称，与导出一致;orderNumber:s:订单号，OCR 未绑定订单时为空;customer:s:客户名称，删除订单取操作前快照;changedFields:s:变更字段，使用竖线分隔;details:s:操作详情;recordId:s:业务记录 ID，OCR 为附件 ID')]];
    $s['InvoiceLogPage'] = $s['LogPage'];
    $s['InvoiceLogPage']['properties']['data']['items'] = ref('InvoiceLog');
    $s['ProcurementLog'] = ['allOf' => [ref('BusinessOperationLog'), shape('operator:s:操作者名称，与导出一致；优先显示名称，其次登录账号，用户资料缺失时返回 #ID')]];
    $s['ProcurementLogPage'] = $s['LogPage'];
    $s['ProcurementLogPage']['properties']['data']['items'] = ref('ProcurementLog');
    $s['ApiError'] = shape('success:b:失败时为 false;code:i:业务错误码;message:s:错误说明;data:null:错误没有业务数据');
    $s['ValidationError'] = shape('message:s:校验失败摘要;errors:#[]s:字段名到错误消息数组，支持 items.0.price 等嵌套字段');
    $s['HttpError'] = shape('message:s:HTTP 异常信息', ['additionalProperties' => true]);

    return $s;
}
