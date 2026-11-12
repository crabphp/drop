<?php

declare(strict_types=1);

use App\Console\CleanupFiles;
use Crab\Command;

Command::add('drop:cleanup', CleanupFiles::class);
