<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

abstract class Extensions implements IExtensions 
{
    public static function hello(): void 
    {
        echo "hello abstract";
    }
}
?>