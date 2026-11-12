<?php

declare(strict_types=1);

namespace App;

use App\Storage\Objects;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;
use Psr\Clock\ClockInterface;

final readonly class Files
{
    public function __construct(private Database $db, private Settings $settings, private Ids $ids, private ClockInterface $clock, private Objects $objects) {}

    /** @return array<string, mixed> */
    public function initiate(string $name, int $size): array
    {
        if ($size > $this->settings->maxFileBytes) {
            throw new HttpException(413, 'This file is larger than the upload limit.');
        }
        // A display name only, never an object path. Strip control/bidi characters and client directory names.
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new HttpException(422, 'Please give the file a name.');
        }
        $now = $this->clock->now()->getTimestamp();
        $token = bin2hex(random_bytes(32));
        $file = $this->db->transaction(function (Connection $tx) use ($name, $size, $now, $token): array {
            $used = (int) $tx->table('files')->sum('size');
            if ($size > $this->settings->maxStoredBytes - $used || $tx->table('files')->count() >= 10000) {
                throw new HttpException(503, 'Storage is temporarily full. Please try again later.');
            }
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $file = [
                    'id' => $this->ids->next(),
                    'object_key' => 'files/' . bin2hex(random_bytes(24)),
                    'name' => $name, 'size' => $size,
                    'upload_token_hash' => hash('sha256', $token),
                    'state' => 'pending', 'upload_until' => $now + Settings::UPLOAD_WINDOW_SECONDS,
                    'cleanup_after' => $now + Settings::UPLOAD_WINDOW_SECONDS + 600, 'created_at' => $now, 'expires_at' => null,
                ];
                if ($tx->table('files')->insertOrIgnore($file) === 1) {
                    return $file;
                }
            }
            throw new \RuntimeException('Unable to allocate a sharing ID.');
        });
        try {
            $upload = $this->objects->upload($file['object_key'], $size, $file['upload_until']);
        } catch (\Throwable $error) {
            // No upload URL was issued, so the reservation is safe to remove.
            $this->db->table('files')->where('id', $file['id'])->delete();
            throw $error;
        }
        return ['id' => $file['id'], 'token' => $token, 'upload' => $upload, 'upload_until' => gmdate('c', $file['upload_until'])];
    }

    /** @return array<string, mixed> */
    public function complete(string $id, #[\SensitiveParameter] string $token): array
    {
        $file = $this->find($id);
        if ($file === null || !hash_equals($file['upload_token_hash'], hash('sha256', $token))) {
            throw new HttpException(404, 'This upload was not found.');
        }
        $now = $this->clock->now()->getTimestamp();
        if ($file['state'] === 'ready') {
            if ((int) $file['expires_at'] <= $now) {
                throw new HttpException(410, 'This file has expired.');
            }
            return $this->shared($file);
        }
        if ((int) $file['upload_until'] <= $now) {
            throw new HttpException(410, 'This upload has expired. Please upload the file again.');
        }
        $size = $this->objects->size($file['object_key']);
        if ($size === null) {
            throw new HttpException(409, 'The upload has not finished yet.');
        }
        if ($size !== (int) $file['size']) {
            throw new HttpException(422, 'The uploaded size does not match the file. Please upload it again.');
        }
        // Recheck the clock after the R2 request. A delayed completion may never revive an expired upload.
        $now = $this->clock->now()->getTimestamp();
        $this->db->execute(
            "UPDATE files SET state = 'ready', created_at = ?, expires_at = ?, cleanup_after = ? WHERE id = ? AND state = 'pending' AND upload_until > ?",
            [$now, $now + 86400, $now + 86400, $id, $now],
        );
        $file = $this->find($id);
        if ($file === null || $file['state'] !== 'ready' || (int) $file['expires_at'] <= $now) {
            throw new HttpException(410, 'This upload has expired.');
        }
        return $this->shared($file);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return Ids::valid($id) ? $this->db->table('files')->where('id', $id)->first() : null;
    }

    /** @return array<string, mixed>|null */
    public function available(string $id): ?array
    {
        $file = $this->find($id);
        return $file !== null && $file['state'] === 'ready' && (int) $file['expires_at'] > $this->clock->now()->getTimestamp() ? $file : null;
    }

    public function download(string $id): string
    {
        $file = $this->available($id);
        if ($file === null) {
            throw new HttpException(404, 'This file has expired or does not exist.');
        }
        $now = $this->clock->now()->getTimestamp();
        return $this->objects->download($file['object_key'], $file['name'], min($now + 60, (int) $file['expires_at']));
    }

    /** @return list<array<string, mixed>> */
    public function current(int $page): array
    {
        return $this->db->table('files')->where('state', 'ready')
            ->where('expires_at', '>', $this->clock->now()->getTimestamp())
            ->orderBy('created_at', 'desc')->orderBy('id')->limit(51)->offset(($page - 1) * 50)->get();
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    private function shared(array $file): array
    {
        return [
            'id' => $file['id'], 'name' => $file['name'], 'size' => (int) $file['size'],
            'url' => $this->settings->origin() . '/' . $file['id'],
            'expires_at' => gmdate('c', (int) $file['expires_at']),
        ];
    }
}
