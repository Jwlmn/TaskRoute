<?php

return [
    'exception_sla' => [
        'default' => [
            'policy_minutes' => 30,
            'reminder_interval_minutes' => 30,
            'feedback_policy_minutes' => 30,
            'feedback_reminder_interval_minutes' => 30,
            'notice_templates' => [
                'sla_escalation' => '任务 {task_no} 异常待处理 {pending_minutes} 分钟，当前等级：{level_label}，请尽快处理。',
                'sla_reminder' => '任务 {task_no} 已持续待处理 {pending_minutes} 分钟（{level_label}），请尽快处理。',
                'feedback_sla_reminder' => '任务 {task_no} 距离上次反馈已 {feedback_pending_minutes} 分钟，超出反馈 SLA {feedback_overtime_minutes} 分钟，请尽快同步处理进展。',
            ],
            'assign_accounts' => [],
            'alert_levels' => [
                ['minutes' => 30, 'code' => 'timeout_30', 'label' => '临近超时', 'type' => 'primary'],
                ['minutes' => 60, 'code' => 'timeout_60', 'label' => '高优先级', 'type' => 'warning'],
                ['minutes' => 120, 'code' => 'timeout_120', 'label' => '严重超时', 'type' => 'danger'],
            ],
        ],
        'by_type' => [
            // 示例（按异常类型覆盖阈值）：
            // 'vehicle_breakdown' => [
            //     'policy_minutes' => 20,
            //     'feedback_policy_minutes' => 15,
            //     'notice_templates' => [
            //         'feedback_sla_reminder' => '【车辆故障】任务 {task_no} 已 {feedback_pending_minutes} 分钟未反馈，请立即同步处理。',
            //     ],
            //     'assign_accounts' => ['dispatcher'],
            //     'alert_levels' => [
            //         ['minutes' => 20, 'label' => '车辆故障临近超时', 'type' => 'warning'],
            //         ['minutes' => 45, 'label' => '车辆故障高优先级', 'type' => 'danger'],
            //     ],
            // ],
        ],
    ],
];
