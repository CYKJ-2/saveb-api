# RBAC 检查与修复（2026-09-05）

本轮完成用户、角色、权限节点、用户角色授权、角色权限授权及登录鉴权链路修复。代码原件备份位于 `E:/wwwroot/rbac-backup-20260905`。

## 修复结果

| 问题 | 修复后的行为 |
| --- | --- |
| 主角色缺少权限预加载，登录菜单只计算多角色 | 登录、/me、接口校验统一使用 RbacService，合并主角色与多角色 |
| 禁用角色仍能授权，包括 super_admin | 仅启用角色参与授权；普通用户不接受权限节点中的通配符 |
| 超级管理员缺少新菜单 | 启用的 super_admin 从完整有效权限树读取菜单，不依赖关联表是否补齐 |
| 清空角色后主角色继续授权 | 全量角色同步同时维护 user_roles 和 role_id，空数组可以撤销全部角色 |
| 授权人为空，自己删除保护无效 | 使用 auth_user 请求属性；补齐授权人，阻止删除/禁用当前账户及移除自身 super_admin |
| 任意登录用户可重置别人的密码 | 重置密码要求 system.user.update；禁止操作权限高于自身的账户 |
| 用户编辑可绕过独立角色授权权限 | 创建/编辑中的角色字段也要求 system.user.assign_role，统一走角色授权 Service/Dao |
| 授权可以超出操作者自身权限 | 普通管理者不能授予自己不拥有的角色/权限；失败写入回滚 |
| 改密/禁用后旧 token 仍然有效 | 改密、禁用撤销用户 token；角色/权限变更在下一个 API 请求重新计算 |
| 权限树父节点循环、软删除无级联 | 拒绝自身/子孙/不存在/action 父节点；显式软删除整棵子树；禁用或删除祖先阻断子权限 |
| 角色删除仅检查主角色 | 同时检查多角色关联中的启用用户 |
| 权限提交缺失参数会清空、非法 ID 出错 | 必须显式提交列表，支持空数组；校验实际存在且未删除的权限 |
| 前端勾选菜单时误加入子权限 | 菜单与操作独立勾选，仅提交 getCheckedKeys 的实际结果；禁用节点保留显示与回显 |
| 授权页面依赖无关列表权限 | 角色选项、权限树和授权回显允许对应授权权限访问，保留原有读取权限 |
| 登录缓存、重复 401、动态路由残留 | 受保护页面导航重新读取 /me；退出清理状态与动态路由；401 本地清理不递归请求登出 |
| 用户筛选/分页不正确 | 支持用户名/姓名/启用与禁用筛选，保留分页总数，修复 Unix 时间戳显示 |
| 访问日志记录明文密码/token | 对请求体、认证头及响应敏感字段脱敏；敏感 SQL 不展开绑定值。历史日志未改写 |

新增 UserRole、RolePermission、AuditLog Model，以及 UserRoleService、UserRoleDao、AuditLogDao。前端新增独立“分配角色”抽屉，使用现有主题样式。

## 使用规则

- 普通账户要管理用户角色，需要 `system.user.assign_role`；要使用用户管理列表，还需 `system.user.list`。
- `system.user.create` / `system.user.update` 不再隐式允许授予角色。
- 菜单授权不会自动授予创建、编辑、删除按钮。需要哪些操作，明确勾选相应操作权限。
- 只分配子权限时，系统补齐祖先菜单用于导航，不授予兄弟操作权限。
- 用户 `role_id` 是完整角色集合中的兼容主角色。`PUT /users/{id}/roles` 替换完整集合；旧单角色 PUT 替换为一个角色。
- 管理者修改自身权限后立即刷新前端状态；其他已登录用户在下次页面导航刷新菜单和按钮，接口权限在下次请求生效。
- 已定向执行 `2026_09_05_230000_add_user_role_assignment_permission.php`，仅补入缺少的用户角色授权权限点，没有重跑历史业务迁移，也未修改现有账户的角色分配。

## 验证

```text
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
# 14 tests, 64 assertions，全部通过；每项使用独立 PostgreSQL schema，结束后删除。

docker exec saveb-api-app php vendor/bin/phpunit --filter ExampleTest
# 2 tests, 2 assertions，全部通过；APP_KEY 仅设置在测试配置中。

cd E:/wwwroot/saveb-admin
npm run build
# 通过；仍有原工程的 Vite CJS/大分包提示。

docker exec saveb-api-app php scripts/audit_rbac.php
# 只读：检查有效权限、缺失接口权限码及权限树异常。
```

浏览器验证：超级管理员登录、菜单加载、用户分页总数、用户角色授权回显、角色列表。自动化 API 测试验证授权保存、清空、撤权、禁用、越权拒绝与事务回滚。

## 范围与后续

本地现有用户 ID 2 的 test 角色有用户列表/创建/编辑/删除权限，但没有新增的用户角色分配权限；保留其原有授权，若需分配角色，应由超级管理员明确勾选。

只读检查还发现订单接口使用的 `system.order.list/create/update/delete` 四个权限码在当前数据库缺少节点，普通角色暂时无法获授这些订单接口权限。本轮优先保证用户/RBAC 链路，未擅自补建订单菜单或授予订单业务权限。订单、发票等业务功能不属于此次回归覆盖范围。

当前 saveb-admin 工程是 Vue 3 + Element Plus，沿用 Vben 风格主题；本轮未进行整套 Vben monorepo 迁移。
