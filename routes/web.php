<?php

declare(strict_types=1);

use App\Actions\Backoffice;
use App\Actions\PublicPages;
use App\Actions\Readiness;
use App\Actions\Uploads;
use App\Http\SameOrigin;
use Crab\Http\Response;
use Crab\Route;

Route::get('/', [PublicPages::class, 'home']);
Route::get('/ready', Readiness::class);
Route::get('/robots.txt', static fn(): Response => Response::text("User-agent: *\nDisallow: /\n"));
Route::get('/backoffice', [Backoffice::class, 'index']);
Route::post('/backoffice', [Backoffice::class, 'login'])->middleware(SameOrigin::class);
Route::post('/backoffice/logout', [Backoffice::class, 'logout'])->middleware(SameOrigin::class);
Route::post('/api/uploads', [Uploads::class, 'initiate'])->middleware(SameOrigin::class);
Route::post('/api/uploads/{id}/complete', [Uploads::class, 'complete'])->middleware(SameOrigin::class);
Route::get('/{id}/download', [PublicPages::class, 'download']);
Route::get('/{id}', [PublicPages::class, 'show']);
