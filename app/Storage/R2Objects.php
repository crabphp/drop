<?php

declare(strict_types=1);

namespace App\Storage;

use App\Settings;
use Crab\Contracts\Storage\Storage;
use Crab\Http\HttpException;
use Crab\S3\Signature;
use Psr\Clock\ClockInterface;

final readonly class R2Objects implements Objects
{
    public function __construct(private Settings $settings, private Storage $storage, private ClockInterface $clock) {}

    public function upload(string $key, int $size, int $until): array
    {
        // Content-Length is signed but supplied automatically by the browser. A URL cannot upload more bytes
        // than reserved. If-None-Match makes the object immutable, even if an upload URL is replayed.
        $headers = ['content-type' => 'application/octet-stream', 'content-length' => (string) $size, 'if-none-match' => '*'];
        return [
            'url' => $this->signed('PUT', $key, $until, [], $headers),
            'headers' => ['Content-Type' => 'application/octet-stream', 'If-None-Match' => '*'],
        ];
    }

    public function size(string $key): ?int
    {
        $this->configured();
        return $this->storage->disk('r2')->file($key)?->size;
    }

    public function download(string $key, string $filename, int $until): string
    {
        return $this->signed('GET', $key, $until, [
            'response-content-type' => 'application/octet-stream',
            'response-content-disposition' => 'attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'response-cache-control' => 'private, no-store',
        ]);
    }

    public function delete(string $key): void
    {
        $this->configured();
        $this->storage->disk('r2')->delete($key);
    }

    /** @param array<string, string> $query @param array<string, string> $headers */
    private function signed(string $method, string $key, int $until, array $query = [], array $headers = []): string
    {
        $this->configured();
        $now = $this->clock->now();
        $seconds = $until - $now->getTimestamp();
        if ($seconds < 1 || $seconds > ($method === 'PUT' ? Settings::UPLOAD_WINDOW_SECONDS : 60)) {
            throw new HttpException(410, 'This link has expired.');
        }
        $host = $this->settings->accountId . '.r2.cloudflarestorage.com';
        $path = '/' . rawurlencode($this->settings->bucket) . '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $signature = new Signature($this->settings->accessKey, $this->settings->secretKey, 'auto');
        return 'https://' . $host . $path . '?' . $signature->query($method, $host, $path, $query, $seconds, $now, $headers);
    }

    private function configured(): void
    {
        if (!$this->settings->r2Configured()) {
            throw new HttpException(503, 'Uploads are not configured yet. Please try again later.');
        }
    }
}
