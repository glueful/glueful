<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PSR-15 Compatible Request Handler Interface
 * 
 * This interface follows the PSR-15 specification but adapts it to work
 * with Symfony's HttpFoundation components (Request/Response) instead of PSR-7.
 * 
 * A request handler processes an HTTP request and produces an HTTP response.
 * This may be the final handler that produces the response, or a middleware
 * that delegates to another handler.
 */
interface RequestHandlerInterface
{
    /**
     * Handle the request and produce a response
     * 
     * @param Request $request The request to handle
     * @return Response The resulting response
     */
    public function handle(Request $request): Response;
}