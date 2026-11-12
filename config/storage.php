<?php

declare(strict_types=1);

return [
    'default' => 'r2',
    'disks' => ['r2' => [
        'driver' => 's3',
        'key' => env('R2_ACCESS_KEY_ID', ''),
        'secret' => env('R2_SECRET_ACCESS_KEY', ''),
        'region' => 'auto',
        'endpoint' => 'https://' . env('R2_ACCOUNT_ID', '') . '.r2.cloudflarestorage.com',
        'bucket' => env('R2_BUCKET', 'drop'),
        'path_style' => true,
        'timeout' => 15,
    ]],
];
