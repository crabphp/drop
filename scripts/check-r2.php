<?php

declare(strict_types=1);

// A real, opt-in smoke check: writes one tiny random object and deletes it in finally.
// Never prints credentials, authorization headers or signed URLs.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Settings;
use App\Storage\Objects;
use Crab\Contracts\HttpClient\Client;

$app = require dirname(__DIR__) . '/app.php';
$settings = $app->container->get(Settings::class);
if (!$settings->r2Configured()) {
    fwrite(STDERR, "R2 is not configured. Fill the R2 values in .env.\n");
    exit(1);
}
$objects = $app->container->get(Objects::class);
$http = $app->container->get(Client::class)->timeout(10);
$key = 'files/check-' . bin2hex(random_bytes(16));
$bytes = "Drop storage check\n";
$exit = 0;
try {
    $upload = $objects->upload($key, strlen($bytes), time() + 120);
    $headers = [...$upload['headers'], 'Content-Length' => (string) strlen($bytes)];
    $response = $http->request('PUT', $upload['url'], headers: $headers, body: $bytes);
    if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException('R2 refused the signed upload (HTTP ' . $response->getStatusCode() . ').');
    }
    if ($objects->size($key) !== strlen($bytes)) {
        throw new \RuntimeException('R2 metadata did not match the uploaded size.');
    }
    $repeat = $http->request('PUT', $upload['url'], headers: $headers, body: $bytes);
    if ($repeat->getStatusCode() !== 412) {
        throw new \RuntimeException('R2 did not enforce immutable uploads (HTTP ' . $repeat->getStatusCode() . ').');
    }
    $download = $objects->download($key, 'check.txt', time() + 60);
    $response = $http->request('GET', $download);
    if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== $bytes
        || !str_starts_with($response->getHeaderLine('Content-Disposition'), 'attachment;')) {
        throw new \RuntimeException('R2 did not return the expected attachment.');
    }
    echo "R2 signed upload, HEAD, overwrite protection and attachment download passed.\n";
    $origin = $argv[1] ?? $settings->origin();
    if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
        throw new \RuntimeException('The optional first argument must be the browser origin URL.');
    }
    $preflight = $http->request('OPTIONS', $upload['url'], headers: [
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'PUT',
        'Access-Control-Request-Headers' => 'content-type,if-none-match',
    ]);
    $allowed = $preflight->getHeaderLine('Access-Control-Allow-Origin');
    if ($preflight->getStatusCode() >= 300 || !in_array($allowed, [$origin, '*'], true)) {
        fwrite(STDERR, "Browser CORS is not enabled for this origin. Apply deploy/r2-cors.json to the bucket.\n");
        $exit = 1;
    } else {
        echo "Browser upload CORS passed.\n";
    }
} catch (\Throwable $error) {
    // Only our own fixed messages include a safe status. Do not print SDK/network exception details.
    $safe = $error instanceof \RuntimeException && !str_contains($error->getMessage(), 'http')
        && str_starts_with($error->getMessage(), 'R2 ');
    fwrite(STDERR, $safe ? $error->getMessage() . "\n" : "R2 check failed. Verify bucket permissions, credentials and connectivity.\n");
    $exit = 1;
} finally {
    try {
        $objects->delete($key);
        echo "Temporary check object deleted.\n";
    } catch (\Throwable) {
        fwrite(STDERR, "Could not delete the temporary check object; inspect the bucket's files/check- prefix.\n");
        $exit = 1;
    }
}
exit($exit);
