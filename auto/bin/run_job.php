#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';
require_once __DIR__ . '/../engine.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "auto scheduler worker must run in CLI\n");
    exit(1);
}

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "missing job id\n");
    exit(1);
}

try {
    $pdo = db();
    auto_ensure_schema($pdo);
    auto_execute_job($pdo, $jobId);
} catch (Throwable $e) {
    fwrite(STDERR, 'failed to run job: ' . $e->getMessage() . "\n");
    exit(1);
}
