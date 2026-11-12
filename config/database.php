<?php

declare(strict_types=1);

return ['connections' => ['main' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE', storage_path('database.sqlite')),
]]];
