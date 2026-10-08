<?php

declare(strict_types=1);

namespace App;

final class Mailer
{
    public function __construct(private string $from)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($this->from, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $this->from,
            'Reply-To: ' . $this->from,
        ];
        return @mail($to, $encoded, $body, implode("\r\n", $headers));
    }
}
