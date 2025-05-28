<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\DI\Interfaces\ContainerInterface;

/**
 * PSR-15 Compatible Middleware Dispatcher
 *
 * Handles the execution of a middleware pipeline in a PSR-15 compatible way.
 * Manages the middleware stack and delegates processing through each middleware
 * until a response is generated.
 */
class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] The middleware stack */
    private array $middlewareStack = [];

    /** @var callable The final request handler to use if no middleware produces a response */
    private $fallbackHandler;

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /**
     * Create a new middleware dispatcher
     *
     * @param callable|null $fallbackHandler The fallback handler to use at the end of the middleware stack
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?callable $fallbackHandler = null, ?ContainerInterface $container = null)
    {
        $this->container = $container ?? $this->getDefaultContainer();

        $this->fallbackHandler = $fallbackHandler ?: function (Request $request) {
            // Default fallback handler returns a JSON 404 response
            return new JsonResponse([
                'success' => false,
                'message' => 'Route not found',
                'code' => 404
            ], 404);
        };
    }

    /**
     * Add middleware to the stack
     *
     * @param MiddlewareInterface $middleware The middleware to add
     * @return self Fluent interface
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middlewareStack[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware to the stack
     *
     * @param array $middlewareList Array of MiddlewareInterface instances
     * @return self Fluent interface
     */
    public function pipeMany(array $middlewareList): self
    {
        foreach ($middlewareList as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                $this->pipe($middleware);
            }
        }
        return $this;
    }

    /**
     * Add middleware by class name (resolved through DI container)
     *
     * @param string $middlewareClass The middleware class name
     * @param array $constructorArgs Additional constructor arguments
     * @return self Fluent interface
     */
    public function pipeClass(string $middlewareClass, array $constructorArgs = []): self
    {
        // Resolve middleware through DI container if available
        if ($this->container && empty($constructorArgs)) {
            $middleware = $this->container->get($middlewareClass);
        } else {
            // If constructor args are provided or no container, create instance manually
            $middleware = new $middlewareClass(...$constructorArgs);
        }

        if ($middleware instanceof MiddlewareInterface) {
            $this->pipe($middleware);
        }

        return $this;
    }

    /**
     * Add multiple middleware by class names
     *
     * @param array $middlewareClasses Array of middleware class names or [class => args] pairs
     * @return self Fluent interface
     */
    public function pipeManyClasses(array $middlewareClasses): self
    {
        foreach ($middlewareClasses as $key => $value) {
            if (is_string($key)) {
                // Format: ['ClassName' => [arg1, arg2]]
                $this->pipeClass($key, $value);
            } else {
                // Format: ['ClassName1', 'ClassName2']
                $this->pipeClass($value);
            }
        }
        return $this;
    }

    /**
     * Create a MiddlewareInterface from a closure
     *
     * @param callable $callable The closure to wrap as middleware
     * @return MiddlewareInterface
     */
    public function createMiddleware(callable $callable): MiddlewareInterface
    {
        return new class ($callable) implements MiddlewareInterface {
            private $callable;

            public function __construct(callable $callable)
            {
                $this->callable = $callable;
            }

            public function process(Request $request, RequestHandlerInterface $handler): Response
            {
                // Call the middleware
                $result = call_user_func($this->callable, $request);

                // If it returns a response, return it
                if ($result instanceof Response) {
                    return $result;
                }

                // Otherwise, continue to the next middleware
                return $handler->handle($request);
            }
        };
    }

    /**
     * Handle the request through the middleware stack
     *
     * @param Request $request The incoming request
     * @return Response The resulting response
     */
    public function handle(Request $request): Response
    {
        try {
            // If there are no middleware, use the fallback handler
            if (empty($this->middlewareStack)) {
                return $this->processFallback($request);
            }

            // Take the first middleware and create a new dispatcher with the remaining stack
            $middleware = array_shift($this->middlewareStack);
            $next = clone $this;

            // Process the request through the middleware
            return $middleware->process($request, $next);
        } catch (\Throwable $exception) {
            // Log the exception
            if (class_exists('\\Glueful\\Exceptions\\ExceptionHandler')) {
                ExceptionHandler::logError($exception);
            }

            // Return an error response
            return new JsonResponse([
                'success' => false,
                'message' => 'Internal server error: ' . $exception->getMessage(),
                'code' => $exception->getCode() ?: 500,
                'type' => get_class($exception)
            ], 500);
        }
    }

    /**
     * Process the fallback handler to generate a response
     *
     * @param Request $request The incoming request
     * @return Response The resulting response
     */
    private function processFallback(Request $request): Response
    {
        $result = call_user_func($this->fallbackHandler, $request);

        // If the handler returned a Response object, return it
        if ($result instanceof Response) {
            return $result;
        }

        // Convert array responses to JsonResponse
        if (is_array($result)) {
            $statusCode = $result['code'] ?? ($result['success'] ?? true ? 200 : 500);
            return new JsonResponse($result, $statusCode);
        }

        // For any other type of result, wrap it in a JsonResponse
        return new JsonResponse([
            'success' => true,
            'data' => $result
        ], 200);
    }

    /**
     * Get default container safely
     *
     * @return ContainerInterface|null
     */
    private function getDefaultContainer(): ?ContainerInterface
    {
        // Check if app() function exists (available when bootstrap is loaded)
        if (function_exists('app')) {
            try {
                return app();
            } catch (\Exception $e) {
                // Fall back to null if container is not available
                return null;
            }
        }

        return null;
    }
}
