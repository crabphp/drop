<?php

declare(strict_types=1);

namespace App\Actions;

use App\Settings;
use Crab\Contracts\Database\Database;
use Crab\Http\Response;

final readonly class Readiness
{
    public function __construct(private Database $db, private Settings $settings) {}

    public function handle(): Response
    {
        try {
            foreach (['files', 'admin_sessions', 'rate_limits'] as $table) {
                $this->db->table($table)->limit(1)->get();
            }
            $database = true;
        } catch (\Throwable) {
            $database = false;
        }
        $configured = $this->settings->r2Configured();
        $ready = $database && $configured;
        return Response::json(['ready' => $ready, 'database' => $database, 'storage_configured' => $configured], $ready ? 200 : 503);
    }
}
