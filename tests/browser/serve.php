<?php

declare(strict_types=1);

// Isolated browser fixture. No .env credentials are read by the fake object store and no real R2 calls occur.
// Only uses the disposable storage/browser-test.sqlite database; never use this entrypoint in production.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Storage\Objects;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;

if (getenv('DB_DATABASE') !== dirname(__DIR__, 2) . '/storage/browser-test.sqlite') {
    fwrite(STDERR, "Browser fixture requires the absolute path to storage/browser-test.sqlite as DB_DATABASE.\n");
    exit(1);
}

$app = require dirname(__DIR__, 2) . '/app.php';
$app->container->get(Migrator::class)->run();
$db = $app->container->get(Database::class);
$app->container->instance(Objects::class, new class($db) implements Objects {
    public function __construct(private Database $db) {}
    public function upload(string $key, int $size, int $until): array
    {
        return ['url' => 'https://browser.r2.cloudflarestorage.com/drop/' . $key, 'headers' => ['Content-Type' => 'application/octet-stream', 'If-None-Match' => '*']];
    }
    public function size(string $key): ?int
    {
        // Simulate a finished upload. PHP tests cover absent/mismatched real object metadata separately.
        $size = $this->db->table('files')->where('object_key', $key)->value('size');
        return $size === null ? null : (int) $size;
    }
    public function download(string $key, string $filename, int $until): string
    {
        return 'https://browser.r2.cloudflarestorage.com/drop/download';
    }
    public function delete(string $key): void {}
});
exit($app->console->run(['start', '--single', '--address=tcp://127.0.0.1:8096']));
