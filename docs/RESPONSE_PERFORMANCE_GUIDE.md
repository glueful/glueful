# Response API Performance Guide

## 🚀 Performance Summary

The Glueful Response API provides excellent out-of-the-box performance:

- **40,000+ operations per second** - handles high-traffic applications
- **25μs average response time** - extremely fast response generation  
- **Zero memory overhead** - compared to direct JsonResponse usage
- **Full Symfony compatibility** - seamless middleware integration

## 📊 When Additional Optimization is Needed

Most applications will never need optimization beyond the standard Response class. Consider additional caching only when:

- **Serving > 50,000 requests per minute**
- **Response generation becomes a bottleneck** (profiling shows high CPU usage)
- **Identical responses generated repeatedly** (e.g., configuration endpoints)

## 🎯 Recommended Optimization Strategies

### 1. **HTTP Caching (Recommended)**
Use proper HTTP caching headers instead of application-level caching:

```php
// Add caching headers to responses
public function getConfiguration(): Response
{
    $config = $this->configService->getPublicConfig();
    
    return Response::success($config, 'Configuration retrieved')
        ->setMaxAge(3600)           // Cache for 1 hour
        ->setPublic()               // Allow CDN/proxy caching
        ->setEtag(md5(serialize($config))); // Enable conditional requests
}

// For user-specific data
public function getUserProfile(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    
    return Response::success($profile, 'Profile retrieved')
        ->setMaxAge(300)            // 5 minutes
        ->setPrivate()              // Don't cache in shared caches
        ->setLastModified($profile->updated_at);
}
```

### 2. **Application-Level Caching**
Cache expensive operations, not responses:

```php
class UserService
{
    public function getProfile(int $userId): array
    {
        return cache()->remember("user_profile:$userId", 600, function() use ($userId) {
            return $this->repository->getUserWithPermissions($userId);
        });
    }
}

// Controller stays clean
public function show(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    return Response::success($profile, 'Profile retrieved');
}
```

### 3. **Middleware-Based Response Caching**
For repeated identical responses:

```php
class ResponseCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheStore $cache,
        private array $cacheableRoutes = []
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->shouldCache($request)) {
            return $handler->handle($request);
        }

        $cacheKey = $this->generateCacheKey($request);
        
        // Try to get from cache
        if ($cached = $this->cache->get($cacheKey)) {
            return unserialize($cached);
        }

        // Generate response
        $response = $handler->handle($request);

        // Cache successful responses
        if ($response->getStatusCode() === 200) {
            $this->cache->set($cacheKey, serialize($response), 300);
        }

        return $response;
    }

    private function shouldCache(Request $request): bool
    {
        return $request->getMethod() === 'GET' && 
               in_array($request->getPathInfo(), $this->cacheableRoutes);
    }
}
```

### 4. **Reverse Proxy Caching (Best Performance)**
Use Nginx, Varnish, or CDN for maximum performance:

```nginx
# Nginx configuration
location /api/config {
    proxy_pass http://backend;
    proxy_cache api_cache;
    proxy_cache_valid 200 1h;
    proxy_cache_key "$request_uri";
    add_header X-Cache-Status $upstream_cache_status;
}
```

## 🔧 Implementation Examples

### HTTP Cache Helper
Add to BaseController for easy HTTP caching:

```php
abstract class BaseController
{
    protected function cached(Response $response, int $maxAge = 300, bool $public = false): Response
    {
        $response->setMaxAge($maxAge);
        
        if ($public) {
            $response->setPublic();
        } else {
            $response->setPrivate();
        }
        
        // Add ETag for conditional requests
        $response->setEtag(md5($response->getContent()));
        
        return $response;
    }
    
    protected function notModified(): Response
    {
        return new Response('', 304);
    }
}

// Usage
class ConfigController extends BaseController
{
    public function show(): Response
    {
        $config = $this->configService->getPublicConfig();
        
        return $this->cached(
            Response::success($config, 'Configuration retrieved'),
            3600,  // 1 hour
            true   // public caching
        );
    }
}
```

### Smart Caching Service
For application-level caching with tags:

