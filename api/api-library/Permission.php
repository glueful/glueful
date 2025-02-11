<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class Permission {
    public const VIEW = 'A';
    public const SAVE = 'B';
    public const DELETE = 'C';
    public const EDIT = 'D';
    
    public static function getAll(): array {
        return [
            self::VIEW,
            self::SAVE,
            self::EDIT,
            self::DELETE
        ];
    }
}


