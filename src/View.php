<?php

declare(strict_types=1);

namespace App;

final class View
{
    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function render(string $template, array $data = []): string
    {
        $content = $this->partial($template, $data);
        return $this->partial('layout', $data + ['content' => $content]);
    }

    public function partial(string $template, array $data = []): string
    {
        $shared = $this->shared;
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template missing: ' . $template);
        }
        extract($shared + $data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
