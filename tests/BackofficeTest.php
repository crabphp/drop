<?php

declare(strict_types=1);

namespace Tests;

use App\AdminSessions;
use App\Settings;
use Crab\Http\Request;
use Crab\Testing\Api;

final class BackofficeTest extends DropTestCase
{
    public function testUnauthenticatedPageDoesNotExposeFiles(): void
    {
        $this->shared('private-name.txt');
        $page = $this->api->get('/backoffice')->assertStatus(200)->body();
        self::assertStringContainsString('Access token', $page);
        self::assertStringNotContainsString('private-name.txt', $page);
        self::assertStringNotContainsString('sample', $page);
    }

    public function testTokenLoginUsesHashedRevocableCookieSession(): void
    {
        $this->shared();
        $response = $this->api->post('/backoffice', form: ['token' => 'sample'])->assertStatus(303);
        self::assertSame('/backoffice', $response->header('Location'));
        $cookie = $response->header('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/backoffice', $cookie);
        self::assertStringNotContainsString('sample', $cookie);
        preg_match('/drop_admin=([a-f0-9]+)/', $cookie, $match);
        self::assertSame(hash('sha256', $match[1]), $this->db->table('admin_sessions')->first()['token_hash']);
        $admin = $this->api->withHeaders(['Cookie' => explode(';', $cookie)[0]]);
        $page = $admin->get('/backoffice')->assertStatus(200)->body();
        self::assertStringContainsString('hello.txt', $page);
        self::assertStringNotContainsString('type="password"', $page);
        self::assertStringNotContainsString('Delete file', $page);
        $admin->post('/backoffice/logout')->assertStatus(303);
        self::assertSame(0, $this->db->table('admin_sessions')->count());
        self::assertStringContainsString('Access token', $admin->get('/backoffice')->body());
    }

    public function testInvalidLoginAndCrossSiteMutationsAreDenied(): void
    {
        $this->api->post('/backoffice', form: ['token' => 'wrong'])->assertStatus(401);
        (new Api($this->app))->post('/backoffice', form: ['token' => 'sample'])->assertStatus(403);
        $this->api->withHeaders(['Origin' => 'https://evil.example'])->post('/backoffice', form: ['token' => 'sample'])->assertStatus(403);
        self::assertSame(0, $this->db->table('admin_sessions')->count());
        $admin = $this->admin();
        $admin->withHeaders(['Origin' => 'https://evil.example'])->post('/backoffice/logout')->assertStatus(403);
        self::assertSame(1, $this->db->table('admin_sessions')->count());
    }

    public function testLoginIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->api->post('/backoffice', ['token' => 'wrong'])->assertStatus(401);
        }
        $response = $this->api->post('/backoffice', ['token' => 'sample'])->assertStatus(429);
        self::assertNotSame('', $response->header('Retry-After'));
    }

    public function testExpiredSessionsAndExpiredFilesAreNotShown(): void
    {
        $this->shared('old.txt');
        $this->clock->advance('+23 hours');
        $admin = $this->admin();
        $this->shared('current.txt');
        $this->clock->advance('+1 hour');
        $page = $admin->get('/backoffice')->assertStatus(200)->body();
        self::assertStringNotContainsString('old.txt', $page);
        self::assertStringContainsString('current.txt', $page);
        $this->clock->advance('+11 hours');
        self::assertStringContainsString('Access token', $admin->get('/backoffice')->body());
    }

    public function testRotatingAdminTokenInvalidatesExistingSessions(): void
    {
        $response = $this->api->post('/backoffice', ['token' => 'sample'])->assertStatus(303);
        preg_match('/drop_admin=([a-f0-9]+)/', $response->header('Set-Cookie'), $match);
        $request = (new Request('GET', '/backoffice'))->withCookieParams(['drop_admin' => $match[1]]);
        $settings = new Settings('http://127.0.0.1:8080', 'rotated', 'test-key-not-a-secret', 2000000000, 10737418240, '', 'drop', '', '');
        $sessions = new AdminSessions($this->db, $settings, $this->clock);
        self::assertFalse($sessions->authenticated($request));
    }

    public function testPaginationAndOnlyReadyFilesAreListed(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->shared('file-' . $i . '.txt');
        }
        $this->initiate('not-completed.txt');
        $admin = $this->admin();
        $page = $admin->get('/backoffice')->assertStatus(200)->body();
        self::assertStringContainsString('Next', $page);
        self::assertStringNotContainsString('not-completed.txt', $page);
        self::assertStringContainsString('Previous', $admin->get('/backoffice', ['page' => 2])->assertStatus(200)->body());
        $admin->get('/backoffice', ['page' => 0])->assertStatus(422);
    }
}
