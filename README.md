# TaskRoute

TaskRoute 是一个多行业适用的中文智能调度平台，当前仓库采用三端分层结构：

- `backend`：Laravel 12 API 服务
- `frontend-b`：B 端调度后台（Vue 3 + Element Plus）
- `frontend-c`：C 端客户门户（Vue 3 + Element Plus）

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

3. 启动 B 端

```bash
cd frontend-b
npm install
npm run dev
```

4. 启动 C 端

```bash
cd frontend-c
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
- API 入口：`/api/v1/meta`、`/api/v1/pre-plan-orders`、`/api/v1/dispatch-tasks`
- 鉴权与权限：Sanctum Token + `role` 中间件（`admin`/`dispatcher`/`driver`）
- 智能派单：`/api/v1/dispatch/preview`、`/api/v1/dispatch/create-tasks`

## 默认测试账号

- 管理员：`admin@taskroute.local` / `TaskRoute@123`
- 调度员：`dispatcher@taskroute.local` / `TaskRoute@123`
- 司机：`driver@taskroute.local` / `TaskRoute@123`

## 下一步建议

- 接入认证（Sanctum/JWT）和 RBAC 中间件
- 智能派单规则引擎（禁混、载重、时间窗、多订单拼单）
- 对接高德路径优化与司机定位上报接口
- B 端地图调度大屏与异常告警中心
