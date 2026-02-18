<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

json_response([
    'ok' => true,
    'ts' => time(),
    'php' => PHP_VERSION
]);
