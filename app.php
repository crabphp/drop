<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Setup;
use Crab\App;
use Crab\Http\TrustedProxies;
use Crab\S3\S3Plugin;
use Crab\Schedule\SchedulePlugin;

return App::configure(__DIR__)
    ->withPlugins([S3Plugin::class, SchedulePlugin::class])
    ->withSetup(Setup::class)
    // Only trust the loopback reverse proxy we control, never arbitrary forwarding headers.
    ->withMiddleware(prepend: [SecurityHeaders::class, new TrustedProxies(['127.0.0.1', '::1'])])
    ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
    ->create();
