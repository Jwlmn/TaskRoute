<?php

return [
    'exception_sla' => [
        'default' => [
            'policy_minutes' => 30,
            'reminder_interval_minutes' => 30,
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
            //     'alert_levels' => [
            //         ['minutes' => 20, 'label' => '车辆故障临近超时', 'type' => 'warning'],
            //         ['minutes' => 45, 'label' => '车辆故障高优先级', 'type' => 'danger'],
            //     ],
            // ],
        ],
    ],
];
