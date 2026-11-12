<?php

declare(strict_types=1);

namespace App;

use App\Actions\Cleanup;
use App\Storage\Objects;
use App\Storage\R2Objects;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Crab\Contracts\Schedule\Schedule;

final class Setup implements Plugin
{
    public function register(Container $container): void
    {
        $container->singleton(Settings::class, static fn(): Settings => new Settings(
            (string) config('app.url'), (string) config('drop.admin_token'), (string) config('drop.key'),
            (int) config('drop.max_file_bytes'), (int) config('drop.max_stored_bytes'),
            (string) config('drop.r2_account_id'), (string) config('drop.r2_bucket'),
            (string) config('drop.r2_access_key_id'), (string) config('drop.r2_secret_access_key'),
        ));
        $container->singleton(Objects::class, R2Objects::class);
        $container->get(Schedule::class)->call(Cleanup::class)->everyMinutes(5)->name('drop-cleanup');
    }
}
