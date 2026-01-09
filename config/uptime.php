<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Projects to Monitor
    |--------------------------------------------------------------------------
    |
    | Define the list of projects/devices that should be monitored for uptime.
    | Each entry should have a name, IP address, and optional timeout.
    | 
    | For HMI/PLC devices using Modbus TCP:
    |   - Set 'type' => 'modbus'
    |   - Configure 'modbus_config' with port, unit_id, start_address, quantity
    |
    | For web applications:
    |   - Set 'type' => 'http' (or omit, it's the default)
    |   - IP should be full URL (e.g., http://example.com)
    |
    */

    'projects' => [
        // Example: HMI Device using Modbus TCP
        [
            'name' => 'DWP Monitoring',
            'project_group' => 'DWP',
            'ip' => '172.70.87.35',
            'timeout' => 10,
            'type' => 'dwp',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 199,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 1',
            'project_group' => 'IP_STC',
            'ip' => '172.70.66.245',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 3',
            'project_group' => 'IP_STC',
            'ip' => '172.70.87.146',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 4',
            'project_group' => 'IP_STC',
            'ip' => '172.70.86.135',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 6',
            'project_group' => 'IP_STC',
            'ip' => '172.70.87.247',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 7',
            'project_group' => 'IP_STC',
            'ip' => '172.70.87.248',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 8',
            'project_group' => 'IP_STC',
            'ip' => '172.70.87.91',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'IP STC Machine 9',
            'project_group' => 'IP_STC',
            'ip' => '172.70.87.140',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 503,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'RTC Machine 1',
            'project_group' => 'RTC',
            'ip' => '172.70.86.50',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 502,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'RTC Machine 3',
            'project_group' => 'RTC',
            'ip' => '172.70.66.192',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 502,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
        [
            'name' => 'RTC Machine 4',
            'project_group' => 'RTC',
            'ip' => '172.70.89.149',
            'timeout' => 10,
            'type' => 'modbus',
            'modbus_config' => [
                'port' => 502,           // Standard Modbus TCP port
                'unit_id' => 1,          // Slave/Unit ID
                'start_address' => 1,    // Starting register address
                'quantity' => 1,         // Number of registers to read
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Check Interval
    |--------------------------------------------------------------------------
    |
    | How often should uptime checks run (in minutes)
    | This is used for scheduled commands
    |
    */

    'check_interval' => env('UPTIME_CHECK_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Log Interval (Smart Logging)
    |--------------------------------------------------------------------------
    |
    | How often to save logs to database when status remains the same (in minutes)
    | Example: If set to 5, and status stays "online", it will only log every 5 minutes
    | But if status changes (online -> offline), it will log immediately
    | This prevents database bloat from redundant logs
    |
    */

    'log_interval_minutes' => env('UPTIME_LOG_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep uptime logs (in days)
    | Logs older than this will be cleaned up
    |
    */

    'retention_days' => env('UPTIME_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Alert Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure when to send alerts
    |
    */

    'alerts' => [
        'enabled' => env('UPTIME_ALERTS_ENABLED', false),
        'consecutive_failures' => 3, // Alert after X consecutive failures
        'email' => env('UPTIME_ALERT_EMAIL', 'admin@example.com'),
    ],

];
