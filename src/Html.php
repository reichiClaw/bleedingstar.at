<?php

declare(strict_types=1);

namespace App;

final class Html
{
    private const ALLOWED = '<p><br><strong><em><b><i><a><ul><ol><li><h2><h3><blockquote><img><figure><figcaption>';

    public static function clean(?string $html): string
    {
        $html = $html ?? '';
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? $html;
        $html = strip_tags($html, self::ALLOWED);
        $html = preg_replace_callback('/<img\b[^>]*>/i', [self::class, 'filterImage'], $html) ?? $html;
        $html = preg_replace_callback('/<a\b[^>]*>/i', [self::class, 'filterAnchor'], $html) ?? $html;
        return trim($html);
    }

    public static function wordpress(?string $html): string
    {
        $html = $html ?? '';
        $html = str_replace('Du braucht einen', 'Du brauchst einen', $html);
        $html = preg_replace('/\[block\s+title="([^"]*)"\]/i', '<h2>$1</h2>', $html) ?? $html;
        $html = preg_replace('#<h1\b([^>]*)>#i', '<h2$1>', $html) ?? $html;
        $html = preg_replace('#</h1>#i', '</h2>', $html) ?? $html;
        $html = preg_replace('#\[/block\]#i', '', $html) ?? $html;
        $html = preg_replace('#<div[^>]*wp-block-spacer[^>]*>\s*</div>#i', '', $html) ?? $html;
        $html = preg_replace_callback(
            '/\[wpaudio\s+url="([^"]+)"(?:\s+text="([^"]*)")?\]/i',
            static fn (array $m) => '<p><a href="' . e($m[1]) . '">' . e($m[2] !== '' ? $m[2] : 'Audio') . '</a></p>',
            $html
        ) ?? $html;
        $html = preg_replace('/\[[a-z0-9_-]+[^\]]*\]/i', '', $html) ?? $html;
        return self::clean($html);
    }

    private static function filterImage(array $match): string
    {
        $tag = $match[0];
        if (!preg_match('/\ssrc\s*=\s*("|\')([^"\']+)\1/i', $tag, $src)) {
            return '';
        }
        $url = $src[2];
        if (!self::allowedUrl($url) && !str_starts_with($url, '/media/')) {
            return '';
        }
        $alt = '';
        if (preg_match('/\salt\s*=\s*("|\')([^"\']*)\1/i', $tag, $a)) {
            $alt = $a[2];
        }
        return '<img src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy">';
    }

    private static function filterAnchor(array $match): string
    {
        $tag = $match[0];
        if (!preg_match('/\shref\s*=\s*("|\')([^"\']+)\1/i', $tag, $href)) {
            return '<a>';
        }
        $url = $href[2];
        if (!self::allowedUrl($url) && !str_starts_with($url, '/') && !str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:')) {
            return '<a>';
        }
        return '<a href="' . e($url) . '" rel="noopener noreferrer">';
    }

    private static function allowedUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }
}
