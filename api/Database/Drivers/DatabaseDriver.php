<?php

namespace Glueful\Database\Driver;

interface DatabaseDriver
{
    public function wrapIdentifier(string $identifier): string;
    public function insertIgnore(string $table, array $columns): string;
    public function upsert(string $table, array $columns, array $updateColumns): string;
}