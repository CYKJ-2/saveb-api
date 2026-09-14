<?php

declare(strict_types=1);

namespace ApiDocs;

use RuntimeException;

/** 控制器输出经过业务代码核对；新路由没有契约时生成器会报错，而不是生成空文档。 */
function contract(string $controller, string $method, string $verb, array $defaults): array
{
    $groups = [
        'Auth' => '认证与登录', 'User' => '用户管理', 'UserRole' => '用户角色授权',
        'Role' => '角色管理', 'Permission' => '权限管理', 'Order' => '基础订单',
        'OrderManagement' => '订单管理', 'OrderStatistics' => '订单统计',
        'DashboardOverview' => '首页统计', 'Invoice' => 'Invoice 订单', 'InvoiceOcr' => 'Invoice 识别与附件',
        'Attachment' => 'Invoice 识别与附件', 'SaSales' => 'SA 销售统计', 'Procurement' => '采购工作台',
        'Warehouse' => '仓库工作台', 'Influencer' => '达人工作台', 'Paypal' => 'PayPal 余额监控',
        'Operations' => '工作巡查', 'Collector' => '数据采集', 'CollectorManagement' => '数据采集',
        'Logistics' => '采购物流', 'Analysis' => 'Analysis 采购成交价格分析',
        'SaPersonalPerformance' => 'SA 个人业绩详情',
    ];
    $maps = [
        'SaPersonalPerformance' => [
            'options' => ['个人业绩员工选项及默认员工', 'PersonalPerformanceOptions'],
            'report' => ['员工分摊业绩、每日趋势及分页订单', 'PersonalPerformanceReport'],
        ],
        'Analysis' => [
            'options' => ['分析筛选项与双语列定义', 'AnalysisOptions'],
            'report' => ['CNY 成交总览、日月趋势、排行占比和四组交叉分析', 'AnalysisReport'],
            'index' => ['分析明细分页（默认 20 条）', 'AnalysisRowPage'],
            'customers' => ['客户名汇总分页及完整历史首购日期', 'AnalysisCustomerPage'],
            'evidence' => ['采购原始行与分类依据', 'AnalysisEvidence'],
            'imports' => ['采集版本历史分页', 'AnalysisImportPage'],
            'import' => ['上传采购当月快照或供应商映射，首次可初始化历史', 'AnalysisImported'],
            'export' => ['按当前语言导出全部分析明细', 'csv'],
        ],
        'Auth' => ['login' => ['账号密码登录', 'Login'], 'me' => ['当前用户与权限树', 'Me'], 'logout' => ['退出并撤销当前令牌', 'Message']],
        'User' => ['index' => ['分页查询用户', '[]User'], 'all' => ['用户下拉选项（全量）', '[]User'], 'count' => ['用户数量', shape('total:i:用户数;active_only:b:是否仅启用用户')], 'show' => ['用户详情', 'User'], 'store' => ['创建用户', 'User'], 'update' => ['修改用户', 'User'], 'destroy' => ['删除用户', 'Deleted'], 'permissions' => ['用户有效权限（平铺）', '[]PermissionBrief'], 'changePassword' => ['修改用户密码', 'Message']],
        'Role' => ['index' => ['分页查询角色', 'RolePage'], 'all' => ['角色下拉选项（全量）', '[]Role'], 'show' => ['角色详情', 'Role'], 'store' => ['创建角色', 'Role'], 'update' => ['修改角色', 'Role'], 'destroy' => ['删除角色', 'Deleted'], 'assignments' => ['角色已授权权限 ID', shape('permissions:[]i:精确保存的权限 ID 列表')], 'assignPermissions' => ['同步角色权限', 'Role']],
        'Permission' => ['index' => ['权限平铺列表', '[]Permission'], 'tree' => ['权限树', '[]Permission'], 'show' => ['权限详情', 'Permission'], 'store' => ['创建权限节点', 'Permission'], 'update' => ['修改权限节点', 'Permission'], 'destroy' => ['删除权限节点', 'Deleted']],
        'UserRole' => ['index' => ['查询用户全部角色', 'UserRoles'], 'show' => ['查询兼容主角色', 'UserRole'], 'assign' => ['设置单一角色', 'UserRole'], 'unassign' => ['移除兼容主角色', 'UserRole'], 'sync' => ['同步用户全部角色', 'UserRoles'], 'add' => ['追加角色', ['anyOf' => [ref('UserRoles'), ref('UserRole')]]], 'remove' => ['移除指定角色', 'UserRoles']],
        'Order' => ['index' => ['基础订单分页搜索', 'OrderPage'], 'count' => ['基础订单数量', shape('total:i:订单数量;status:?s:输入的状态筛选值')], 'show' => ['基础订单详情', 'Order'], 'store' => ['创建基础订单', 'Order'], 'update' => ['修改基础订单', 'Order'], 'destroy' => ['删除基础订单', 'Deleted']],
        'OrderManagement' => ['index' => ['合并来源订单分页搜索', 'ManagedOrderPage'], 'editorOptions' => ['编辑订单的客服选项', shape('staff:[]s:有效及历史员工代码')], 'adjust' => ['修改订单主客服和销售分成', 'Version'], 'export' => ['导出订单搜索结果', 'csv']],
        'Invoice' => ['index' => ['Invoice 订单分页列表', 'InvoicePage'], 'show' => ['Invoice 详情及内嵌图片', 'InvoiceDetail'], 'nextNumber' => ['预览下一个 Invoice 订单号', shape('number:s:预览订单号，保存时会重新生成')], 'formOptions' => ['Invoice 表单选项', 'InvoiceOptions'], 'parseText' => ['解析粘贴的 Invoice 文本', 'ParsedInvoice'], 'save' => [$verb === 'POST' ? '创建 Invoice 订单' : '修改 Invoice 订单', 'InvoiceOrder'], 'destroy' => ['删除 Invoice 订单', 'null'], 'logs' => ['Invoice 操作日志分页（与导出字段一致）', 'InvoiceLogPage'], 'exportLogs' => ['导出 Invoice 操作日志', 'csv']],
        'Attachment' => ['upload' => ['上传 Invoice 图片附件', 'Attachment'], 'show' => ['读取附件图片二进制', 'image']],
        'InvoiceOcr' => ['recognize' => ['识别 Invoice 截图并提取商品', 'Ocr']],
        'SaSales' => ['bounds' => ['SA 数据可用日期', 'Bounds'], 'report' => ['SA 销售统计报表', 'SalesReport'], 'orders' => ['SA 订单明细分页查询', 'SalesOrders'], 'orderOptions' => ['SA 员工筛选选项', shape('employees:[]s:员工代码')], 'export' => ['导出 SA 销售报表', 'csv']],
        'Procurement' => ['index' => ['采购订单分页列表', 'ProcurementPage'], 'statistics' => ['采购任务状态统计', shape('total:i:筛选后的任务数量;statuses:#i:各采购状态计数')], 'logs' => ['采购操作日志分页（包含操作者名称）', 'ProcurementLogPage'], 'save' => [$verb === 'POST' ? '创建采购任务' : '修改采购任务', 'Version'], 'destroy' => ['移除采购任务', 'null'], 'destroySource' => ['移除未建采购任务的来源订单', 'null'], 'export' => ['导出采购订单', 'csv'], 'exportLogs' => ['导出采购操作日志（包含操作者名称）', 'csv']],
        'Warehouse' => ['index' => ['仓库履约分页列表', 'WarehousePage'], 'action' => ['更新质检、发货及履约状态', 'Version']],
        'Influencer' => ['directory' => ['达人网站目录分页', 'InfluencerDirectory'], 'options' => ['网站绑定的达人名称选项', ['type' => 'array', 'items' => shape('name:s:达人名称')]], 'sales' => ['达人销量分页', 'InfluencerSalesPage'], 'report' => ['达人月度销量报表', 'InfluencerReport'], 'save' => ['新增或确认达人网站归属', ['allOf' => [ref('InfluencerDomain'), shape('added:b:是否新增加网站归属')]]], 'export' => ['导出完整达人网站目录', 'csv']],
        'Paypal' => ['index' => ['PayPal 账户分页与汇总', 'PaypalPage'], 'orders' => ['指定收款账户的订单分页', 'PaypalOrders'], 'withdrawals' => ['提款记录分页', 'WithdrawalPage'], 'statistics' => ['每日或每月提款统计', '[]WithdrawalPeriod'], 'create' => ['新增 PayPal 监控账户', shape('id:i:新账户 ID')], 'update' => ['更新 PayPal 账户', 'Version'], 'export' => ['导出全部 PayPal 账户', 'csv'], 'exportOrders' => ['导出指定账户收款订单', 'csv'], 'exportWithdrawals' => ['导出提款记录', 'csv'], 'logs' => ['PayPal 操作记录分页（与导出字段一致）', 'PaypalOperationLogPage'], 'exportLogs' => ['导出 PayPal 修改日志', 'csv']],
        'Operations' => ['index' => ['工作巡查入口列表', '[]Spreadsheet'], 'directory' => ['工作巡查目录与部门统计', 'Operations']],
        'Collector' => ['status' => ['数据采集运行状态', 'CollectorStatus'], 'today' => ['提交当日采集任务', 'CollectorAccepted']],
        'CollectorManagement' => ['settings' => ['自动采集设置', 'CollectorSchedule'], 'saveSettings' => ['修改自动采集间隔', 'CollectorSchedule'], 'jobs' => ['采集任务分页列表', 'CollectorJobPage'], 'detail' => ['采集任务及分片分页', 'CollectorDetail'], 'collect' => ['提交历史或缺失日期采集', 'CollectorJob'], 'reprocess' => ['从归档预览重新处理', 'CollectorJob']],
        'Logistics' => ['status' => ['物流服务及任务状态', 'LogisticsStatus'], 'refresh' => ['提交物流刷新任务', 'LogisticsJob']],
    ];
    if (in_array($controller, ['OrderStatistics', 'DashboardOverview'], true)) {
        $module = $defaults['module'];
        $titles = ['overview' => '销售概览', 'sales-trend' => '分类销售趋势', 'categories' => '销售分类统计', 'influencers' => '达人统计', 'staff' => '员工统计', 'recent-orders' => '最近订单', 'currencies' => '币种统计', 'paypal' => 'PayPal 收款与提款', 'exchange-rates' => '汇率参考', 'system-status' => '数据覆盖状态', 'spreadsheets' => '在线协作入口'];
        $response = $module === 'overview' ? 'Totals' : 'Statistics';
        if ($controller === 'DashboardOverview') {
            $inner = match ($module) {
                'overview' => 'DashboardOverview', 'recent-orders' => 'RecentOrders', 'paypal' => 'DashboardPaypal',
                'exchange-rates' => 'Rates', 'system-status' => 'DataStatus', 'spreadsheets' => 'Spreadsheets',
                default => 'Statistics',
            };
            $response = shape('range:Range:实际采用的统计范围;timezone:s:Asia/Shanghai;generatedAt:time:报表生成时间;data:' . $inner . ':本模块统计数据');
        }
        $entry = [$titles[$module], $response];
    } else {
        $entry = $maps[$controller][$method] ?? throw new RuntimeException("缺少接口契约：$controller::$method");
    }
    [$title, $response] = $entry;
    $result = ['title' => $title, 'group' => $groups[$controller], 'response' => $response, 'notes' => [], 'rules' => [], 'extra' => []];
    if ($controller === 'SaPersonalPerformance') {
        $result['notes'][] = '作为 SA 销售统计页面底部模块，需要同时具备 business.sa_sales.list 和 business.sa_sales.personal 权限。';
        if ($method === 'report') {
            $result['notes'][] = 'staffCode 精确匹配客服编码，日期采用北京时间闭区间且最多 366 天。scope 默认 all，包含普通及 Invoice；可选 order 或 invoice。测试订单、待处理订单不计入。沿用订单人工覆盖、客服分摊、历史汇率及 Invoice 去重规则。';
            $result['notes'][] = '个人统计参照 html/html/index.html 的 renderPersonalDetail。金额按全部个人分摊记录累加；成交与退款单数分别按日期、客户原名、订单总金额去重（总金额为零时回退个人金额）。totalOrders 和 orders 均为去重成交单数，不包含退款；去重不删除金额或订单明细。';
            $result['notes'][] = '退款率按单量为退款单数/(成交单数+退款单数)，按金额为退款额/(销售额+退款额)。沿用旧版边界：无成交单时按单量为 0，无正销售额时按金额为 0。summary.refunds 为正数，退款明细金额为负数。缺少美元汇率的订单保留明细，但不纳入财务指标或去重单数。';
            $result['notes'][] = '所有金额统一 USD，佣金按所选范围合计净销售额计算一次：前 40000 USD 为 1.5%，随后 20000 为 2%，随后 20000 为 2.5%，其余为 3%；净额不大于零时佣金为零。普通与 Invoice 合并计提。上方 SA 普通/Invoice 排行榜仍各自独立计提。';
            $result['notes'][] = '前端默认当月完整日期，daily 补齐每个日历日供图表展示，每日表格只显示 sales>0 或 refunds>0 的日期。仍使用新系统订单及 Invoice 数据；旧 html 使用独立 sales 台账且按退款发生日新增负数记录。此接口的状态退款归入现有订单业务日期，不凭状态推造旧台账的退款日期或额外正销售记录。';
            $result['notes'][] = 'orders 默认每页 20 条，最大 100 条；合并来源后服务端分页，仅为当前页批量读取电话等附加信息。翻页传 includeSummary=0 时 summary=null、daily=[]，页面保留上次汇总；不从当前页重算总数。';
            $result['rules'] += ['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:100'];
        }
    }
    if ($controller === 'Analysis') {
        $result['resolvedDynamic'] = true;
        if ($method !== 'import' && $method !== 'evidence') {
            $result['rules']['endDate'] = 'nullable|date_format:Y-m-d';
        }
        $result['notes'][] = '仅使用采购集成表及供应商字典，不关联收单或订单系统。金额固定 CNY，以已采购且价格有效的每行实际成交价格直接汇总，不推算数量。金额为两位小数字符串，缺失价格保留 null。趋势使用发起采购日期，缺日期金额只纳入无日期限制的总览。';
        $result['notes'][] = '未传日期为全部已导入历史。品牌代号与供应商联合匹配品类；品牌待确认统一待分类。首复购按规范化客户名和完整有效采购历史判断：最早采购日所有行是首购，后续日期是复购；缺名或日期为未知。筛选不改变首次日期。';
        if ($method === 'report') {
            $result['notes'][] = '交叉单元格 share = amount / 当前筛选总成交金额 × 100。行列合计复用相同快照下 distributions 对应维度的 amount、rows、share，总合计使用 summary.amount 与 eligible_rows。品类×价格区间使用 category/price_band；首复购×品牌使用 customer_type/brand。保留待分类和未知组，总额为零时占比为零，负金额保留符号，四舍五入后的占比相加可能不等于 100%。';
            $result['notes'][] = '页面默认传入北京时间当月起止日期。概览、排行及交叉按原日期查询；趋势按 grain=month 扩展至对应自然年、grain=day 扩展至对应自然月，再截取 2026-07-01 起的日期。品牌、品类等条件保留。trend.startDate/endDate 返回实际趋势范围；范围完全早于起算日时保留原日期边界，periods/points 为空。起算日后的无数据月份仍保留坐标。';
        }
        if ($method === 'import') {
            $result['notes'][] = 'multipart/form-data：file 为 XLSX，最大 256 MiB；source_type=procurement/suppliers。默认 mode=current_month，仅替换北京时间当前月份 Sheet 的完整快照，其他月份不变；缺少月份、空表或解析失败保留旧数据。mode=initialize 仅首次导入全部历史。相同文件和目标月份重复导入不累计。供应商上传更新映射并重新分类有效历史，原始值和成交金额不变。';
        }
    }
    if ($controller === 'Auth') {
        $result['notes'][] = '登录无需令牌；其余请求使用 Authorization: Bearer <token>。不是 JWT，也不是 Laravel session 登录。';
        if ($method !== 'logout') {
            $result['queryRules'] = ['locale' => 'nullable|string'];
            $result['extra'] = shape('locale:s:小写语言标识，优先 query.locale，其次 Accept-Language，默认 en-us')['properties'];
        }
    }
    if ($controller === 'User' && $method === 'index') {
        $result['extra'] = shape('current_page:i:当前页;per_page:i:每页条数;total:i:全部筛选用户数;last_page:i:最后页码')['properties'];
        $result['notes'][] = '此接口 data 直接是用户数组；分页字段位于响应顶层，不是 data.list。';
    }
    if ($controller === 'User' && $method === 'all') {
        $result['extra'] = shape('total:i:返回用户数量')['properties'];
    }
    if ($controller === 'User' && in_array($method, ['store', 'update'], true)) {
        $result['notes'][] = '提交 role_id 或 role_ids 还需 system.user.assign_role 权限；两者合并去重后保存。不能授予自己没有的权限，系统保护规则可能返回 403。';
    }
    if ($controller === 'Role' && $method === 'assignPermissions') {
        $result['notes'][] = 'permission_ids 与 permissions 至少提供一个；都提供时优先使用 permission_ids。空数组会清空权限。后端精确保存提交节点，不自动补选子节点；不能授予超出自身的权限。';
        $result['bodyAnyOf'] = [['required' => ['permission_ids']], ['required' => ['permissions']]];
    }
    if ($controller === 'UserRole') {
        $result['notes'][] = match ($method) {
            'assign' => 'role_id 或 role_code 至少一个；都提供时 role_id 优先。此兼容接口将全部角色替换成指定单一角色。',
            'add' => 'role_id 或 role_code 至少一个；已授权角色返回 UserRole 及 message，否则返回更新后的 UserRoles。',
            'sync' => 'role_ids 必须出现；空数组清空角色，非空数组完整替换角色集合。',
            'unassign' => '移除当前兼容主角色，保留其余关联角色；主角色可能随后重新推导。',
            default => '角色授予受当前用户有效权限和超级管理员保护规则限制。',
        };
        if (in_array($method, ['assign', 'add'], true)) {
            $result['bodyAnyOf'] = [['required' => ['role_id']], ['required' => ['role_code']]];
        }
    }
    if ($controller === 'OrderManagement' && in_array($method, ['index', 'export'], true)) {
        $result['rules'] = [
            'startDate' => 'nullable|required_with:endDate|date_format:Y-m-d',
            'endDate' => 'nullable|required_with:startDate|date_format:Y-m-d|after_or_equal:startDate',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'scope' => 'sometimes|in:normal,testing', 'granularity' => 'sometimes|in:day,month',
            'staffExact' => 'nullable|boolean', 'influencerExact' => 'nullable|boolean',
            'orderStatus' => 'nullable|in:completed,pending,failed,reversed,refunded,expired',
            'classification' => 'nullable|in:official,top_influencer,mid_influencer,offline,invoice,unmatched',
        ];
        foreach (['orderId', 'paypalOrderId', 'customerName', 'customerService', 'paypalAccount', 'website', 'influencer'] as $key) {
            $result['rules'][$key] = 'nullable|string|max:255';
        }
        $result['resolvedDynamic'] = true;
        $result['notes'][] = '合并普通订单与 Invoice 并去重；未传日期不自动限制为当天。scope=testing 额外要求 system.order.testing。列表中的 Invoice ID 为 invoice:ID，不能直接传入普通订单编辑接口。';
    }
    if ($controller === 'OrderManagement' && $method === 'adjust') {
        $result['notes'][] = '仅 pending/completed/failed 订单可以修改客服；targetStatus=completed 仅用于待处理订单。分摊客服不可重复、比例合计须为 100%，主客服必须包含在分摊列表中。version 冲突返回 409。';
    }
    if ($controller === 'OrderStatistics') {
        $result['notes'][] = '仅统计完成的正常订单；overview/currencies 排除 Invoice，分类及趋势包含 Invoice。日期差不得超过 366 天；granularity 默认 day。';
        if ($defaults['module'] === 'categories') {
            $result['notes'][] = '始终返回官方站点、头部达人、中腰部达人、线下订单、Invoice 订单和未匹配分类；当前日期范围无订单的分类返回零值。';
        }
    }
    if ($controller === 'DashboardOverview') {
        $result['notes'][] = '默认查询北京时间当天；只传一端日期时另一端取相同日期。原始筛选范围最多 366 个自然日。';
        if ($defaults['module'] === 'sales-trend') {
            $result['notes'][] = 'granularity=day 时，统计范围扩展到筛选涉及的完整自然月；month 时扩展到涉及的完整自然年。默认按日展示北京时间当月全部日期，无数据日期补零。只传某一天仍统计其整月，跨月筛选补齐首月月初至末月月末。返回 range 为实际统计范围，其他首页模块仍使用原始筛选日期。';
        }
        if (in_array($defaults['module'], ['currencies', 'paypal', 'exchange-rates', 'system-status', 'spreadsheets'], true)) {
            $result['notes'][] = '当前首页已移除此展示模块，但后端路由仍存在，本说明保留其实际接口。';
        }
    }
    if ($controller === 'Invoice') {
        if ($method === 'save') {
            $result['notes'][] = '编辑必须提交最新 version。虽然状态字段能通过多个枚举的格式校验，业务层目前只允许 Paid 入库，其他状态返回 422；省略时兼容 Paid。分摊合计须为 100%，客服不可重复。';
            $result['notes'][] = 'invoice_date 为添加日期，编辑不会覆盖原值；order_date 为订单业务日期。附件必须属于当前用户或绑定于正在编辑的订单。保存后重新请求详情以获得内嵌图片。';
        }
        if ($method === 'show') {
            $result['notes'][] = '详情包含 invoice_screenshot_image 和 items[].image；直接使用 src 展示，依据 status 区分未上传、文件缺失和读取失败，无需逐张请求 /attachments/{id}。';
        }
        if ($method === 'nextNumber') {
            $result['notes'][] = '仅预览，不预占订单号；最终订单号由保存事务生成。';
        }
    }
    if ($controller === 'InvoiceOcr' || ($controller === 'Invoice' && $method === 'parseText')) {
        $result['notes'][] = 'fields 只包含成功识别的字段，items 可包含多件商品。识别非 USD 金额时返回 sourceAmount/currency 和换算提示，不能直接把它当作 amount_usd。识别结果不自动创建订单。';
    }
    if ($controller === 'Attachment') {
        $result['notes'][] = $method === 'upload' ? 'multipart/form-data，字段 file。最大 25 MiB，JPEG/PNG/WebP，像素总数不得超过 4000 万；上传后用返回的 id 进行 OCR 或绑定 Invoice。' : '返回图片文件而非 JSON；附件访问还校验所有者及订单绑定关系。Cache-Control: private, no-store。';
    }
    if ($controller === 'SaSales' && $method === 'orders') {
        $result['notes'][] = '销售分类为空时只查询全部普通订单；classification=invoice 才查 Invoice。customerService=Unassigned 筛选未分配客服；其他员工按精确匹配。totalAmount 为全部筛选记录的净金额。';
    }
    if ($controller === 'SaSales' && $method === 'report') {
        $result['notes'][] = '普通订单指标位于 data，Invoice 指标位于 data.invoiceSales。当前 Controller 固定 includeDetails=false，即使传 true 也不会返回明细；请调用 /sa-sales/orders。';
    }
    if ($controller === 'Procurement') {
        $result['notes'][] = match ($method) {
            'save' => '编辑需最新 version。sourceKey 用于关联来源订单；来源商品、已交仓商品受业务校验保护，不能任意改写。warehouse_arrived 会创建或同步仓库记录。',
            'statistics' => '统计忽略 status 筛选，其余筛选保留；不按当前分页截断。',
            default => '列表同时包含已创建的采购任务和未建任务的来源订单；后者 id=null、version=0，移除时使用 /procurement/source 和 sourceKey。',
        };
    }
    if ($controller === 'Warehouse') {
        $result['notes'][] = $method === 'index' ? 'scope 默认 recent，按业务日期区分近 120 天与历史；statuses/allTotal 不受 status 筛选影响。' : 'items 必须与现有商品逐项对应，保留名称和数量。服务端根据质检与累计发货数量推导最终状态，并同步采购任务；版本冲突返回 409。';
    }
    if ($controller === 'Paypal' && $method === 'update') {
        $action = $defaults['action'];
        $result['title'] = ['balance' => '登记 PayPal 当前余额', 'review' => '设置 PayPal 审核次数', 'withdrawal' => '登记 PayPal 提款'][$action];
        $result['rules'] = ['version' => 'required|integer|min:1'];
        if ($action === 'review') {
            $result['rules']['value'] = 'required|integer|min:0|max:1000000';
        } else {
            $result['rules']['amount'] = 'required|numeric|' . ($action === 'withdrawal' ? 'gt:0' : 'min:0') . '|max:10000000|decimal:0,2';
        }
        if ($action === 'withdrawal') {
            $result['rules'] += ['date' => 'required|date_format:Y-m-d|before_or_equal:today', 'source' => 'nullable|string|max:2000'];
        }
        $result['resolvedDynamic'] = true;
        $result['notes'][] = 'version 来自账户列表，冲突返回 409。balance 设置当前余额基线；review 设置审核次数绝对值；withdrawal 新增一笔提款并扣减监控余额。';
    }
    if ($controller === 'Paypal' && $method === 'export') {
        $result['notes'][] = '此现有接口导出全部账户，不接收列表的 keyword/sort/threshold 筛选。';
    }
    if ($controller === 'Paypal' && in_array($method, ['logs', 'exportLogs'], true)) {
        $result['notes'][] = '沿用 business.paypal.logs 权限。合并本地和旧共享状态日志，按操作时间倒序排列；列表在数据库分页，导出不受页码限制。账号名优先使用历史快照，缺失时按邮箱或账户 ID 补全。';
        $result['notes'][] = '列表与 CSV 的九个展示字段、北京时间格式及中英文内容一致。金额保留两位小数，审核次数为整数；原始日志没有记录的财务值保持为空。';
    }
    if ($controller === 'Paypal' && in_array($method, ['withdrawals', 'exportWithdrawals', 'statistics'], true)) {
        $result['notes'][] = '历史导入的累计提款可能没有日期。列表/导出可包含 date 为空的历史累计占位；日/月图表只统计有日期的提款，所以图表合计可能与列表不同。';
    }
    if ($controller === 'Influencer' && $method === 'export') {
        $result['notes'][] = '当前接口导出全部网站目录，没有关键词或日期入参。';
    }
    if ($controller === 'CollectorManagement' && in_array($method, ['collect', 'reprocess'], true)) {
        $result['notes'][] = '异步受理返回 HTTP 202，后续通过任务详情查询结果。requestId 为幂等 UUID，提交超时重试需复用同一值。日期不得晚于北京时间今天。';
        $result['notes'][] = $method === 'reprocess' ? 'mode 必须为 reprocess，sourceJobId 必填；当前服务强制 dryRun=true，仅预览重处理，不发布业务数据。' : 'mode=history 为历史范围采集，missing 为补缺；dryRun 默认 false。sourceJobId 在此接口禁止传入。';
    }
    if ($controller === 'Collector' && $method === 'today' || $controller === 'Logistics' && $method === 'refresh') {
        $result['notes'][] = 'HTTP 202 表示已受理，不等于执行完成。requestId 为幂等 UUID；超时重试应保留同一编号。状态接口返回当前进度。';
    }

    return $result;
}

