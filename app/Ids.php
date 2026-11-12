<?php

declare(strict_types=1);

namespace App;

final class Ids
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function next(): string
    {
        $id = '';
        for ($i = 0; $i < 7; $i++) {
            $id .= self::ALPHABET[random_int(0, 61)];
        }
        return $id;
    }

    public static function valid(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9]{7}$/D', $id) === 1;
    }
}
