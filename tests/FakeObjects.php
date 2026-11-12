<?php

declare(strict_types=1);

namespace Tests;

use App\Storage\Objects;

final class FakeObjects implements Objects
{
    /** @var array<string, int> */
    public array $files = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<array{key: string, filename: string, until: int}> */
    public array $downloads = [];
    public bool $failDelete = false;
    public bool $failUpload = false;
    public ?\Closure $onSize = null;

    public function upload(string $key, int $size, int $until): array
    {
        if ($this->failUpload) {
            throw new \RuntimeException('Storage unavailable.');
        }
        return ['url' => 'https://example.r2.cloudflarestorage.com/drop/' . $key, 'headers' => ['Content-Type' => 'application/octet-stream', 'If-None-Match' => '*']];
    }

    public function size(string $key): ?int
    {
        if ($this->onSize !== null) {
            ($this->onSize)();
        }
        return $this->files[$key] ?? null;
    }

    public function download(string $key, string $filename, int $until): string
    {
        $this->downloads[] = ['key' => $key, 'filename' => $filename, 'until' => $until];
        return 'https://example.r2.cloudflarestorage.com/private-download';
    }

    public function delete(string $key): void
    {
        if ($this->failDelete) {
            throw new \RuntimeException('Storage unavailable.');
        }
        $this->deleted[] = $key;
        unset($this->files[$key]);
    }
}
