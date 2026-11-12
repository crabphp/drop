<?php

declare(strict_types=1);

namespace App\Http;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Psr\Http\Message\ResponseInterface;

final class SecurityHeaders implements Middleware
{
    public function handle(Request $request, Handler $next): ResponseInterface
    {
        return $next->handle($request)
            ->withHeader('Cache-Control', 'private, no-store')
            // Keep external referrers private without making same-origin form POSTs send Origin: null.
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self' https://*.r2.cloudflarestorage.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    }
}
