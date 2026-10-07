<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_release_date(?int $year, ?int $month, ?int $day, string $precision): string
{
    if ($precision === 'day' && $year && $month && $day) {
        return sprintf('%02d.%02d.%04d', $day, $month, $year);
    }
    if ($precision === 'month' && $year && $month) {
        return sprintf('%02d.%04d', $month, $year);
    }
    if ($precision === 'year' && $year) {
        return (string) $year;
    }
    if ($year && $precision !== 'unknown') {
        return (string) $year;
    }
    return '';
}

function release_type_label(?string $type): string
{
    return match ($type) {
        'single' => 'Single',
        'ep' => 'EP',
        'album' => 'Album',
        'compilation' => 'Compilation',
        default => '',
    };
}

function normalize_match_key(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/\s*-\s*single\s*$/u', '', $value) ?? $value;
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    return $value;
}

function slugify(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($ascii !== false ? $ascii : $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : 'eintrag';
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 64);
}

function request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    return $path === '' ? '/' : $path;
}
