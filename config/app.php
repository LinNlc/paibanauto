<?php

declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/../data/schedule.db',
    'session_name' => 'paiban_session',
    'shift_options' => ['白', '中1', '中2', '夜', '休息'],
    'site_name' => '排班助手',
    'sse_keepalive_sec' => 15,
    'password_cost' => 12,
];
