<?php

namespace App\Services\Dispatch;

use App\Models\DispatchTask;
use App\Models\LogisticsSite;
use App\Models\SystemMessage;
use App\Models\User;
use Carbon\CarbonImmutable;

class ExceptionSlaService
{
    private const POLICY_MINUTES = 30;
    private const FEEDBACK_POLICY_MINUTES = 30;
    private const FEEDBACK_REMINDER_INTERVAL_MINUTES = 30;

    /**
     * @var array<int, array{minutes:int,code:string,label:string,type:string}>
     */
    private const ALERT_LEVELS = [
        ['minutes' => 30, 'code' => 'timeout_30', 'label' => '临近超时', 'type' => 'primary'],
        ['minutes' => 60, 'code' => 'timeout_60', 'label' => '高优先级', 'type' => 'warning'],
        ['minutes' => 120, 'code' => 'timeout_120', 'label' => '严重超时', 'type' => 'danger'],
    ];

    public function syncTaskExceptionSla(DispatchTask $task, bool $notifyOnEscalation = true): DispatchTask
    {
        $routeMeta = is_array($task->route_meta) ? $task->route_meta : [];
        $exception = is_array($routeMeta['exception'] ?? null) ? $routeMeta['exception'] : null;
        if (! $exception) {
            return $task;
        }

        $now = CarbonImmutable::now();
        $alertLevels = $this->resolveAlertLevels((string) ($exception['type'] ?? ''));
        $annotatedException = $this->annotateException($exception, $now);
        $assignedUser = null;
        $newAlertLevels = [];
        $shouldSendReminder = false;
        $shouldSendFeedbackReminder = false;
        $shouldPersist = false;

        if (($annotatedException['status'] ?? null) === 'pending') {
            $newAlertLevels = $this->resolveNewAlertLevels($annotatedException, $alertLevels);
            if ($newAlertLevels !== []) {
                $annotatedException = $this->appendAlertHistory($annotatedException, $newAlertLevels, $now);
                $shouldPersist = true;
            } else {
                $shouldSendReminder = $this->shouldSendReminder($annotatedException, $now);
                if ($shouldSendReminder) {
                    $annotatedException = $this->appendReminderHistory($annotatedException, $now);
                    $shouldPersist = true;
                }
            }

            $shouldSendFeedbackReminder = $this->shouldSendFeedbackReminder($annotatedException, $now);
            if ($shouldSendFeedbackReminder) {
                $annotatedException = $this->appendFeedbackReminderHistory($annotatedException, $now);
                $shouldPersist = true;
            }
        }

        if (($annotatedException['status'] ?? null) === 'pending') {
            [$annotatedException, $assignedUser, $assignedChanged] = $this->ensureAssignedHandler($task, $annotatedException, $now);
            if ($assignedChanged) {
                $shouldPersist = true;
            }
        }
        $annotatedException = $this->annotateException($annotatedException, $now);

        if ($shouldPersist) {
            $routeMeta['exception'] = $annotatedException;
            $task->route_meta = $routeMeta;
            $task->save();
            $task->refresh();
        } else {
            $routeMeta['exception'] = $annotatedException;
            $task->setAttribute('route_meta', $routeMeta);
        }

        if ($notifyOnEscalation && $newAlertLevels !== []) {
            $this->notifyEscalation($task, $annotatedException, $newAlertLevels);
        }
        if ($notifyOnEscalation && $shouldSendReminder) {
            $this->notifyReminder($task, $annotatedException);
        }
        if ($notifyOnEscalation && $shouldSendFeedbackReminder) {
            $this->notifyFeedbackReminder($task, $annotatedException);
        }
        if ($notifyOnEscalation && $assignedUser instanceof User) {
            $this->notifyAssignedHandler($task, $annotatedException, $assignedUser);
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array{0:array<string,mixed>,1:?User,2:bool}
     */
    private function ensureAssignedHandler(DispatchTask $task, array $exception, CarbonImmutable $now): array
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $levelCode = (string) ($sla['level_code'] ?? 'normal');
        if ($levelCode === '' || $levelCode === 'normal') {
            return [$exception, null, false];
        }

        $currentAssignedId = (int) ($exception['assigned_handler_id'] ?? 0);
        if ($currentAssignedId > 0) {
            $existing = User::query()->where('id', $currentAssignedId)->where('status', 'active')->first();
            if ($existing instanceof User) {
                return [$exception, null, false];
            }
        }

        $target = $this->resolveAssignmentTarget($task, (string) ($exception['type'] ?? ''));
        if (! $target) {
            return [$exception, null, false];
        }

        $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];
        $history[] = [
            'event' => 'sla_assign',
            'operator_id' => null,
            'operator_account' => 'system',
            'operator_name' => '系统',
            'assigned_handler_id' => (int) $target->id,
            'assigned_handler_account' => $target->account,
            'assigned_handler_name' => $target->name,
            'reason' => 'exception_sla_auto_assign',
            'occurred_at' => $now->toDateTimeString(),
        ];

        $exception['history'] = $history;
        $exception['assigned_handler_id'] = (int) $target->id;
        $exception['assigned_handler_account'] = $target->account;
        $exception['assigned_handler_name'] = $target->name;
        $exception['assigned_at'] = $now->toDateTimeString();
        $exception['assigned_reason'] = 'exception_sla_auto_assign';

        return [$exception, $target, true];
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    public function annotateException(array $exception, CarbonImmutable $now): array
    {
        $exceptionType = (string) ($exception['type'] ?? '');
        $policyMinutes = $this->resolvePolicyMinutes($exceptionType);
        $alertLevels = $this->resolveAlertLevels($exceptionType);
        $reminderIntervalMinutes = $this->resolveReminderIntervalMinutes($exceptionType);
        $feedbackPolicyMinutes = $this->resolveFeedbackPolicyMinutes($exceptionType);
        $feedbackReminderIntervalMinutes = $this->resolveFeedbackReminderIntervalMinutes($exceptionType);
        $reportedAt = $this->parseTime($exception['reported_at'] ?? null);
        $handledAt = $this->parseTime($exception['handled_at'] ?? null);
        $lastFeedbackAt = $this->parseTime($exception['last_feedback_at'] ?? null);
        $status = (string) ($exception['status'] ?? '');

        $pendingMinutes = 0;
        if ($reportedAt) {
            $pendingEnd = $status === 'handled' && $handledAt ? $handledAt : $now;
            $pendingMinutes = (int) floor(max(0, $reportedAt->diffInMinutes($pendingEnd)));
        }

        $deadlineAt = $reportedAt?->addMinutes($policyMinutes);
        $remainingMinutes = max(0, $policyMinutes - $pendingMinutes);
        $overtimeMinutes = max(0, $pendingMinutes - $policyMinutes);
        $level = $this->resolveLevelByMinutes($pendingMinutes, $alertLevels);
        $nextEscalationMinute = $this->resolveNextEscalationMinute($pendingMinutes, $alertLevels);
        $handledMinutes = null;
        if ($status === 'handled' && $reportedAt && $handledAt) {
            $handledMinutes = (int) floor(max(0, $reportedAt->diffInMinutes($handledAt)));
        }

        $feedbackPendingMinutes = null;
        if ($lastFeedbackAt) {
            $feedbackEnd = $status === 'handled' && $handledAt ? $handledAt : $now;
            $feedbackPendingMinutes = (int) floor(max(0, $lastFeedbackAt->diffInMinutes($feedbackEnd)));
        }
        $feedbackRemainingMinutes = $feedbackPendingMinutes !== null
            ? max(0, $feedbackPolicyMinutes - $feedbackPendingMinutes)
            : null;
        $feedbackIsOvertime = $feedbackPendingMinutes !== null
            ? $feedbackPendingMinutes >= $feedbackPolicyMinutes
            : false;
        $feedbackDeadlineAt = $lastFeedbackAt?->addMinutes($feedbackPolicyMinutes);

        $currentSla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $currentAlerts = collect($currentSla['alerted_levels'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $nextReminderMinutes = $this->resolveNextReminderMinutes(
            $status,
            (string) $level['code'],
            $currentSla,
            $now,
            $reminderIntervalMinutes
        );
        $feedbackNextReminderMinutes = $this->resolveFeedbackNextReminderMinutes(
            $status,
            $feedbackIsOvertime,
            $currentSla,
            $now,
            $feedbackReminderIntervalMinutes
        );

        $exception['sla'] = array_merge($currentSla, [
            'policy_minutes' => $policyMinutes,
            'pending_minutes' => $pendingMinutes,
            'remaining_minutes' => $remainingMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_overtime' => $pendingMinutes >= $policyMinutes,
            'deadline_at' => $deadlineAt?->toDateTimeString(),
            'level_code' => $level['code'],
            'level_label' => $level['label'],
            'level_type' => $level['type'],
            'next_escalation_minutes' => $nextEscalationMinute,
            'handled_minutes' => $handledMinutes,
            'handled_overtime' => $handledMinutes !== null ? $handledMinutes >= $policyMinutes : null,
            'alerted_levels' => $currentAlerts,
            'reminder_interval_minutes' => $reminderIntervalMinutes,
            'next_reminder_minutes' => $nextReminderMinutes,
            'reminder_count' => (int) ($currentSla['reminder_count'] ?? 0),
            'feedback_policy_minutes' => $feedbackPolicyMinutes,
            'feedback_pending_minutes' => $feedbackPendingMinutes,
            'feedback_remaining_minutes' => $feedbackRemainingMinutes,
            'feedback_is_overtime' => $feedbackIsOvertime,
            'feedback_deadline_at' => $feedbackDeadlineAt?->toDateTimeString(),
            'feedback_reminder_interval_minutes' => $feedbackReminderIntervalMinutes,
            'feedback_next_reminder_minutes' => $feedbackNextReminderMinutes,
            'feedback_reminder_count' => (int) ($currentSla['feedback_reminder_count'] ?? 0),
        ]);

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<int, array{minutes:int,code:string,label:string,type:string}>
     */
    private function resolveNewAlertLevels(array $exception, array $alertLevels): array
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $pendingMinutes = (int) ($sla['pending_minutes'] ?? 0);
        $alerted = collect($sla['alerted_levels'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return collect($alertLevels)
            ->filter(fn ($level) => $pendingMinutes >= $level['minutes'])
            ->reject(fn ($level) => in_array($level['code'], $alerted, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $exception
     * @param  array<int, array{minutes:int,code:string,label:string,type:string}>  $newAlertLevels
     * @return array<string, mixed>
     */
    private function appendAlertHistory(array $exception, array $newAlertLevels, CarbonImmutable $now): array
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];
        $alertedLevels = collect($sla['alerted_levels'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($newAlertLevels as $level) {
            $alertedLevels[] = $level['code'];
            $history[] = [
                'event' => 'sla_alert',
                'level_code' => $level['code'],
                'level_label' => $level['label'],
                'threshold_minutes' => $level['minutes'],
                'occurred_at' => $now->toDateTimeString(),
                'operator_id' => null,
                'operator_account' => 'system',
                'operator_name' => '系统',
            ];
        }

        $exception['history'] = $history;
        $exception['sla'] = array_merge($sla, [
            'alerted_levels' => array_values(array_unique($alertedLevels)),
            'last_alert_at' => $now->toDateTimeString(),
            'current_alert_level' => end($newAlertLevels)['code'] ?? ($sla['current_alert_level'] ?? null),
            'last_notice_at' => $now->toDateTimeString(),
        ]);

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    private function appendReminderHistory(array $exception, CarbonImmutable $now): array
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];
        $currentLevelLabel = (string) ($sla['level_label'] ?? '超时');

        $history[] = [
            'event' => 'sla_reminder',
            'level_code' => $sla['level_code'] ?? null,
            'level_label' => $currentLevelLabel,
            'occurred_at' => $now->toDateTimeString(),
            'operator_id' => null,
            'operator_account' => 'system',
            'operator_name' => '系统',
        ];

        $exception['history'] = $history;
        $exception['sla'] = array_merge($sla, [
            'last_notice_at' => $now->toDateTimeString(),
            'reminder_count' => (int) ($sla['reminder_count'] ?? 0) + 1,
        ]);

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>
     */
    private function appendFeedbackReminderHistory(array $exception, CarbonImmutable $now): array
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $history = is_array($exception['history'] ?? null) ? $exception['history'] : [];

        $history[] = [
            'event' => 'feedback_sla_reminder',
            'feedback_policy_minutes' => (int) ($sla['feedback_policy_minutes'] ?? self::FEEDBACK_POLICY_MINUTES),
            'feedback_pending_minutes' => (int) ($sla['feedback_pending_minutes'] ?? 0),
            'occurred_at' => $now->toDateTimeString(),
            'operator_id' => null,
            'operator_account' => 'system',
            'operator_name' => '系统',
        ];

        $exception['history'] = $history;
        $exception['sla'] = array_merge($sla, [
            'feedback_last_notice_at' => $now->toDateTimeString(),
            'feedback_reminder_count' => (int) ($sla['feedback_reminder_count'] ?? 0) + 1,
        ]);

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $exception
     * @param  array<int, array{minutes:int,code:string,label:string,type:string}>  $newAlertLevels
     */
    private function notifyEscalation(DispatchTask $task, array $exception, array $newAlertLevels): void
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $highestLevel = end($newAlertLevels);
        if (! is_array($highestLevel)) {
            return;
        }

        $title = match ($highestLevel['code']) {
            'timeout_120' => '异常任务严重超时预警',
            'timeout_60' => '异常任务高优先级预警',
            default => '异常任务超时预警',
        };

        $taskNo = (string) ($task->task_no ?? '-');
        $pendingMinutes = (int) ($sla['pending_minutes'] ?? 0);
        $levelLabel = (string) ($highestLevel['label'] ?? '异常预警');
        $content = $this->resolveNoticeContent((string) ($exception['type'] ?? ''), 'sla_escalation', [
            'task_no' => $taskNo,
            'pending_minutes' => $pendingMinutes,
            'level_label' => $levelLabel,
        ], "任务 {$taskNo} 异常待处理 {$pendingMinutes} 分钟，当前等级：{$levelLabel}，请尽快处理。");

        $recipientIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'dispatcher'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($recipientIds as $userId) {
            SystemMessage::query()->create([
                'user_id' => $userId,
                'message_type' => 'dispatch_notice',
                'title' => $title,
                'content' => $content,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'task_no' => $taskNo,
                    'exception_type' => $exception['type'] ?? null,
                    'sla_level_code' => $highestLevel['code'],
                    'sla_level_label' => $levelLabel,
                    'pending_minutes' => $pendingMinutes,
                    'alerted_levels' => collect($newAlertLevels)->pluck('code')->values()->all(),
                    'notice_type' => 'exception_sla',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function notifyReminder(DispatchTask $task, array $exception): void
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $taskNo = (string) ($task->task_no ?? '-');
        $pendingMinutes = (int) ($sla['pending_minutes'] ?? 0);
        $levelLabel = (string) ($sla['level_label'] ?? '超时');

        $title = '异常任务持续超时催办';
        $content = $this->resolveNoticeContent((string) ($exception['type'] ?? ''), 'sla_reminder', [
            'task_no' => $taskNo,
            'pending_minutes' => $pendingMinutes,
            'level_label' => $levelLabel,
        ], "任务 {$taskNo} 已持续待处理 {$pendingMinutes} 分钟（{$levelLabel}），请尽快处理。");

        $recipientIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'dispatcher'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($recipientIds as $userId) {
            SystemMessage::query()->create([
                'user_id' => $userId,
                'message_type' => 'dispatch_notice',
                'title' => $title,
                'content' => $content,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'task_no' => $taskNo,
                    'exception_type' => $exception['type'] ?? null,
                    'sla_level_code' => $sla['level_code'] ?? null,
                    'sla_level_label' => $levelLabel,
                    'pending_minutes' => $pendingMinutes,
                    'notice_type' => 'exception_sla_reminder',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function notifyAssignedHandler(DispatchTask $task, array $exception, User $assignedUser): void
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $taskNo = (string) ($task->task_no ?? '-');
        $levelLabel = (string) ($sla['level_label'] ?? '超时');

        SystemMessage::query()->create([
            'user_id' => (int) $assignedUser->id,
            'message_type' => 'dispatch_notice',
            'title' => '异常任务已自动指派给你',
            'content' => "任务 {$taskNo} 当前等级 {$levelLabel}，系统已自动指派你跟进处理。",
            'meta' => [
                'task_id' => (int) $task->id,
                'task_no' => $taskNo,
                'exception_type' => $exception['type'] ?? null,
                'sla_level_code' => $sla['level_code'] ?? null,
                'sla_level_label' => $levelLabel,
                'notice_type' => 'exception_sla_assign',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function notifyFeedbackReminder(DispatchTask $task, array $exception): void
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $taskNo = (string) ($task->task_no ?? '-');
        $pendingMinutes = (int) ($sla['feedback_pending_minutes'] ?? 0);
        $policyMinutes = max(1, (int) ($sla['feedback_policy_minutes'] ?? self::FEEDBACK_POLICY_MINUTES));
        $overtimeMinutes = max(0, $pendingMinutes - $policyMinutes);

        $title = '异常反馈超时催办';
        $content = $this->resolveNoticeContent((string) ($exception['type'] ?? ''), 'feedback_sla_reminder', [
            'task_no' => $taskNo,
            'feedback_pending_minutes' => $pendingMinutes,
            'feedback_overtime_minutes' => $overtimeMinutes,
            'feedback_policy_minutes' => $policyMinutes,
        ], "任务 {$taskNo} 距离上次反馈已 {$pendingMinutes} 分钟，超出反馈 SLA {$overtimeMinutes} 分钟，请尽快同步处理进展。");

        $recipientIds = collect([
            (int) ($exception['assigned_handler_id'] ?? 0),
        ])
            ->merge(
                User::query()
                    ->where('status', 'active')
                    ->whereIn('role', ['admin', 'dispatcher'])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            )
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($recipientIds as $userId) {
            SystemMessage::query()->create([
                'user_id' => $userId,
                'message_type' => 'dispatch_notice',
                'title' => $title,
                'content' => $content,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'task_no' => $taskNo,
                    'exception_type' => $exception['type'] ?? null,
                    'feedback_policy_minutes' => $policyMinutes,
                    'feedback_pending_minutes' => $pendingMinutes,
                    'feedback_overtime_minutes' => $overtimeMinutes,
                    'notice_type' => 'exception_feedback_sla',
                ],
            ]);
        }
    }

    /**
     * @return array{code:string,label:string,type:string}
     */
    private function resolveLevelByMinutes(int $pendingMinutes, array $alertLevels): array
    {
        $resolved = ['code' => 'normal', 'label' => '正常', 'type' => 'success'];
        foreach ($alertLevels as $level) {
            if ($pendingMinutes < $level['minutes']) {
                break;
            }
            $resolved = [
                'code' => (string) $level['code'],
                'label' => (string) $level['label'],
                'type' => (string) $level['type'],
            ];
        }

        return $resolved;
    }

    private function resolveNextEscalationMinute(int $pendingMinutes, array $alertLevels): ?int
    {
        foreach ($alertLevels as $level) {
            if ($pendingMinutes < $level['minutes']) {
                return $level['minutes'] - $pendingMinutes;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function shouldSendReminder(array $exception, CarbonImmutable $now): bool
    {
        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $levelCode = (string) ($sla['level_code'] ?? 'normal');
        if ($levelCode === '' || $levelCode === 'normal') {
            return false;
        }

        $alertedLevels = collect($sla['alerted_levels'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        if ($alertedLevels === []) {
            return false;
        }

        $interval = max(5, $this->resolveReminderIntervalMinutes((string) ($exception['type'] ?? '')));
        $lastNoticeAt = $this->parseTime($sla['last_notice_at'] ?? null);
        if (! $lastNoticeAt) {
            return true;
        }

        return $lastNoticeAt->diffInMinutes($now) >= $interval;
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function shouldSendFeedbackReminder(array $exception, CarbonImmutable $now): bool
    {
        if (($exception['status'] ?? null) !== 'pending') {
            return false;
        }

        $sla = is_array($exception['sla'] ?? null) ? $exception['sla'] : [];
        $feedbackPendingMinutes = $sla['feedback_pending_minutes'] ?? null;
        if (! is_numeric($feedbackPendingMinutes)) {
            return false;
        }
        if (! (bool) ($sla['feedback_is_overtime'] ?? false)) {
            return false;
        }

        $interval = max(5, $this->resolveFeedbackReminderIntervalMinutes((string) ($exception['type'] ?? '')));
        $lastNoticeAt = $this->parseTime($sla['feedback_last_notice_at'] ?? null);
        if (! $lastNoticeAt) {
            return true;
        }

        return $lastNoticeAt->diffInMinutes($now) >= $interval;
    }

    /**
     * @param  array<string, mixed>  $sla
     */
    private function resolveNextReminderMinutes(
        string $status,
        string $levelCode,
        array $sla,
        CarbonImmutable $now,
        int $interval
    ): ?int {
        if ($status !== 'pending' || $levelCode === '' || $levelCode === 'normal') {
            return null;
        }

        $lastNoticeAt = $this->parseTime($sla['last_notice_at'] ?? null);
        if (! $lastNoticeAt) {
            return $interval;
        }

        $elapsed = max(0, $lastNoticeAt->diffInMinutes($now));
        if ($elapsed >= $interval) {
            return 0;
        }

        return $interval - $elapsed;
    }

    /**
     * @param  array<string, mixed>  $sla
     */
    private function resolveFeedbackNextReminderMinutes(
        string $status,
        bool $feedbackIsOvertime,
        array $sla,
        CarbonImmutable $now,
        int $interval
    ): ?int {
        if ($status !== 'pending' || ! $feedbackIsOvertime) {
            return null;
        }

        $lastNoticeAt = $this->parseTime($sla['feedback_last_notice_at'] ?? null);
        if (! $lastNoticeAt) {
            return $interval;
        }

        $elapsed = max(0, $lastNoticeAt->diffInMinutes($now));
        if ($elapsed >= $interval) {
            return 0;
        }

        return $interval - $elapsed;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePolicyMinutes(string $exceptionType): int
    {
        $default = (int) config('dispatch.exception_sla.default.policy_minutes', self::POLICY_MINUTES);
        $typed = (int) config("dispatch.exception_sla.by_type.{$exceptionType}.policy_minutes", 0);

        return max(1, $typed > 0 ? $typed : $default);
    }

    /**
     * @return array<int, array{minutes:int,code:string,label:string,type:string}>
     */
    private function resolveAlertLevels(string $exceptionType): array
    {
        $raw = config("dispatch.exception_sla.by_type.{$exceptionType}.alert_levels");
        if (! is_array($raw) || $raw === []) {
            $raw = config('dispatch.exception_sla.default.alert_levels', self::ALERT_LEVELS);
        }

        $levels = collect($raw)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                $minutes = max(1, (int) ($item['minutes'] ?? 0));
                $code = trim((string) ($item['code'] ?? ''));
                $label = trim((string) ($item['label'] ?? ''));
                $type = trim((string) ($item['type'] ?? ''));

                return [
                    'minutes' => $minutes,
                    'code' => $code !== '' ? $code : "timeout_{$minutes}",
                    'label' => $label !== '' ? $label : $this->resolveDefaultLabel($minutes),
                    'type' => $type !== '' ? $type : $this->resolveDefaultTagType($minutes),
                ];
            })
            ->sortBy('minutes')
            ->values()
            ->all();

        if ($levels === []) {
            return self::ALERT_LEVELS;
        }

        return $levels;
    }

    private function resolveReminderIntervalMinutes(string $exceptionType): int
    {
        $default = (int) config('dispatch.exception_sla.default.reminder_interval_minutes', 30);
        $typed = (int) config("dispatch.exception_sla.by_type.{$exceptionType}.reminder_interval_minutes", 0);

        return max(5, $typed > 0 ? $typed : $default);
    }

    private function resolveFeedbackPolicyMinutes(string $exceptionType): int
    {
        $default = (int) config('dispatch.exception_sla.default.feedback_policy_minutes', self::FEEDBACK_POLICY_MINUTES);
        $typed = (int) config("dispatch.exception_sla.by_type.{$exceptionType}.feedback_policy_minutes", 0);

        return max(1, $typed > 0 ? $typed : $default);
    }

    private function resolveFeedbackReminderIntervalMinutes(string $exceptionType): int
    {
        $default = (int) config('dispatch.exception_sla.default.feedback_reminder_interval_minutes', self::FEEDBACK_REMINDER_INTERVAL_MINUTES);
        $typed = (int) config("dispatch.exception_sla.by_type.{$exceptionType}.feedback_reminder_interval_minutes", 0);

        return max(5, $typed > 0 ? $typed : $default);
    }

    /**
     * @param  array<string, int|string>  $variables
     */
    private function resolveNoticeContent(
        string $exceptionType,
        string $templateKey,
        array $variables,
        string $fallback
    ): string {
        $typedTemplate = trim((string) config("dispatch.exception_sla.by_type.{$exceptionType}.notice_templates.{$templateKey}", ''));
        $defaultTemplate = trim((string) config("dispatch.exception_sla.default.notice_templates.{$templateKey}", ''));
        $template = $typedTemplate !== '' ? $typedTemplate : ($defaultTemplate !== '' ? $defaultTemplate : $fallback);

        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    private function resolveAssignmentTarget(DispatchTask $task, string $exceptionType): ?User
    {
        $preferredAccounts = config("dispatch.exception_sla.by_type.{$exceptionType}.assign_accounts");
        if (! is_array($preferredAccounts) || $preferredAccounts === []) {
            $preferredAccounts = config('dispatch.exception_sla.default.assign_accounts', []);
        }

        $normalizedAccounts = collect($preferredAccounts)
            ->map(fn ($account) => trim((string) $account))
            ->filter()
            ->values()
            ->all();
        if ($normalizedAccounts !== []) {
            $candidates = User::query()
                ->whereIn('account', $normalizedAccounts)
                ->where('status', 'active')
                ->get()
                ->keyBy('account');

            foreach ($normalizedAccounts as $account) {
                $candidate = $candidates->get($account);
                if ($candidate instanceof User) {
                    return $candidate;
                }
            }
        }

        if ($task->dispatcher_id) {
            $dispatcher = User::query()
                ->where('id', (int) $task->dispatcher_id)
                ->where('status', 'active')
                ->first();
            if ($dispatcher instanceof User) {
                return $dispatcher;
            }
        }

        $siteId = $task->vehicle?->site_id;
        if (! $siteId && $task->vehicle_id) {
            $siteId = $task->vehicle()->value('site_id');
        }
        $regionCode = null;
        if ($siteId) {
            $regionCode = LogisticsSite::query()->where('id', (int) $siteId)->value('region_code');
        }

        $candidate = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dispatcher', 'admin'])
            ->orderBy('id')
            ->get()
            ->first(function (User $user) use ($siteId, $regionCode): bool {
                if ($user->role === 'admin') {
                    return true;
                }

                if ($siteId === null && $regionCode === null) {
                    return true;
                }

                $scope = $user->resolveDataScope();
                if (($scope['type'] ?? 'all') === 'all') {
                    return true;
                }
                if ($siteId && in_array((int) $siteId, $scope['site_ids'] ?? [], true)) {
                    return true;
                }
                if ($regionCode && in_array((string) $regionCode, $scope['region_codes'] ?? [], true)) {
                    return true;
                }

                return false;
            });

        return $candidate instanceof User ? $candidate : null;
    }

    private function resolveDefaultLabel(int $minutes): string
    {
        if ($minutes >= 120) {
            return '严重超时';
        }
        if ($minutes >= 60) {
            return '高优先级';
        }

        return '临近超时';
    }

    private function resolveDefaultTagType(int $minutes): string
    {
        if ($minutes >= 120) {
            return 'danger';
        }
        if ($minutes >= 60) {
            return 'warning';
        }

        return 'primary';
    }
}
