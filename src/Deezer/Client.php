<?php

declare(strict_types=1);

namespace App\Deezer;

use App\Discogs\DiscogsException;

/**
 * Minimal Deezer public API client. No credentials are needed; the API allows
 * roughly 50 requests per 5 seconds per IP, so calls are paced.
 */
class Client
{
    private const BASE = 'https://api.deezer.com';
    private float $lastCall = 0.0;

    public function __construct(private array $config)
    {
    }

    /** @throws DiscogsException on network, quota or data errors */
    public function get(string $path): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->pace();
            $ch = curl_init(self::BASE . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['User-Agent: ' . ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'), 'Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                if ($attempt < 3) {
                    sleep($attempt);
                    continue;
                }
                throw new DiscogsException('Deezer network error: ' . $err, 0, false);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                throw new DiscogsException('Deezer returned no JSON (HTTP ' . $status . ')', $status, false);
            }
            if (isset($data['error'])) {
                $code = (int) ($data['error']['code'] ?? 0);
                if ($code === 4 && $attempt < 3) {
                    sleep(5 * $attempt);
                    continue;
                }
                throw new DiscogsException(
                    'Deezer error ' . $code . ': ' . ($data['error']['message'] ?? 'unknown'),
                    $code,
                    $code === 4
                );
            }
            return $data;
        }
        throw new DiscogsException('Deezer: giving up', 0, false);
    }

    private function pace(): void
    {
        $gap = microtime(true) - $this->lastCall;
        if ($gap < 0.15) {
            usleep((int) ((0.15 - $gap) * 1_000_000));
        }
        $this->lastCall = microtime(true);
    }
}
