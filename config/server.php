<?php

declare(strict_types=1);

return [
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8080'),
    'workers' => (int) env('SERVER_WORKERS', 1),
    'max_body' => 16 * 1024,
    'watch' => ['app', 'config', 'routes', 'resources', 'public', 'app.php'],
];