```php
class SmartCache
{
    public function __construct(private CacheStore $cache) {}
    
    public function rememberResponse(string $key, int $ttl, callable $callback, array $tags = []): Response
    {
        $cached = $this->cache->get($key);
        
        if ($cached) {
            return unserialize($cached);
        }
        
        $response = $callback();
        
        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $this->cache->set($key, serialize($response), $ttl);
            
            // Tag the cache entry for easy invalidation
            foreach ($tags as $tag) {
                $this->cache->tag($tag, $key);
            }
        }
        
        return $response;
    }
    
    public function invalidateTag(string $tag): void
    {
        $keys = $this->cache->getTaggedKeys($tag);
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }
}

// Usage
class UserController extends BaseController
{
    public function show(int $userId): Response
    {
        return $this->smartCache->rememberResponse(
            "user_profile:$userId",
            600,
            fn() => Response::success(
                $this->userService->getProfile($userId),
                'Profile retrieved'
            ),
            ["user:$userId", 'user_profiles']
        );
    }
}
```

## 📈 Performance Monitoring

### Track Response Performance
```php
class ResponsePerformanceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $duration = (microtime(true) - $start) * 1000;
        
        // Add performance headers in development
        if (app()->isDebug()) {
            $response->headers->set('X-Response-Time', $duration . 'ms');
            $response->headers->set('X-Memory-Usage', memory_get_usage(true));
        }
        
        // Log slow responses
        if ($duration > 100) { // 100ms threshold
            logger()->warning('Slow response detected', [
                'url' => $request->getUri(),
                'duration' => $duration,
                'memory' => memory_get_usage(true)
            ]);
        }
        
        return $response;
    }
}
```

### Cache Hit Rate Monitoring
```php
class CacheMetrics
{
    private static int $hits = 0;
    private static int $misses = 0;
    
    public static function hit(): void { self::$hits++; }
    public static function miss(): void { self::$misses++; }
    
    public static function getStats(): array
    {
        $total = self::$hits + self::$misses;
        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => $total > 0 ? (self::$hits / $total) * 100 : 0
        ];
    }
}
```

## 🎯 Performance Targets

### Benchmarks for Different Application Types

**Small Applications (< 1K requests/min)**
- Standard Response API: ✅ Sufficient
- Additional optimizations: ❌ Not needed

**Medium Applications (1K-10K requests/min)**  
- HTTP caching: ✅ Recommended
- Application caching: ✅ For expensive operations
- Response caching: ⚠️ Only if needed

**Large Applications (> 10K requests/min)**
- All above optimizations: ✅ Required
- Reverse proxy caching: ✅ Essential  
- CDN integration: ✅ Recommended

## 🔍 Best Practices

### 1. **Measure First, Optimize Second**
```php
// Use profiling to identify bottlenecks
$profiler = app()->get(ProfilerInterface::class);
$profiler->start('user_profile_generation');

$profile = $this->userService->getProfile($userId);

$profiler->end('user_profile_generation');
```

### 2. **Cache Invalidation Strategy**
```php
class UserService
{
    public function updateProfile(int $userId, array $data): User
    {
        $user = $this->repository->update($userId, $data);
        
        // Clear related caches
        cache()->forget("user_profile:$userId");
        cache()->invalidateTag("user:$userId");
        
        return $user;
    }
}
```

### 3. **Gradual Optimization**
```php
// Start with simple HTTP caching
return Response::success($data)->setMaxAge(300);

// Add application caching if needed
$data = cache()->remember($key, 300, $callback);

// Add response caching only for high-traffic endpoints
// (via middleware or custom implementation)
```

## ✅ Summary

The standard Glueful Response API provides excellent performance (40K+ ops/sec) for the vast majority of applications. When additional performance is needed:

1. **Start with HTTP caching** - proper, standards-compliant, works with CDNs
2. **Add application-level caching** - cache expensive operations, not responses  
3. **Use reverse proxy caching** - for maximum performance at scale
4. **Implement response caching selectively** - only for specific high-traffic endpoints

This approach maintains Glueful's philosophy of developer freedom while providing clear guidance for performance optimization when needed.