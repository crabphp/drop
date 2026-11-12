<?php

declare(strict_types=1);

namespace Tests;

use App\Actions\Cleanup;

final class CleanupTest extends DropTestCase
{
    public function testCleanupDeletesExpiredFilesButKeepsCurrentFiles(): void
    {
        $old = $this->shared('old.txt');
        $this->clock->advance('+23 hours');
        $current = $this->shared('current.txt');
        $this->clock->advance('+1 hour');
        $cleanup = $this->app->container->get(Cleanup::class);
        self::assertSame(['deleted' => 1, 'failed' => 0], $cleanup->handle());
        self::assertNull($this->db->table('files')->where('id', $old['id'])->first());
        self::assertNotNull($this->db->table('files')->where('id', $current['id'])->first());
        self::assertCount(1, $this->objects->deleted);
        self::assertSame(['deleted' => 0, 'failed' => 0], $cleanup->handle());
    }

    public function testFailedDeletesRetainMetadataForRetry(): void
    {
        $this->shared();
        $this->clock->advance('+24 hours');
        $cleanup = $this->app->container->get(Cleanup::class);
        $this->objects->failDelete = true;
        self::assertSame(['deleted' => 0, 'failed' => 1], $cleanup->handle());
        self::assertSame(1, $this->db->table('files')->count());
        $this->objects->failDelete = false;
        self::assertSame(['deleted' => 1, 'failed' => 0], $cleanup->handle());
        self::assertSame(0, $this->db->table('files')->count());
    }

    public function testAbandonedUploadIsDeletedAfterGracePeriod(): void
    {
        $this->initiate();
        $cleanup = $this->app->container->get(Cleanup::class);
        $this->clock->advance('+1 hour');
        self::assertSame(['deleted' => 0, 'failed' => 0], $cleanup->handle());
        $this->clock->advance('+10 minutes');
        self::assertSame(['deleted' => 1, 'failed' => 0], $cleanup->handle());
    }

    public function testExpiredSessionsAndRateCountersArePurged(): void
    {
        $this->admin();
        self::assertSame(1, $this->db->table('admin_sessions')->count());
        self::assertSame(1, $this->db->table('rate_limits')->count());
        $this->clock->advance('+12 hours');
        $this->app->container->get(Cleanup::class)->handle();
        self::assertSame(0, $this->db->table('admin_sessions')->count());
        self::assertSame(0, $this->db->table('rate_limits')->count());
    }
}
