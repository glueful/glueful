<?php

declare(strict_types=1);

namespace Glueful\Lock\Store;

use Symfony\Component\Lock\Store\PdoStore;
use Glueful\Database\DatabaseInterface;

class DatabaseLockStore extends PdoStore
{
    private DatabaseInterface $database;
    private array $options;

    public function __construct(DatabaseInterface $database, array $options = [])
    {
        $this->database = $database;
        $this->options = array_merge([
            'table' => 'locks',
            'id_col' => 'key_id',
            'token_col' => 'token',
            'expiration_col' => 'expiration'
        ], $options);

        $pdo = $database->getConnection();

        parent::__construct($pdo, $this->options);
    }

    public function getDatabase(): DatabaseInterface
    {
        return $this->database;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
