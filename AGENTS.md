# 项目约定

- Laravel 后端，来源为 saveb-erp / saveb-source 的前后端分离重构。
- 新功能使用对应 Controller → Service → Dao → Model；每张新表建立 Model，中间表使用 Pivot Model。
- PHP 命名、方法拆分、类型与注释遵循 [CODE_STYLE.md](CODE_STYLE.md)，参考 novel-api 的业务可读性，保留 Laravel 的框架机制。
- Controller 负责参数校验和响应，Service 负责业务规则及事务，Dao 负责查询和写入。
- API 必须通过 auth.api 认证，管理操作使用 permission 中间件；统一使用 RbacService 计算有效权限。
- 当前用户取 request()->attributes->get('auth_user')，本项目的 Bearer 认证并未使用默认 session guard。
- 本地通过 Docker 运行。RBAC 回归：docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml。
- RBAC 测试仅使用随机 rbac_test_* schema。不要对现有业务库运行 migrate:fresh 或重置种子数据。
