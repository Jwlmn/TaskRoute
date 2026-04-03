<?php

namespace Tests\Feature;

use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_report_exception_and_dispatcher_can_list_pending_exception_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '高架拥堵，预计延后30分钟',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'pending')
            ->assertJsonPath('route_meta.exception.type', 'traffic_jam');

        Sanctum::actingAs($dispatcher);
        $listResponse = $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
        ]);
        $listResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $taskId)
            ->assertJsonPath('data.0.route_meta.exception.description', '高架拥堵，预计延后30分钟')
            ->assertJsonPath('data.0.route_meta.exception.sla.policy_minutes', 30)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.assigned', 0)
            ->assertJsonPath('summary.unassigned', 1)
            ->assertJsonPath('summary.my', 0)
            ->assertJsonPath('summary.no_feedback', 1)
            ->assertJsonPath('summary.feedback_timeout', 0)
            ->assertJsonPath('assignee_stats', []);

        $this->assertIsString((string) $listResponse->json('data.0.route_meta.exception.sla.level_code'));
    }

    public function test_dispatcher_can_handle_exception_by_reassign_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $targetVehicle = Vehicle::query()
            ->where('id', '!=', $vehicle->id)
            ->where('status', 'idle')
            ->whereNotNull('driver_id')
            ->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId])->assertOk();
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/waypoint-arrive', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'vehicle_breakdown',
            'description' => '车辆故障，无法继续执行',
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'reassign',
            'reassign_vehicle_id' => $targetVehicle->id,
            'handle_note' => '改派备用车辆继续执行',
        ])->assertOk()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('vehicle_id', $targetVehicle->id)
            ->assertJsonPath('driver_id', $targetVehicle->driver_id)
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'reassign')
            ->assertJsonPath('route_meta.exception.previous_task_status', 'in_progress')
            ->assertJsonPath('route_meta.exception.current_task_status', 'assigned');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => 'idle',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $targetVehicle->id,
            'status' => 'busy',
        ]);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_dispatcher_handle_continue_keeps_accepted_status_for_accepted_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk()
            ->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '拥堵，申请继续执行',
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'continue',
            'handle_note' => '确认继续执行',
        ])->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'continue')
            ->assertJsonPath('route_meta.exception.previous_task_status', 'accepted')
            ->assertJsonPath('route_meta.exception.current_task_status', 'accepted');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => 'busy',
        ]);
    }

    public function test_dispatcher_handle_cancel_will_cancel_related_order_and_release_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId])->assertOk();
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/waypoint-arrive', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'customer_reject',
            'description' => '客户拒收，申请取消任务',
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'cancel',
            'handle_note' => '确认取消并重排计划',
        ])->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'cancel')
            ->assertJsonPath('route_meta.exception.current_task_status', 'cancelled');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => 'idle',
        ]);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_dispatcher_handle_exception_is_idempotent_for_same_action_but_rejects_different_action(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '幂等处理测试',
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'continue',
            'handle_note' => '继续执行',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'continue');

        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'continue',
            'handle_note' => '继续执行',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'continue');

        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'cancel',
            'handle_note' => '冲突处理',
        ])->assertStatus(422)->assertJsonPath('message', '当前异常已处理，请勿重复提交不同处理动作');
    }

    public function test_exception_list_will_trigger_sla_alert_message_once_per_level(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => 'SLA 预警测试',
        ])->assertOk();

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : [];
        $exception['reported_at'] = now()->subMinutes(61)->toDateTimeString();
        $routeMeta['exception'] = $exception;
        $task->route_meta = $routeMeta;
        $task->save();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
        ])->assertOk()
            ->assertJsonPath('data.0.route_meta.exception.sla.level_code', 'timeout_60');

        $firstNoticeCount = SystemMessage::query()
            ->where('meta->notice_type', 'exception_sla')
            ->count();
        $this->assertGreaterThan(0, $firstNoticeCount);

        // 重复查询不应重复发送同等级告警
        $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
        ])->assertOk();

        $secondNoticeCount = SystemMessage::query()
            ->where('meta->notice_type', 'exception_sla')
            ->count();
        $this->assertSame($firstNoticeCount, $secondNoticeCount);
    }

    public function test_exception_sla_can_use_type_specific_policy_config(): void
    {
        Config::set('dispatch.exception_sla.by_type.traffic_jam.policy_minutes', 45);
        Config::set('dispatch.exception_sla.by_type.traffic_jam.alert_levels', [
            ['minutes' => 45, 'code' => 'timeout_45', 'label' => '拥堵临近超时', 'type' => 'primary'],
            ['minutes' => 90, 'code' => 'timeout_90', 'label' => '拥堵严重超时', 'type' => 'danger'],
        ]);

        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '按类型SLA配置测试',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.sla.policy_minutes', 45);

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : [];
        $exception['reported_at'] = now()->subMinutes(46)->toDateTimeString();
        $routeMeta['exception'] = $exception;
        $task->route_meta = $routeMeta;
        $task->save();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
            'task_no' => $task->task_no,
        ])->assertOk()
            ->assertJsonPath('data.0.route_meta.exception.sla.policy_minutes', 45)
            ->assertJsonPath('data.0.route_meta.exception.sla.level_code', 'timeout_45')
            ->assertJsonPath('data.0.route_meta.exception.sla.level_label', '拥堵临近超时')
            ->assertJsonPath('data.0.route_meta.exception.sla.level_type', 'primary');
    }

    public function test_exception_sla_reminder_will_respect_interval_config(): void
    {
        Config::set('dispatch.exception_sla.default.reminder_interval_minutes', 20);

        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '催办间隔测试',
        ])->assertOk();

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : [];
        $exception['reported_at'] = now()->subMinutes(65)->toDateTimeString();
        $routeMeta['exception'] = $exception;
        $task->route_meta = $routeMeta;
        $task->save();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-list', ['status' => 'pending'])->assertOk();
        $initialReminderCount = SystemMessage::query()
            ->where('meta->notice_type', 'exception_sla_reminder')
            ->count();
        $this->assertSame(0, $initialReminderCount);

        $task->refresh();
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : [];
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $sla['last_notice_at'] = now()->subMinutes(21)->toDateTimeString();
        $exception['sla'] = $sla;
        $routeMeta['exception'] = $exception;
        $task->route_meta = $routeMeta;
        $task->save();

        $response = $this->postJson('/api/v1/dispatch-task/exception-list', ['status' => 'pending'])->assertOk()
            ->assertJsonPath('data.0.route_meta.exception.sla.reminder_interval_minutes', 20);
        $nextReminderMinutes = (int) $response->json('data.0.route_meta.exception.sla.next_reminder_minutes');
        $this->assertGreaterThanOrEqual(0, $nextReminderMinutes);
        $this->assertLessThanOrEqual(20, $nextReminderMinutes);
        $afterReminderCount = SystemMessage::query()
            ->where('meta->notice_type', 'exception_sla_reminder')
            ->count();
        $this->assertGreaterThan(0, $afterReminderCount);
    }

    public function test_exception_sla_will_auto_assign_handler_when_escalated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '自动指派测试',
        ])->assertOk();

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : [];
        $exception['reported_at'] = now()->subMinutes(35)->toDateTimeString();
        $routeMeta['exception'] = $exception;
        $task->route_meta = $routeMeta;
        $task->save();

        Sanctum::actingAs($dispatcher);
        $response = $this->postJson('/api/v1/dispatch-task/exception-list', ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.0.route_meta.exception.assigned_handler_id', $dispatcher->id)
            ->assertJsonPath('data.0.route_meta.exception.assigned_handler_account', $dispatcher->account);
        $nextReminderMinutes = (int) $response->json('data.0.route_meta.exception.sla.next_reminder_minutes');
        $this->assertGreaterThanOrEqual(0, $nextReminderMinutes);
        $this->assertLessThanOrEqual(30, $nextReminderMinutes);

        $this->assertDatabaseHas('system_messages', [
            'user_id' => $dispatcher->id,
            'message_type' => 'dispatch_notice',
        ]);
        $assignNoticeCount = SystemMessage::query()
            ->where('user_id', $dispatcher->id)
            ->where('meta->notice_type', 'exception_sla_assign')
            ->count();
        $this->assertGreaterThan(0, $assignNoticeCount);
    }

    public function test_admin_can_manually_assign_exception_handler(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($admin);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '手动改派责任人测试',
        ])->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/dispatch-task/exception-assign', [
            'task_id' => $taskId,
            'assigned_handler_id' => $dispatcher->id,
            'assign_note' => '请优先跟进',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.assigned_handler_id', $dispatcher->id)
            ->assertJsonPath('route_meta.exception.assigned_handler_account', $dispatcher->account)
            ->assertJsonPath('route_meta.exception.assigned_reason', 'manual_assign');

        $noticeCount = SystemMessage::query()
            ->where('user_id', $dispatcher->id)
            ->where('meta->notice_type', 'exception_manual_assign')
            ->count();
        $this->assertGreaterThan(0, $noticeCount);
    }

    public function test_exception_list_can_filter_assigned_to_me(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicles = Vehicle::query()->where('status', 'idle')->limit(2)->get();
        $orders = PrePlanOrder::query()->where('status', 'pending')->limit(2)->get();
        $this->assertCount(2, $vehicles);
        $this->assertCount(2, $orders);

        Sanctum::actingAs($admin);
        $taskIds = [];
        foreach ($orders as $index => $order) {
            $resp = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
                'assignments' => [[
                    'vehicle_id' => $vehicles[$index]->id,
                    'order_ids' => [$order->id],
                ]],
            ]);
            $resp->assertCreated();
            $taskIds[] = (int) $resp->json('created_task_ids.0');
        }

        foreach ($taskIds as $taskId) {
            $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
            $task->status = 'in_progress';
            $task->route_meta = array_merge(is_array($task->route_meta) ? $task->route_meta : [], [
                'exception' => [
                    'status' => 'pending',
                    'type' => 'traffic_jam',
                    'description' => '负责人筛选测试',
                    'reported_at' => now()->toDateTimeString(),
                    'history' => [],
                ],
            ]);
            $task->save();
        }

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/dispatch-task/exception-assign', [
            'task_id' => $taskIds[0],
            'assigned_handler_id' => $dispatcher->id,
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
            'assigned_to_me' => true,
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $taskIds[0])
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.assigned', 1)
            ->assertJsonPath('summary.unassigned', 0)
            ->assertJsonPath('summary.my', 1)
            ->assertJsonPath('summary.no_feedback', 1)
            ->assertJsonPath('summary.feedback_timeout', 0)
            ->assertJsonPath('assignee_stats.0.assigned_handler_id', $dispatcher->id)
            ->assertJsonPath('assignee_stats.0.pending_count', 1);

        $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
            'assigned_handler_id' => $dispatcher->id,
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $taskIds[0]);
    }

    public function test_admin_can_batch_assign_exception_handler(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicles = Vehicle::query()->where('status', 'idle')->limit(2)->get();
        $orders = PrePlanOrder::query()->where('status', 'pending')->limit(2)->get();
        $this->assertCount(2, $vehicles);
        $this->assertCount(2, $orders);

        Sanctum::actingAs($admin);
        $taskIds = [];
        foreach ($orders as $index => $order) {
            $resp = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
                'assignments' => [[
                    'vehicle_id' => $vehicles[$index]->id,
                    'order_ids' => [$order->id],
                ]],
            ]);
            $resp->assertCreated();
            $taskIds[] = (int) $resp->json('created_task_ids.0');
        }

        foreach ($taskIds as $taskId) {
            $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
            $task->status = 'in_progress';
            $task->route_meta = array_merge(is_array($task->route_meta) ? $task->route_meta : [], [
                'exception' => [
                    'status' => 'pending',
                    'type' => 'traffic_jam',
                    'description' => '批量改派责任人测试',
                    'reported_at' => now()->toDateTimeString(),
                    'history' => [],
                ],
            ]);
            $task->save();
        }

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/dispatch-task/exception-assign-batch', [
            'task_ids' => $taskIds,
            'assigned_handler_id' => $dispatcher->id,
            'assign_note' => '批量接管',
        ])->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('skipped_count', 0);

        foreach ($taskIds as $taskId) {
            $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
            $this->assertSame($dispatcher->id, data_get($task->route_meta, 'exception.assigned_handler_id'));
        }
    }

    public function test_admin_can_batch_remind_exception_handler(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $vehicles = Vehicle::query()->where('status', 'idle')->limit(2)->get();
        $orders = PrePlanOrder::query()->where('status', 'pending')->limit(2)->get();
        $this->assertCount(2, $vehicles);
        $this->assertCount(2, $orders);

        Sanctum::actingAs($admin);
        $taskIds = [];
        foreach ($orders as $index => $order) {
            $resp = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
                'assignments' => [[
                    'vehicle_id' => $vehicles[$index]->id,
                    'order_ids' => [$order->id],
                ]],
            ]);
            $resp->assertCreated();
            $taskIds[] = (int) $resp->json('created_task_ids.0');
        }

        foreach ($taskIds as $taskId) {
            $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
            $task->status = 'in_progress';
            $task->route_meta = array_merge(is_array($task->route_meta) ? $task->route_meta : [], [
                'exception' => [
                    'status' => 'pending',
                    'type' => 'traffic_jam',
                    'description' => '批量催办测试',
                    'reported_at' => now()->toDateTimeString(),
                    'history' => [],
                    'sla' => [
                        'reminder_count' => 0,
                    ],
                ],
            ]);
            $task->save();
        }

        $this->postJson('/api/v1/dispatch-task/exception-assign-batch', [
            'task_ids' => $taskIds,
            'assigned_handler_id' => $dispatcher->id,
            'assign_note' => '先指派再催办',
        ])->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('skipped_count', 0);

        $this->postJson('/api/v1/dispatch-task/exception-remind-batch', [
            'task_ids' => $taskIds,
            'remind_note' => '请 10 分钟内反馈处理进展',
        ])->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('skipped_count', 0);

        $reminderNoticeCount = SystemMessage::query()
            ->where('user_id', $dispatcher->id)
            ->where('meta->notice_type', 'exception_manual_reminder')
            ->count();
        $this->assertSame(2, $reminderNoticeCount);

        foreach ($taskIds as $taskId) {
            $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
            $this->assertSame(1, (int) data_get($task->route_meta, 'exception.sla.reminder_count'));
            $history = data_get($task->route_meta, 'exception.history', []);
            $this->assertTrue(collect($history)->contains(fn ($item) => data_get($item, 'event') === 'manual_reminder'));
        }
    }

    public function test_batch_remind_should_skip_tasks_in_cooldown_window(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $vehicle = Vehicle::query()->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($admin);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $task->status = 'in_progress';
        $task->route_meta = array_merge(is_array($task->route_meta) ? $task->route_meta : [], [
            'exception' => [
                'status' => 'pending',
                'type' => 'traffic_jam',
                'description' => '冷却窗口催办测试',
                'reported_at' => now()->subMinutes(40)->toDateTimeString(),
                'assigned_handler_id' => $dispatcher->id,
                'assigned_handler_account' => $dispatcher->account,
                'assigned_handler_name' => $dispatcher->name,
                'history' => [],
                'sla' => [
                    'level_code' => 'timeout_30',
                    'last_notice_at' => now()->subMinutes(5)->toDateTimeString(),
                    'reminder_interval_minutes' => 30,
                    'next_reminder_minutes' => 25,
                    'reminder_count' => 1,
                ],
            ],
        ]);
        $task->save();

        $this->postJson('/api/v1/dispatch-task/exception-remind-batch', [
            'task_ids' => [$taskId],
            'remind_note' => '冷却窗口内不应重复催办',
        ])->assertOk()
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('cooldown_count', 1);

        $noticeCount = SystemMessage::query()
            ->where('user_id', $dispatcher->id)
            ->where('meta->notice_type', 'exception_manual_reminder')
            ->count();
        $this->assertSame(0, $noticeCount);
    }

    public function test_admin_can_submit_exception_feedback(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $vehicle = Vehicle::query()->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($admin);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        $task = \App\Models\DispatchTask::query()->findOrFail($taskId);
        $task->status = 'in_progress';
        $task->route_meta = array_merge(is_array($task->route_meta) ? $task->route_meta : [], [
            'exception' => [
                'status' => 'pending',
                'type' => 'traffic_jam',
                'description' => '反馈闭环测试',
                'reported_at' => now()->toDateTimeString(),
                'assigned_handler_id' => $dispatcher->id,
                'assigned_handler_account' => $dispatcher->account,
                'assigned_handler_name' => $dispatcher->name,
                'history' => [],
            ],
        ]);
        $task->save();

        $feedbackContent = '已联系司机确认现场情况，预计 15 分钟内恢复通行。';
        $this->postJson('/api/v1/dispatch-task/exception-feedback', [
            'task_id' => $taskId,
            'feedback_content' => $feedbackContent,
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.last_feedback_content', $feedbackContent)
            ->assertJsonPath('route_meta.exception.last_feedback_by', $admin->id);

        $task->refresh();
        $history = data_get($task->route_meta, 'exception.history', []);
        $this->assertTrue(collect($history)->contains(function ($item) use ($feedbackContent, $admin): bool {
            return data_get($item, 'event') === 'manual_feedback'
                && data_get($item, 'operator_id') === $admin->id
                && data_get($item, 'feedback_content') === $feedbackContent;
        }));

        $this->assertDatabaseHas('system_messages', [
            'user_id' => $dispatcher->id,
            'message_type' => 'dispatch_notice',
        ]);
        $noticeCount = SystemMessage::query()
            ->where('user_id', $dispatcher->id)
            ->where('meta->notice_type', 'exception_manual_feedback')
            ->count();
        $this->assertSame(1, $noticeCount);
    }
}
