<?php

declare(strict_types=1);

namespace Tests;

use App\Storage\Objects;
use Crab\App;
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Testing\Api;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

abstract class DropTestCase extends TestCase
{
    protected App $app;
    protected Api $api;
    protected Database $db;
    protected FakeObjects $objects;
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        $this->app = require dirname(__DIR__) . '/app.php';
        $this->clock = new FrozenClock('2026-11-12T12:00:00Z');
        $this->objects = new FakeObjects();
        $this->app->container->instance(ClockInterface::class, $this->clock);
        $this->app->container->instance(Clock::class, $this->clock);
        $this->app->container->instance(Objects::class, $this->objects);
        $this->app->container->get(Migrator::class)->run();
        $this->db = $this->app->container->get(Database::class);
        $this->api = (new Api($this->app))->withHeaders(['Origin' => 'http://127.0.0.1:8080']);
    }

    /** @return array<string, mixed> */
    protected function initiate(string $name = 'hello.txt', int $size = 5): array
    {
        return $this->api->post('/api/uploads', ['name' => $name, 'size' => $size])->assertStatus(201)->json();
    }

    /** @return array<string, mixed> */
    protected function shared(string $name = 'hello.txt', int $size = 5): array
    {
        $upload = $this->initiate($name, $size);
        $row = $this->db->table('files')->where('id', $upload['id'])->first();
        $this->objects->files[$row['object_key']] = $size;
        return $this->api->post('/api/uploads/' . $upload['id'] . '/complete', ['token' => $upload['token']])->assertStatus(200)->json();
    }

    protected function admin(): Api
    {
        $response = $this->api->post('/backoffice', form: ['token' => 'sample'])->assertStatus(303);
        $cookie = explode(';', $response->header('Set-Cookie'))[0];
        return $this->api->withHeaders(['Cookie' => $cookie]);
    }
}
