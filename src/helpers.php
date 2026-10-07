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

function excerpt(?string $html, int $max = 180): string
{
    $spaced = preg_replace('#</(p|h[1-6]|li|div|blockquote)>|<br\s*/?>#i', ' ', $html ?? '') ?? '';
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,;:-–") . ' …';
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

/**
 * Cache-busting token for a file below the document root (public/ in the repository,
 * the FTP root on the server). Falls back to a constant when the file is not readable.
 */
function asset_version(string $webPath): string
{
    static $cache = [];
    if (isset($cache[$webPath])) {
        return $cache[$webPath];
    }
    $roots = [
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        app_root() . '/public',
        dirname(app_root()),
    ];
    foreach ($roots as $root) {
        if ($root !== '' && is_file($root . $webPath)) {
            $mtime = @filemtime($root . $webPath);
            if ($mtime !== false) {
                return $cache[$webPath] = dechex($mtime);
            }
        }
    }
    return $cache[$webPath] = '1';
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

/** Source settings with defaults, so an older config/config.php keeps working. */
function source_config(array $config, string $name): array
{
    $discogs = $config['discogs'] ?? [];
    $defaults = [
        'deezer' => [
            'enabled' => true,
            'label_names' => $discogs['allow_label_names'] ?? ['BleedingStar Records'],
            'user_agent' => $discogs['user_agent'] ?? 'BleedingStarCatalog/1.0',
            'timeout' => 20,
        ],
        'apple' => [
            'enabled' => true,
            'country' => 'at',
            'user_agent' => $discogs['user_agent'] ?? 'BleedingStarCatalog/1.0',
            'timeout' => 20,
        ],
    ];
    return array_replace($defaults[$name] ?? [], (array) ($config[$name] ?? []));
}
