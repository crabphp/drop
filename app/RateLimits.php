<?php

declare(strict_types=1);

namespace App;

use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;
use Psr\Clock\ClockInterface;

final readonly class RateLimits
{
    public function __construct(private Database $db, private Settings $settings, private ClockInterface $clock) {}

    public function hit(string $scope, string $ip, int $limit, int $window): void
    {
        $now = $this->clock->now()->getTimestamp();
        $end = (intdiv($now, $window) + 1) * $window;
        // No raw visitor IPs in the database; shared atomic counters also work with several workers.
        $key = hash_hmac('sha256', $scope . ':' . $ip . ':' . $end, $this->settings->key);
        $this->db->execute(
            'INSERT INTO rate_limits (key, hits, expires_at) VALUES (?, 1, ?) ON CONFLICT(key) DO UPDATE SET hits = MIN(hits + 1, ?)',
            [$key, $end, $limit + 1],
        );
        $row = $this->db->first('SELECT hits FROM rate_limits WHERE key = ?', [$key]);
        if ((int) $row['hits'] > $limit) {
            throw new HttpException(429, 'Too many requests. Please try again later.', ['Retry-After' => (string) ($end - $now)]);
        }
    }
}
