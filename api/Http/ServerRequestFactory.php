<?php

declare(strict_types=1);

namespace Glueful\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-7 Server Request Factory
 *
 * Creates PSR-7 compliant ServerRequest objects from globals.
 * Uses Nyholm PSR-7 implementation.
 */
class ServerRequestFactory
{
    /**
     * Create ServerRequest from globals
     *
     * Creates a PSR-7 ServerRequest object using global variables:
     * - $_SERVER
     * - $_FILES
     * - $_COOKIE
     * - $_GET
     * - $_POST
     *
     * @return ServerRequestInterface
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();

        $creator = new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );

        $request = $creator->fromGlobals();

        // Handle JSON content type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $content = file_get_contents('php://input');
            if (!empty($content)) {
                $parsedBody = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request = $request->withParsedBody($parsedBody);
                }
            }
        }

        return $request;
    }
}
