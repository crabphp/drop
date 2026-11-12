<?php

declare(strict_types=1);

namespace Tests;

use App\Settings;
use App\Storage\R2Objects;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\Storage;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final class R2Test extends TestCase
{
    private function settings(): Settings
    {
        return new Settings('https://drop.daudau.cc', 'sample', 'test', 2000000000, 10737418240,
            str_repeat('a', 32), 'drop', 'test-access-key', 'test-secret-key');
    }

    public function testCrabS3SignsSizeBoundImmutableUploads(): void
    {
        $storage = $this->createStub(Storage::class);
        $clock = new FrozenClock('2026-11-12T12:00:00Z');
        $objects = new R2Objects($this->settings(), $storage, $clock);
        $upload = $objects->upload('files/random-object-key', 2000000000, $clock->now()->getTimestamp() + 3600);
        self::assertSame('https', parse_url($upload['url'], PHP_URL_SCHEME));
        self::assertSame(str_repeat('a', 32) . '.r2.cloudflarestorage.com', parse_url($upload['url'], PHP_URL_HOST));
        self::assertSame('/drop/files/random-object-key', parse_url($upload['url'], PHP_URL_PATH));
        parse_str(parse_url($upload['url'], PHP_URL_QUERY), $query);
        self::assertSame('3600', $query['X-Amz-Expires']);
        self::assertSame('content-length;content-type;host;if-none-match', $query['X-Amz-SignedHeaders']);
        self::assertStringContainsString('/auto/s3/aws4_request', $query['X-Amz-Credential']);
        self::assertSame('*', $upload['headers']['If-None-Match']);
        self::assertSame('application/octet-stream', $upload['headers']['Content-Type']);
        self::assertArrayNotHasKey('Content-Length', $upload['headers']);
        self::assertStringNotContainsString('test-secret-key', $upload['url']);
    }

    public function testDownloadForcesAttachmentAndNoCache(): void
    {
        $clock = new FrozenClock('2026-11-12T12:00:00Z');
        $objects = new R2Objects($this->settings(), $this->createStub(Storage::class), $clock);
        $url = $objects->download('files/key', 'report "résumé".html', $clock->now()->getTimestamp() + 1);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('1', $query['X-Amz-Expires']);
        self::assertSame('application/octet-stream', $query['response-content-type']);
        self::assertSame('private, no-store', $query['response-cache-control']);
        self::assertStringStartsWith('attachment;', $query['response-content-disposition']);
        self::assertStringContainsString(rawurlencode('report "résumé".html'), $query['response-content-disposition']);
    }

    public function testMetadataAndDeletionUseCrabStorageDisk(): void
    {
        $disk = $this->createMock(Disk::class);
        $disk->expects(self::once())->method('file')->with('files/key')->willReturn(new File('files/key', 42, new \DateTimeImmutable()));
        $disk->expects(self::once())->method('delete')->with('files/key');
        $storage = $this->createMock(Storage::class);
        $storage->expects(self::exactly(2))->method('disk')->with('r2')->willReturn($disk);
        $objects = new R2Objects($this->settings(), $storage, new FrozenClock());
        self::assertSame(42, $objects->size('files/key'));
        $objects->delete('files/key');
    }

    public function testUnconfiguredStorageIsUnavailableWithoutLeakingDetails(): void
    {
        $app = require dirname(__DIR__) . '/app.php';
        $app->container->get(Migrator::class)->run();
        $api = (new Api($app))->withHeaders(['Origin' => 'http://127.0.0.1:8080']);
        $api->get('/')->assertStatus(200);
        $api->get('/up')->assertStatus(200);
        $api->get('/ready')->assertStatus(503)->assertJson('database', true)->assertJson('storage_configured', false);
        $response = $api->post('/api/uploads', ['name' => 'test.txt', 'size' => 4])->assertStatus(503);
        self::assertStringNotContainsString('R2_SECRET', $response->body());
    }
}
