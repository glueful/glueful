<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PSR-15 Compatible Middleware Interface
 * 
 * This interface follows the PSR-15 specification but adapts it to work
 * with Symfony's HttpFoundation components (Request/Response) instead of PSR-7.
 * 
 * Middleware process an incoming request to produce a response,
 * either by handling the request or delegating to another middleware.
 */
interface MiddlewareInterface
{
    /**
     * Process an incoming request
     * 
     * @param Request $request The request
     * @param RequestHandlerInterface $handler The handler to process the request
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response;
}