<?php

declare(strict_types=1);

namespace App\Discogs;

class Client
{
    private float $nextAt = 0;

    public function __construct(private array $config)
    {
    }

    public function get(string $path): array
    {
        $token = trim((string) ($this->config['token'] ?? ''));
        $gap = $token !== '' ? 1.05 : 2.5;
        $wait = $this->nextAt - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $url = 'https://api.discogs.com' . $path;
        $headers = [
            'User-Agent: ' . ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'),
            'Accept: application/vnd.discogs.v2.discogs+json',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Discogs token=' . $token;
        }
        $timeout = (int) ($this->config['timeout'] ?? 20);
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->nextAt = microtime(true) + $gap;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADER => true,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                if ($attempt < 3) {
                    sleep($attempt);
                    continue;
                }
                throw new DiscogsException('Discogs network error: ' . $err, 0, false);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            if ($status === 429 || $status >= 500) {
                $retryAfter = 0;
                if (preg_match('/^retry-after:\s*(\d+)/im', $header, $m)) {
                    $retryAfter = (int) $m[1];
                }
                $quota = $status === 429 && $retryAfter === 0;
                if ($attempt >= 3 || $quota) {
                    throw new DiscogsException(
                        $quota ? 'Discogs quota exhausted' : 'Discogs temporary error ' . $status,
                        $status,
                        $quota
                    );
                }
                sleep(max(1, $retryAfter ?: $attempt * 2));
                continue;
            }
            if ($status >= 400) {
                throw new DiscogsException('Discogs HTTP ' . $status, $status, false);
            }
            $json = json_decode($body, true);
            if (!is_array($json)) {
                throw new DiscogsException('Discogs returned invalid JSON', $status, false);
            }
            return $json;
        }
    }
}

