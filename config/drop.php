<?php

declare(strict_types=1);

return [
    'admin_token' => env('ADMIN_TOKEN', 'sample'),
    'key' => env('APP_KEY', 'local-development-only'),
    'max_file_bytes' => (int) env('MAX_FILE_BYTES', 2 * 1000 * 1000 * 1000),
    'max_stored_bytes' => (int) env('MAX_STORED_BYTES', 10 * 1024 * 1024 * 1024),
    'r2_account_id' => env('R2_ACCOUNT_ID', ''),
    'r2_bucket' => env('R2_BUCKET', 'drop'),
    'r2_access_key_id' => env('R2_ACCESS_KEY_ID', ''),
    'r2_secret_access_key' => env('R2_SECRET_ACCESS_KEY', ''),
];
