<?php

declare(strict_types=1);

namespace App;

final readonly class Settings
{
    public const UPLOAD_WINDOW_SECONDS = 3600;

    public function __construct(
        public string $url,
        #[\SensitiveParameter] public string $adminToken,
        #[\SensitiveParameter] public string $key,
        public int $maxFileBytes,
        public int $maxStoredBytes,
        public string $accountId,
        public string $bucket,
        #[\SensitiveParameter] public string $accessKey,
        #[\SensitiveParameter] public string $secretKey,
    ) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || (parse_url($url, PHP_URL_PATH) && parse_url($url, PHP_URL_PATH) !== '/')
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('APP_URL must be an http(s) origin without credentials, path, query, or fragment.');
        }
        if ($adminToken === '' || mb_strlen($adminToken) > 1024 || $key === '' || $maxFileBytes < 1 || $maxFileBytes > 5 * 1024 ** 3 || $maxStoredBytes < $maxFileBytes) {
            throw new \InvalidArgumentException('Admin token, app key, or upload limits are invalid.');
        }
    }

    public function origin(): string
    {
        return rtrim($this->url, '/');
    }

    public function secure(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    public function r2Configured(): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $this->accountId) === 1
            && $this->bucket !== '' && $this->accessKey !== '' && $this->secretKey !== '';
    }
}
