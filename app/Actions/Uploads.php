<?php

declare(strict_types=1);

namespace App\Actions;

use App\Files;
use App\Http\CompleteUpload;
use App\Http\NewUpload;
use Crab\Http\Response;

final readonly class Uploads
{
    public function __construct(private Files $files) {}

    public function initiate(NewUpload $input): Response
    {
        return Response::json($this->files->initiate($input->name, $input->size), 201);
    }

    public function complete(string $id, CompleteUpload $input): array
    {
        return $this->files->complete($id, $input->token);
    }
}
