<?php

declare(strict_types=1);

namespace Tests;

use App\Files;
use App\Ids;
use App\Settings;
use Crab\Testing\Api;

final class UploadTest extends DropTestCase
{
    public function testHomepageIsMinimalAndAdminIsUnlisted(): void
    {
        $page = $this->api->get('/')->assertStatus(200);
        self::assertStringContainsString('Drop files here', $page->body());
        self::assertStringContainsString('/assets/drop.css', $page->body());
        self::assertSame(1, substr_count($page->body(), 'rel="stylesheet"'));
        self::assertStringNotContainsString('/assets/design/', $page->body());
        self::assertStringContainsString('name="color-scheme" content="light dark"', $page->body());
        self::assertStringContainsString('data-max-bytes="2000000000"', $page->body());
        self::assertStringNotContainsString('deleted after 24 hours', $page->body());
        self::assertStringNotContainsString('backoffice', $page->body());
        self::assertStringNotContainsString('My files', $page->body());
        self::assertSame('same-origin', $page->header('Referrer-Policy'));
        self::assertStringNotContainsString('<header', $page->body());
        self::assertStringNotContainsString('<footer', $page->body());
        self::assertStringNotContainsString('less permanent', $page->body());
        self::assertSame('private, no-store', $page->header('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", $page->header('Content-Security-Policy'));
        $this->api->get('/robots.txt')->assertStatus(200);
        self::assertStringNotContainsString('backoffice', $this->api->get('/robots.txt')->body());
    }

    public function testInitiationAllocatesShortIdAndPrivateCompletionCapability(): void
    {
        $upload = $this->initiate();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{7}$/D', $upload['id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $upload['token']);
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        self::assertSame(hash('sha256', $upload['token']), $row['upload_token_hash']);
        self::assertStringNotContainsString('hello.txt', $row['object_key']);
        self::assertStringNotContainsString($upload['id'], $row['object_key']);
        $this->api->get('/' . $upload['id'])->assertStatus(404);
        $this->api->get('/' . $upload['id'] . '/download')->assertStatus(404);
    }

    public function testCompletionRequiresCorrectCapabilityAndActualObject(): void
    {
        $upload = $this->initiate();
        $path = '/api/uploads/' . $upload['id'] . '/complete';
        $this->api->post($path, ['token' => str_repeat('0', 64)])->assertStatus(404);
        $this->api->post($path, ['token' => $upload['token']])->assertStatus(409);
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        $this->objects->files[$row['object_key']] = 99;
        $this->api->post($path, ['token' => $upload['token']])->assertStatus(422);
        self::assertSame('pending', $this->db->table('files')->where('id', $upload['id'])->value('state'));
    }

    public function testReadyFileGetsExactly24HoursAndRetryDoesNotExtendIt(): void
    {
        $upload = $this->initiate();
        $this->clock->advance('+2 minutes');
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        $this->objects->files[$row['object_key']] = 5;
        $path = '/api/uploads/' . $upload['id'] . '/complete';
        $shared = $this->api->post($path, ['token' => $upload['token']])->assertStatus(200)->json();
        self::assertSame('2026-11-13T12:02:00+00:00', $shared['expires_at']);
        self::assertSame('http://127.0.0.1:8080/' . $upload['id'], $shared['url']);
        self::assertArrayNotHasKey('object_key', $shared);
        self::assertArrayNotHasKey('token', $shared);
        $this->clock->advance('+1 hour');
        self::assertSame($shared, $this->api->post($path, ['token' => $upload['token']])->assertStatus(200)->json());
    }

    public function testDownloadLinkCannotOutliveFileAndExpiryDoesNotDependOnCleanup(): void
    {
        $shared = $this->shared();
        $this->clock->advance('+23 hours 59 minutes 59 seconds');
        $this->api->get('/' . $shared['id'])->assertStatus(200);
        $this->api->get('/' . $shared['id'] . '/download')->assertStatus(302);
        self::assertSame(strtotime($shared['expires_at']), $this->objects->downloads[0]['until']);
        $this->clock->advance('+1 second');
        $this->api->get('/' . $shared['id'])->assertStatus(404);
        $this->api->get('/' . $shared['id'] . '/download')->assertStatus(404);
        self::assertSame(1, $this->db->table('files')->count());
        self::assertCount(1, $this->objects->downloads);
    }

    public function testPendingAndDelayedCompletionCannotReviveExpiredUploads(): void
    {
        $upload = $this->initiate();
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        $this->objects->files[$row['object_key']] = 5;
        $this->objects->onSize = fn() => $this->clock->advance('+1 hour');
        $this->api->post('/api/uploads/' . $upload['id'] . '/complete', ['token' => $upload['token']])->assertStatus(410);
        self::assertSame('pending', $this->db->table('files')->where('id', $upload['id'])->value('state'));
    }

    public function testUploadValidationAndOriginProtection(): void
    {
        $this->api->post('/api/uploads', ['name' => '', 'size' => 2])->assertStatus(422);
        $this->api->post('/api/uploads', ['name' => 'a', 'size' => -1])->assertStatus(422);
        $this->api->post('/api/uploads', ['name' => str_repeat('a', 256), 'size' => 2])->assertStatus(422);
        $this->api->post('/api/uploads', ['name' => 'a', 'size' => 2000000001])->assertStatus(413);
        $this->api->post('/api/uploads', ['name' => 'a'])->assertStatus(422);
        $this->api->post('/api/uploads', ['name' => 'a', 'size' => 1, 'extra' => 'x'])->assertStatus(422);
        (new Api($this->app))->post('/api/uploads', ['name' => 'a', 'size' => 1])->assertStatus(403);
        $this->api->withHeaders(['Origin' => 'https://evil.example'])->post('/api/uploads', ['name' => 'a', 'size' => 1])->assertStatus(403);
        $this->api->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->post('/api/uploads', ['name' => 'a', 'size' => 1])->assertStatus(403);
        self::assertSame(0, $this->db->table('files')->count());
    }

    public function test2GBFileIsAcceptedAndHasTimeToUpload(): void
    {
        $shared = $this->shared('large.bin', 2000000000);
        self::assertSame(2000000000, $shared['size']);
        self::assertSame(2000000000, $this->db->table('files')->where('id', $shared['id'])->value('size'));
        $upload = $this->initiate('slow.bin', 2000000000);
        self::assertSame($this->clock->now()->getTimestamp() + 3600, strtotime($upload['upload_until']));
        $this->clock->advance('+30 minutes');
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        $this->objects->files[$row['object_key']] = 2000000000;
        $this->api->post('/api/uploads/' . $upload['id'] . '/complete', ['token' => $upload['token']])->assertStatus(200);
    }

    public function testZeroByteFileIsShareable(): void
    {
        $shared = $this->shared('empty.txt', 0);
        self::assertSame(0, $shared['size']);
        $this->api->get('/' . $shared['id'])->assertStatus(200);
    }

    public function testFilenamesAreEscapedAndNeverExecutable(): void
    {
        $name = '\"><img src=x onerror=alert(1)>.txt';
        $shared = $this->shared($name);
        $page = $this->api->get('/' . $shared['id'])->assertStatus(200)->body();
        self::assertStringNotContainsString('<img', $page);
        self::assertStringContainsString('&lt;img', $page);
        self::assertStringNotContainsString('<img', $this->admin()->get('/backoffice')->assertStatus(200)->body());
        $shared = $this->shared("folder\\report\r\n.txt");
        self::assertSame('report.txt', $shared['name']);
    }

    public function testSigningFailureReleasesReservation(): void
    {
        $this->objects->failUpload = true;
        $this->api->post('/api/uploads', ['name' => 'a.txt', 'size' => 1])->assertStatus(500);
        self::assertSame(0, $this->db->table('files')->count());
    }

    public function testGlobalQuotaIsCheckedBeforeCreatingReservation(): void
    {
        $this->initiate('first.txt', 5);
        $settings = new Settings('http://127.0.0.1:8080', 'sample', 'test', 6, 6, '', 'drop', '', '');
        $files = new Files($this->db, $settings, new Ids(), $this->clock, $this->objects);
        try {
            $files->initiate('second.txt', 2);
            self::fail('The reservation should exceed the global quota.');
        } catch (\Crab\Http\HttpException $error) {
            self::assertSame(503, $error->status);
        }
        self::assertSame(1, $this->db->table('files')->count());
    }

    public function testUploadsHaveNoHourlyOrCompletionCountLimit(): void
    {
        for ($i = 0; $i < 131; $i++) {
            $this->shared('file-' . $i . '.txt');
        }
        self::assertSame(131, $this->db->table('files')->where('state', 'ready')->count());
        self::assertSame(0, $this->db->table('rate_limits')->count());
    }

    public function testIdsAreShortAlphanumericAndCaseSensitive(): void
    {
        $ids = new Ids();
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $id = $ids->next();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{7}$/D', $id);
            $seen[$id] = true;
        }
        self::assertCount(500, $seen);
        self::assertFalse(Ids::valid('ab-c_12'));
        self::assertFalse(Ids::valid("abc1234\n"));
    }
}
