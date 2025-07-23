<?php

declare(strict_types=1);

namespace Glueful\Lock\Store;

use Symfony\Component\Lock\Store\FlockStore;

class FileLockStore extends FlockStore
{
    private string $lockPath;
    private array $options;

    public function __construct(?string $lockPath = null, array $options = [])
    {
        $this->lockPath = $lockPath ?? sys_get_temp_dir() . '/glueful_locks';
        $this->options = array_merge([
            'prefix' => 'lock_',
            'extension' => '.lock'
        ], $options);

        if (!is_dir($this->lockPath)) {
            mkdir($this->lockPath, 0777, true);
        }

        parent::__construct($this->lockPath);
    }

    public function getLockPath(): string
    {
        return $this->lockPath;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
