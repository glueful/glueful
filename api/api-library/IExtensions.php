<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

interface IExtensions 
{
    /**
     * Process extension request
     * 
     * @param array<string, mixed> $queryParams GET parameters
     * @param array<string, mixed> $bodyParams POST parameters
     * @return array<string, mixed> Response data
     */
    public static function process(array $queryParams, array $bodyParams): array;
}