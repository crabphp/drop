<?php

declare(strict_types=1);

namespace App\Console;

use App\Actions\Cleanup;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class CleanupFiles implements Command
{
    public function __construct(private Cleanup $cleanup) {}

    public function description(): string
    {
        return 'Delete a batch of expired files and abandoned uploads from R2, then SQLite.';
    }

    public function handle(Input $input, Output $output): int
    {
        $result = $this->cleanup->handle();
        $output->line($result['deleted'] . ' files deleted; ' . $result['failed'] . ' failed (retained for retry).');
        return $result['failed'] === 0 ? 0 : 1;
    }
}
