<?php

declare(strict_types=1);

namespace App\Actions;

use App\Storage\Objects;
use Crab\Contracts\Database\Database;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class Cleanup
{
    public function __construct(private Database $db, private Objects $objects, private ClockInterface $clock, private LoggerInterface $logger) {}

    /** @return array{deleted: int, failed: int} */
    public function handle(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $deleted = 0;
        $failed = 0;
        $files = $this->db->table('files')->where('cleanup_after', '<=', $now)->orderBy('cleanup_after')->limit(200)->get();
        foreach ($files as $file) {
            try {
                // Never remove the row first: failures retain the object key for a safe retry next time.
                $this->objects->delete($file['object_key']);
                $deleted += $this->db->table('files')->where('id', $file['id'])->where('cleanup_after', '<=', $now)->delete();
            } catch (\Throwable) {
                $failed++;
                // Do not log signed URLs, credentials, filenames or SDK exception bodies.
                $this->logger->warning('Drop cleanup could not delete an expired object; it will retry.');
            }
        }
        $this->db->table('admin_sessions')->where('expires_at', '<=', $now)->delete();
        $this->db->table('rate_limits')->where('expires_at', '<=', $now)->delete();
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
