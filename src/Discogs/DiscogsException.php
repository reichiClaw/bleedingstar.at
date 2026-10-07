<?php

declare(strict_types=1);

namespace App\Discogs;

final class DiscogsException extends \RuntimeException
{
    public function __construct(string $message, private int $status, private bool $quota)
    {
        parent::__construct($message);
    }

    public function isQuota(): bool
    {
        return $this->quota;
    }

    public function status(): int
    {
        return $this->status;
    }
}
