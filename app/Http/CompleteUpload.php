<?php

declare(strict_types=1);

namespace App\Http;

use Crab\Validation\Length;
use Crab\Validation\RejectUnknown;

#[RejectUnknown]
final readonly class CompleteUpload
{
    public function __construct(#[Length(min: 64, max: 64)] #[\SensitiveParameter] public string $token) {}
}
