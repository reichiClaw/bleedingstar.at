<?php

declare(strict_types=1);

namespace App\Discogs;

/**
 * Saves the largest provider cover on this server and, when it is
 * bigger than the grid, writes a smaller file derived from that original.
 * Used for Discogs by default; other providers pass their name and CDN hosts.
 */
final class Covers
{
    /** @param list<string> $hosts host names (or suffixes) the cover may be downloaded from */
    public function __construct(
        private string $root,
        private string $userAgent,
        private string $provider = 'discogs',
        private array $hosts = ['discogs.com'],
    ) {
        if (!preg_match('/^[a-z0-9-]+$/', $this->provider)) {
            throw new \InvalidArgumentException('provider name');
        }
    }

    public function store(string $externalId, string $url): ?array
    {
        if (!$this->allowedUrl($url)) {
            return null;
        }
        $bytes = $this->download($url);
        if ($bytes === null) {
            return null;
        }
        return $this->fromBytes($externalId, $bytes);
    }

    /** Paths of a cover stored by an earlier run, or null when none is on disk. */
    public function existing(string $externalId): ?array
    {
        if (!preg_match('/^\d+$/', $externalId)) {
            return null;
        }
        $dir = $this->root . '/storage/uploads/covers/' . $this->provider;
        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            if (is_file($dir . '/' . $externalId . '.' . $ext)) {
                return [
                    'full' => 'covers/' . $this->provider . '/' . $externalId . '.' . $ext,
                    'grid' => is_file($dir . '/' . $externalId . '-640.jpg') ? 'covers/' . $this->provider . '/' . $externalId . '-640.jpg' : null,
                ];
            }
        }
        return null;
    }

    public function fromBytes(string $externalId, string $bytes): ?array
    {
        if (!preg_match('/^\d+$/', $externalId)) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => '',
        };
        if ($ext === '') {
            return null;
        }
        $dir = $this->root . '/storage/uploads/covers/' . $this->provider;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $fullName = $externalId . '.' . $ext;
        if (file_put_contents($dir . '/' . $fullName, $bytes) === false) {
            return null;
        }
        $grid = null;
        if (max((int) $info[0], (int) $info[1]) > 640) {
            $small = $this->shrink($bytes, 640);
            if ($small !== null && file_put_contents($dir . '/' . $externalId . '-640.jpg', $small) !== false) {
                $grid = 'covers/' . $this->provider . '/' . $externalId . '-640.jpg';
            }
        }
        return [
            'full' => 'covers/' . $this->provider . '/' . $fullName,
            'grid' => $grid,
        ];
    }

    private function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach ($this->hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => 15 * 1024 * 1024,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->userAgent, 'Accept: image/*'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false || $status >= 400 || $raw === '') {
            return null;
        }
        return $raw;
    }

    private function shrink(string $bytes, int $maxEdge): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $maxEdge / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 82);
        $out = ob_get_clean();
        return is_string($out) && $out !== '' ? $out : null;
    }
}
