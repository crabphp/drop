<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    echo ".env already exists; left unchanged.\n";
    exit(0);
}
$source = file_get_contents($root . '/.env.example');
if ($source === false) {
    fwrite(STDERR, "Could not read .env.example.\n");
    exit(1);
}
$source = str_replace('APP_KEY=local-development-only', 'APP_KEY=' . bin2hex(random_bytes(32)), $source);
$oldMask = umask(0077);
try {
    $file = fopen($root . '/.env', 'x');
    if ($file === false) {
        throw new \RuntimeException('Could not create .env.');
    }
    if (fwrite($file, $source) !== strlen($source)) {
        throw new \RuntimeException('Could not finish writing .env.');
    }
    fclose($file);
} finally {
    umask($oldMask);
}
echo "Created private .env with a random APP_KEY. Set your R2 credentials and ADMIN_TOKEN there, then run php cua migrate.\n";
