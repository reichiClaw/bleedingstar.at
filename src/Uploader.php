<?php

declare(strict_types=1);

namespace App;

final class Uploader
{
    public function __construct(private string $root)
    {
    }

    public function image(array $file, string $bucket): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload fehlgeschlagen.');
        }
        if (($file['size'] ?? 0) > 8_000_000) {
            throw new \RuntimeException('Die Datei ist größer als 8 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new \RuntimeException('Nur Bilddateien sind erlaubt.');
        }
        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => '',
        };
        if ($ext === '') {
            throw new \RuntimeException('Erlaubt sind JPEG, PNG, GIF und WebP.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dir = $this->root . '/storage/uploads/' . $bucket;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload-Verzeichnis fehlt.');
        }
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Die Datei konnte nicht gespeichert werden.');
        }
        return $bucket . '/' . $name;
    }
}