/** 参数中文说明；字段仍保持代码中的原始大小写与下划线形式。 */
function descriptions(): array
{
    return [
        'id' => '记录 ID，见本接口的资源说明', 'roleId' => '要移除的角色 ID',
        'page' => '页码，从 1 开始，默认 1', 'per_page' => '每页条数，默认 20，最多 100',
        'username' => '登录用户名；列表中为用户名筛选', 'password' => '用户密码', 'token_name' => '本次登录的令牌名称',
        'locale' => '语言；导出使用 zh-CN/en-US，认证接口按传入值转小写',
        'display_name' => '用户显示名称', 'active' => '用户启用状态；列表省略时不过滤，all/count 默认 false 表示不限定启用',
        'must_change_password' => '是否要求用户修改密码', 'role_id' => '兼容单角色 ID', 'role_ids' => '用户角色 ID 列表',
        'role_ids.*' => '角色 ID，必须存在', 'role_code' => '角色代码', 'staff_code' => '员工/客服代码',
        'permission_ids' => '权限 ID 列表，空数组用于清空', 'permission_ids.*' => '未删除的权限 ID，不可重复',
        'permissions' => '权限代码列表，作为 permission_ids 的兼容替代', 'permissions.*' => '未删除的权限代码，不可重复',
        'name' => '英文名称', 'name_zh' => '中文名称', 'code' => '唯一业务代码', 'description' => '英文说明', 'description_zh' => '中文说明',
        'type' => '权限类型 menu/action', 'parent_id' => '父权限 ID，0 为根节点', 'path' => '前端菜单路径',
        'icon' => '菜单图标', 'component' => '前端组件路径', 'action' => '操作标识，例如 list/create/update',
        'resource' => '权限资源标识', 'level' => '菜单层级', 'sort' => '排序字段或数值，取值见本接口规则',
        'status' => '记录状态，取值见本接口枚举', 'hidden' => '是否隐藏菜单', 'keyword' => '搜索关键词',
        'startDate' => '开始业务日期 YYYY-MM-DD，含当天', 'endDate' => '结束业务日期 YYYY-MM-DD，含当天；提供开始日期时不得早于开始日期',
        'start' => '采集开始日期 YYYY-MM-DD，含当天', 'end' => '采集结束日期 YYYY-MM-DD，含当天，不能晚于北京时间今天',
        'month' => '查询月份 YYYY-MM', 'granularity' => '统计粒度 day 按日、month 按月，默认 day',
        'orderId' => '订单号筛选或来源订单编号', 'clientOrderId' => '来源客户订单编号', 'paypalOrderId' => 'PayPal/来源交易编号',
        'customerName' => '顾客姓名', 'customerService' => '客服/员工筛选', 'paypalAccount' => '收款 PayPal 账户筛选',
        'website' => '来源网站筛选', 'influencer' => '达人名称', 'influencerExact' => '是否精确匹配达人，默认 false',
        'staffExact' => '是否精确匹配员工，默认 false', 'scope' => '查询范围，含义见本接口枚举和说明',
        'orderStatus' => '订单状态筛选或保存值', 'classification' => '销售渠道分类，不是商品品类', 'sort_by' => '排序字段，默认 order_time',
        'sort_dir' => '排序方向，默认 desc', 'orderTime' => '下单日期时间', 'sourceSite' => '来源网站', 'influencerName' => '达人名称',
        'receivingPaypal' => '收款 PayPal', 'amountOriginal' => '原币金额', 'currency' => '币种代码，例如 USD',
        'amountUsd' => 'USD 金额', 'itemsCount' => '商品展示数量', 'productName' => '商品名称', 'staffCode' => '客服代码',
        'raw' => '来源兼容 JSON 数据', 'version' => '最近读取的记录版本；编辑后使用响应中的新版本，冲突时刷新重试',
        'targetStatus' => '待处理订单确认完成时传 completed', 'primaryStaffCode' => '主客服，必须同时存在于 staffAllocations',
        'staffAllocations' => '完整销售分成列表，合计必须 100%', 'staffAllocations.*.staffCode' => '参与分成的客服，不可重复',
        'staffAllocations.*.percent' => '该客服的百分比分成，大于 0，合计 100',
        'invoice_date' => '添加日期 YYYY-MM-DD；编辑时服务端保留原添加日期', 'order_date' => '订单日期 YYYY-MM-DD',
        'invoice_status' => 'Invoice 付款状态；当前入库业务只接受 Paid', 'customer_full_name' => '收件人全名',
        'customer_email' => '顾客邮箱', 'phone_number' => '电话', 'country' => '国家/地区', 'address' => '完整收货地址',
        'invoice_link' => 'Invoice 的 HTTP/HTTPS 链接', 'recipient_paypal' => '收款 PayPal 邮箱', 'amount_usd' => 'Invoice 总金额 USD，最多两位小数',
        'expedited_shipping' => '是否加急配送', 'gift_box' => '礼盒 Has 有、None 无', 'fixed_discount' => '固定折扣金额 USD',
        'percentage_discount' => '折扣百分比，0 至 100', 'invoice_screenshot_attachment_id' => '已上传的 Invoice 截图附件 ID',
        'items' => '完整商品明细数组，逐件填写，不能只提交第一件', 'items.*.product_name' => '产品名称',
        'items.*.description' => 'Invoice 中的产品名称/描述', 'items.*.quantity' => 'Invoice 中的数量', 'items.*.price' => 'Invoice 中的价格，最多两位小数',
        'items.*.notes' => '商品备注', 'items.*.image_attachment_id' => '商品图片附件 ID，可空',
        'allocations' => '完整客服分摊列表，percent 合计 100%', 'allocations.*.staff_code' => '分摊客服代码，不可重复',
        'allocations.*.percent' => '销售分成百分比', 'allocations.*.commission_percent' => '提成百分比，省略时 0',
        'text' => '粘贴的 Invoice 原始文本', 'attachmentId' => '已上传图片附件 ID', 'file' => '上传文件；类型和大小限制以本接口校验规则为准（图片或 Analysis XLSX）',
        'category_id' => 'Analysis 品类字典主键', 'brand_id' => 'Analysis 品牌字典主键', 'supplier_id' => 'Analysis 供应商主键',
        'customer_type' => 'unknown 未知、first 已知历史首次、returning 复购', 'classification_status' => '商品品类匹配状态',
        'record_type' => 'ordinary 普通订单采购、invoice、after_sale 售后、other_procurement 达人或样品等',
        'sheet_name' => '来源工作表名称', 'quality' => 'missing 数据缺失，classification 品牌或品类待核查，needs_review 全部核查问题', 'include_cancelled' => '是否包含取消采购，默认 false',
        'source_period' => '来源月份 YYYY-MM，按 Sheet 确定，与采购日期独立保留',
        'customer_key' => '客户汇总接口返回的规范化客户名，精确查询此客户',
        'purchase_method' => 'ws/pl/invoice/after_sale/influencer/accessory/unknown，来自下单形式原文',
        'price_band' => 'CNY 每行实际成交价格区间，负数单列；3000+ 中的加号需 URL 编码',
        'scope' => 'eligible 默认只看已采购且价格有效记录；all 保留全部来源行；quality 查询自动包含无效行',
        'grain' => 'Analysis 趋势粒度 day 或 month，默认 month', 'source_type' => 'suppliers 供应商优选表，procurement 采购集成表',
        'price_basis' => 'row_total 每行商品合计、unit 单件价格、unknown 待确认',
        'includeDetails' => '兼容参数；当前 SA report 固定为 false，明细请独立查询',
        'includeSummary' => '是否返回个人汇总及每日趋势；默认 true，翻页可传 0，仅返回当前页订单',
        'sourceKey' => '来源订单标识，如 order:123 或 invoice:invoice:123，使用列表返回值原样提交',
        'products' => '采购商品列表；来源商品及已交仓商品受业务保护', 'products.*.name' => '采购商品名称',
        'products.*.quantity' => '采购商品数量', 'quantity' => '商品总数量', 'purchaseStatus' => '采购状态', 'supplier' => '供应商',
        'cost' => '采购成本，最多两位小数', 'eta' => '预计到货日期 YYYY-MM-DD', 'trackingNumber' => '物流运单号',
        'trackingCarrier' => '承运商', 'trackingPhone' => '物流查询电话', 'notes' => '操作/任务备注', 'available_only' => '仅返回未创建采购任务的来源订单',
        'items.*.inspection' => 'pending 待质检、passed 通过、failed 不通过', 'items.*.shippedQuantity' => '累计发货数量，不能超过商品数量',
        'items.*.outboundTracking' => '出库物流运单号', 'domain' => '需要绑定的来源网站域名',
        'email' => 'PayPal 收款邮箱', 'accountName' => '账户名称', 'addedDate' => '账户创建日期 YYYY-MM-DD',
        'balance' => '初始监控余额 USD', 'reviews' => '审核次数', 'threshold' => '余额提醒阈值 USD，默认 5000',
        'value' => '审核次数绝对值', 'amount' => '本次余额设置值或提款金额 USD，取决于接口路径',
        'date' => '提款日期 YYYY-MM-DD，不得晚于今天', 'source' => '提款来源或备注', 'mode' => '操作/统计模式，取值见本接口枚举',
        'department' => '工作巡查部门代码', 'requestId' => '幂等请求 UUID；同一次操作重试必须复用',
        'intervalMinutes' => '自动采集间隔分钟，5 至 1440', 'dryRun' => '是否仅预览；reprocess 强制为 true',
        'sourceJobId' => '重处理所使用的历史归档任务 UUID', 'jobId' => '任务 UUID，不传时查询最近任务',
        'taskId' => '指定采购任务 ID；不传时按批次选择', 'provider' => '物流查询提供方，默认 auto',
    ];
}
