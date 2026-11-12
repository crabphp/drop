<?php

declare(strict_types=1);

namespace App\Storage;

interface Objects
{
    /** @return array{url: string, headers: array<string, string>} */
    public function upload(string $key, int $size, int $until): array;

    /** Returns the object size, or null when it does not exist. */
    public function size(string $key): ?int;

    public function download(string $key, string $filename, int $until): string;

    /** Idempotent, including for a key that never finished uploading. */
    public function delete(string $key): void;
}
