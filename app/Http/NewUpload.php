<?php

declare(strict_types=1);

namespace App\Http;

use Crab\Validation\Length;
use Crab\Validation\Range;
use Crab\Validation\RejectUnknown;

#[RejectUnknown]
final readonly class NewUpload
{
    public function __construct(
        #[Length(min: 1, max: 255)] public string $name,
        #[Range(min: 0)] public int $size,
    ) {}
}
