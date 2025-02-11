<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

abstract class Extensions implements IExtensions 
{
    protected static function respond(array $data, int $code = 200): array 
    {
        return [
            'success' => $code === 200,
            'code' => $code,
            'data' => $data
        ];
    }

    protected static function error(string $message, int $code = 400): array 
    {
        return [
            'success' => false,
            'code' => $code,
            'error' => $message
        ];
    }

    abstract public static function process(array $getParams, array $postParams): array;
}
?>