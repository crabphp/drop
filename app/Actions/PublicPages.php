<?php

declare(strict_types=1);

namespace App\Actions;

use App\Files;
use App\Pages;
use App\RateLimits;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;

final readonly class PublicPages
{
    public function __construct(private Files $files, private Pages $pages, private RateLimits $limits) {}

    public function home(): Response
    {
        return $this->pages->home();
    }

    public function show(string $id, Request $request): Response
    {
        $this->limits->hit('file-views', $request->ip(), 120, 60);
        $file = $this->files->available($id);
        return $file === null ? $this->pages->missing() : $this->pages->download($file);
    }

    public function download(string $id, Request $request): Response
    {
        $this->limits->hit('downloads', $request->ip(), 60, 60);
        if ($this->files->available($id) === null) {
            return $this->pages->missing();
        }
        return Response::redirect($this->files->download($id));
    }
}
