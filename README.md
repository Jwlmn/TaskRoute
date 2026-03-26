# TaskRoute

TaskRoute 是一个多行业适用的中文智能调度平台，当前仓库采用统一权限体系下的双终端架构：

- `backend`：Laravel 12 API 服务
- `frontend-pc`：PC 管理门户（Vue 3 + Element Plus）
- `frontend-mobile`：移动端门户（Vue 3 + Element Plus，手机优先）

## 技术栈

- Laravel 12
- Vue 3
- Element Plus（默认蓝白主题）
- PostgreSQL（Docker `latest`）
- Redis（Docker `latest`）

## 本地启动

1. 启动数据库和缓存

```bash
docker compose up -d postgres redis
```

2. 初始化后端

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

3. 启动 PC 端

```bash
cd frontend-pc
npm install
npm run dev
```

4. 启动移动端

```bash
cd frontend-mobile
npm install
npm run dev
```

## 当前已落地的开发骨架（一阶段）

- 多角色基础：`admin`、`dispatcher`、`driver`
- 预计划单模型与接口：`pre_plan_orders`
- 派车任务模型与接口：`dispatch_tasks`
- 任务与订单关联：`dispatch_task_orders`
- 任务节点与轨迹：`task_waypoints`、`driver_locations`
- 电子单据：`electronic_documents`
- 禁混与承运规则：`cargo_incompatibilities`、`vehicle_cargo_rules`
- API 入口：`/api/v1/meta`、`/api/v1/pre-plan-order/list`、`/api/v1/dispatch-task/list`
- 鉴权与权限：Sanctum Token + `role` 中间件（`admin`/`dispatcher`/`driver`）
- 登录安全：图片验证码登录（不开放注册）
- 智能派单：`/api/v1/dispatch/preview`、`/api/v1/dispatch/create-tasks`
- 用户管理：管理员账号分配与维护（`/api/v1/user/list`、`/api/v1/user/create`）
- 资源维护模块：
- 车辆资源：`/api/v1/resource/vehicle/list|create|detail|update`
- 人员资源：`/api/v1/resource/personnel/list|create|detail|update`
- 站点资源（提货点/收货点）：`/api/v1/resource/site/list|create|detail|update`
- 统一权限体系：PC 与移动端使用同一账号、同一权限模型
- PC 端：面向管理与调度操作（`frontend-pc`）
- 移动端：面向任务执行与移动场景操作（`frontend-mobile`）

## 默认测试账号

- 管理员：`admin` / `admin`
- 调度员：`dispatcher` / `TaskRoute@123`
- 司机：`driver` / `TaskRoute@123`

登录流程：
1. 先调用 `GET /api/v1/auth/captcha` 获取验证码 `key` 与图片。
2. 调用 `POST /api/v1/auth/login` 时携带 `account`、`password`、`captcha_key`、`captcha_code`。

## Seeder 结构

已按模块拆分 Seeder，不再堆在 `DatabaseSeeder`：

- `UserAccountSeeder`：系统账号基础数据
- `CargoCategorySeeder`：货品分类基础数据
- `VehicleResourceSeeder`：车辆资源基础数据
- `LogisticsSiteSeeder`：站点资源基础数据
- `CargoRuleSeeder`：禁混规则与承运规则
- `PrePlanOrderSeeder`：预计划单基础样例
- `MockDataSeeder`：通过 Factory 扩展 mock 数据

对应 Factory：

- `UserFactory`
- `CargoCategoryFactory`
- `VehicleFactory`
- `LogisticsSiteFactory`
- `PrePlanOrderFactory`

## 下一步建议

- 接入认证（Sanctum/JWT）和 RBAC 中间件
- 智能派单规则引擎（禁混、载重、时间窗、多订单拼单）
- 对接高德路径优化与司机定位上报接口
- B 端地图调度大屏与异常告警中心
