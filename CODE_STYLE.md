# PHP 四层代码约定

以 `novel-api/novel-api/app` 的业务命名、方法职责和中文 PHPDoc 为参考，保留 Laravel 的构造函数依赖注入、Eloquent 和现有响应封装。

## 分层与命名

- Controller：校验请求，取得 `auth_user`，调用 Service，使用 `AppResponse` 或 CSV / 文件响应。声明实际返回类型，如 `JsonResponse`、`StreamedResponse`、`BinaryFileResponse`。
- Service：处理业务校验、计算、事务和流程编排。复杂流程提取有业务含义的私有方法，事务内的锁、写入与日志保持在同一事务中。
- Dao：封装查询和持久化。单条查询返回明确模型或可空模型；集合、分页器和查询构造器声明类型，并用 PHPDoc 标注元素模型。需要通用 CRUD 时继承 `BaseDao`。
- Model：一表一个模型，中间表使用 Pivot；明确表名、主键、批量赋值字段、字段转换和关联类型。`@property` 以实际 Eloquent 转换为准，例如 `decimal:2` 返回字符串，未转换的日期字段不标注为 Carbon。

依赖按类型命名，例如 `$orderManagementDao`、`$attachmentService`、`$businessOperationLogDao`。局部变量使用 `$request`、`$validatedData`、`$allocation`、`$permission`、`$warehouseRecord` 等业务名称。避免 `$r`、`$d`、`$a` 和不明确的 `$service` / `$dao`。

数组键、请求参数、路由标识和返回字段是接口约定，代码整理不顺带改名。公开方法参数改名时，需要同时检查 PHP 命名参数调用。

## 方法与注释

优先让主方法呈现业务步骤，再把独立计算、校验或输出组装放到私有方法中。不要为了减少行数把不相关流程放到万能工具方法中。

现有示例：

- `OrderManagementService::rows()`：合并来源订单与 Invoice，分别统一字段，再筛选和排序。
- `OrderStatisticsService`：分摊累计、维度累计、趋势日期补齐、金额及占比展示分别处理。
- `SaSalesService`：按维度累计，按客服分摊，最后结算佣金和汇总指标。
- `PaypalService::receivedBaseline()`：解释历史账户缺少收款基线时的回溯规则。
- `WarehouseService`：商品校验和状态推导分开，更新与日志仍位于同一事务。

中文注释说明业务口径、边界条件、锁顺序和兼容原因。数组 PHPDoc 优先描述必要的结构；模型关联通过泛型和 `@property-read` 提供类型提示。避免跨循环保留可写引用。

所有 Controller、Service、Dao、Model 方法（包括私有辅助方法）均保留完整 PHPDoc：

- 首段写清业务用途；不能只写“处理数据”或重复英文方法名。
- 每个形参写 `@param 类型 $名称 中文含义`。日期说明格式及边界，金额说明币种，分页说明页码起点，版本说明并发用途；引用参数说明原地修改。
- 写 `@return 类型 中文含义`，说明分页字段、数组结构、空值或未匹配时的行为。模型集合尽量保留元素泛型；无返回值的方法使用 `@return void`。
- Controller 的请求字段说明应与实际校验规则同步；动态规则不能截断成无效片段。Service 和 Dao 注释描述各自接收的内部参数，不直接照搬 HTTP 字段。
- 需要跨层追踪时用 `@see XxxService::method()` 或 `@see XxxDao::method()` 链接具体实现。已有业务注释必须与实际实现核对，尤其软删除、角色保护、统计分界和图片返回方式。

文档完整性与 PHP 语法检查（不启动应用、不连接数据库）：

```sh
docker exec saveb-api-app php scripts/check-method-docs.php
```

## 格式与验证

多字段数组、复杂调用和查询链分行书写；格式由项目的 `pint.json` 统一。只处理目标目录：

```sh
docker exec saveb-api-app php vendor/bin/pint app/Controllers app/Services app/Dao app/Models
docker exec saveb-api-app php vendor/bin/pint --test app/Controllers app/Services app/Dao app/Models
```

纯统计边界测试不依赖数据库：

```sh
docker exec saveb-api-app php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/StatisticsReadabilityTest.php
```

集成回归分别使用 `phpunit-rbac.xml`、`phpunit-orders.xml`、`phpunit-dashboard.xml`、`phpunit-workbench.xml`、`phpunit-local.xml`。测试在随机 schema 中运行；不能使用 `migrate:fresh` 或重置现有业务库来验证代码整理。
