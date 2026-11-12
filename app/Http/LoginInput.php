<?php

declare(strict_types=1);

namespace App\Http;

use Crab\Validation\Length;
use Crab\Validation\RejectUnknown;

#[RejectUnknown]
final readonly class LoginInput
{
    public function __construct(#[Length(min: 1, max: 1024)] #[\SensitiveParameter] public string $token) {}
}
