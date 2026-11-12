<?php

declare(strict_types=1);

namespace App\Http;

use App\Settings;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Http\HttpException;
use Psr\Http\Message\ResponseInterface;

final readonly class SameOrigin implements Middleware
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        // All mutations require an explicit same-origin browser request, including login/logout.
        // Never derive the trusted origin from the client-controlled Host header.
        if ($request->getHeaderLine('Origin') !== $this->settings->origin()
            || $request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') {
            throw new HttpException(403, 'This request must come from this website.');
        }
        return $next->handle($request);
    }
}
