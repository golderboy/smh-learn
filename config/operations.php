<?php

declare(strict_types=1);

return [
    // DEV restore drill only; never returned by HTTP health checks or committed as a value.
    'dev_restore_password' => env('MARIADB_ROOT_PASSWORD'),
    'minimum_free_bytes' => (int) env('OPS_MINIMUM_FREE_BYTES', 536870912),
    'backup_max_age_hours' => (int) env('OPS_BACKUP_MAX_AGE_HOURS', 26),
    'http_metrics' => env('OPS_HTTP_METRICS', true),
];
