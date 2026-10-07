<?php

declare(strict_types=1);

namespace App;

final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path)
    {
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Lock directory missing: ' . $dir);
        }
        $this->handle = fopen($this->path, 'c');
        if ($this->handle === false) {
            throw new \RuntimeException('Cannot open lock file.');
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }
        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
