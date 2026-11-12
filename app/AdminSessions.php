<?php

declare(strict_types=1);

namespace App;

use Crab\Contracts\Database\Database;
use Crab\Contracts\Http\Request;
use Crab\Http\Cookie;
use Psr\Clock\ClockInterface;

final readonly class AdminSessions
{
    public const COOKIE = 'drop_admin';

    public function __construct(private Database $db, private Settings $settings, private ClockInterface $clock) {}

    public function authenticated(Request $request): bool
    {
        $token = $request->cookie(self::COOKIE, '');
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }
        $row = $this->db->table('admin_sessions')->where('token_hash', hash('sha256', $token))->first();
        return $row !== null && (int) $row['expires_at'] > $this->clock->now()->getTimestamp()
            && hash_equals($row['credential_hash'], $this->fingerprint());
    }

    public function login(#[\SensitiveParameter] string $token, Request $request): ?Cookie
    {
        if (!hash_equals(hash('sha256', $this->settings->adminToken), hash('sha256', $token))) {
            return null;
        }
        $this->logout($request);
        $session = bin2hex(random_bytes(32));
        $this->db->table('admin_sessions')->insert([
            'token_hash' => hash('sha256', $session), 'credential_hash' => $this->fingerprint(),
            'expires_at' => $this->clock->now()->getTimestamp() + 43200,
        ]);
        return new Cookie(self::COOKIE, $session, maxAge: 43200, path: '/backoffice', secure: $this->settings->secure(), sameSite: 'Strict');
    }

    public function logout(Request $request): Cookie
    {
        $token = $request->cookie(self::COOKIE, '');
        if (is_string($token)) {
            $this->db->table('admin_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }
        return Cookie::forget(self::COOKIE, '/backoffice');
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', $this->settings->adminToken, $this->settings->key);
    }
}
