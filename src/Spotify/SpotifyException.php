<?php

declare(strict_types=1);

namespace App\Spotify;

use RuntimeException;

final class SpotifyException extends RuntimeException
{
    /** @param bool $fatal true when further requests in this run are pointless (auth, quota, outage) */
    public function __construct(string $message, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
