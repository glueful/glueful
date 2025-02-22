<?php

namespace Glueful\Database\Driver;

class SQLiteDriver implements DatabaseDriver
{
    public function wrapIdentifier(string $identifier): string
    {
        return "\"$identifier\"";
    }

    public function insertIgnore(string $table, array $columns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        return "INSERT OR IGNORE INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)";
    }

    public function upsert(string $table, array $columns, array $updateColumns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $updates = implode(", ", array_map(fn($col) => "\"$col\" = EXCLUDED.\"$col\"", $updateColumns));

        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders) ON CONFLICT(id) DO UPDATE SET $updates";
    }
}