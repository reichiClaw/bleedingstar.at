<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): bool
    {
        $known = $_SESSION['csrf'] ?? '';
        return is_string($token) && $known !== '' && hash_equals($known, $token);
    }
}
