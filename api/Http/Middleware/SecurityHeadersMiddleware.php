<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Headers Middleware
 *
 * PSR-15 compatible middleware that adds essential security headers to responses.
 * Helps protect against common web vulnerabilities and improves security posture.
 *
 * Features:
 * - Content Security Policy (CSP)
 * - Cross-Site Scripting (XSS) Protection
 * - Click-jacking Prevention (X-Frame-Options)
 * - MIME Sniffing Prevention
 * - Referrer Policy Configuration
 * - Permissions Policy
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array Security header configuration */
    private array $config;

    /**
     * Create a new security headers middleware
     *
     * @param array $config Security header configuration
     */
    public function __construct(array $config = [])
    {
        // Default security header configuration
        $defaultConfig = [
            'content_security_policy' => [
                'enabled' => true,
                'report_only' => false,
                'directives' => [
                    'default-src' => ["'self'"],
                    'script-src' => ["'self'"],
                    'style-src' => ["'self'"],
                    'img-src' => ["'self'", 'data:'],
                    'font-src' => ["'self'"],
                    'connect-src' => ["'self'"],
                    'frame-src' => ["'none'"],
                    'object-src' => ["'none'"],
                ],
            ],
            'x_content_type_options' => true,
            'x_frame_options' => 'DENY',
            'x_xss_protection' => '1; mode=block',
            'strict_transport_security' => [
                'enabled' => true,
                'max_age' => 31536000, // 1 year
                'include_subdomains' => true,
                'preload' => false,
            ],
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => [
                'enabled' => true,
                'directives' => [
                    'geolocation' => ['self'],
                    'microphone' => ['self'],
                    'camera' => ['self'],
                    'payment' => ['self'],
                ],
            ],
        ];

        // Handle legacy config format from security.php
        $this->config = $this->normalizeConfig($defaultConfig, $config);
    }

    /**
     * Normalize config to handle both legacy and new formats
     *
     * @param array $defaultConfig Default configuration
     * @param array $userConfig User-provided configuration
     * @return array Normalized configuration
     */
    private function normalizeConfig(array $defaultConfig, array $userConfig): array
    {
        // Start with default config
        $config = $defaultConfig;

        // Handle legacy format from security.php config
        if (isset($userConfig['content_security_policy']) && is_string($userConfig['content_security_policy'])) {
            // Parse CSP string into directives
            $cspString = $userConfig['content_security_policy'];
            $config['content_security_policy']['enabled'] = !empty($cspString);
            if (!empty($cspString)) {
                // Simple parsing - for more complex CSP strings, this could be enhanced
                $config['content_security_policy']['directives']['default-src'] = [$cspString];
            }
        } elseif (isset($userConfig['content_security_policy']) && is_array($userConfig['content_security_policy'])) {
            $config['content_security_policy'] = array_merge(
                $config['content_security_policy'],
                $userConfig['content_security_policy']
            );
        }

        // Handle other header settings
        if (isset($userConfig['x_frame_options'])) {
            $config['x_frame_options'] = $userConfig['x_frame_options'];
        }

        if (isset($userConfig['x_content_type_options'])) {
            $config['x_content_type_options'] = !empty($userConfig['x_content_type_options']);
        }

        if (isset($userConfig['x_xss_protection'])) {
            $config['x_xss_protection'] = $userConfig['x_xss_protection'];
        }

        if (isset($userConfig['strict_transport_security'])) {
            if (is_string($userConfig['strict_transport_security'])) {
                $config['strict_transport_security']['enabled'] = !empty($userConfig['strict_transport_security']);
            } elseif (is_array($userConfig['strict_transport_security'])) {
                $config['strict_transport_security'] = array_merge(
                    $config['strict_transport_security'],
                    $userConfig['strict_transport_security']
                );
            }
        }

        if (isset($userConfig['referrer_policy'])) {
            $config['referrer_policy'] = $userConfig['referrer_policy'];
        }

        if (isset($userConfig['permissions_policy']) && is_array($userConfig['permissions_policy'])) {
            $config['permissions_policy'] = array_merge(
                $config['permissions_policy'],
                $userConfig['permissions_policy']
            );
        }

        // Merge any other settings that follow the new format
        foreach ($userConfig as $key => $value) {
            if (!isset($config[$key]) && is_array($value)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * Process the request through the security headers middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Process the request through the middleware pipeline
        $response = $handler->handle($request);

        // Add security headers to the response
        $this->addSecurityHeaders($response, $request);

        return $response;
    }

    /**
     * Add security headers to the response
     *
     * @param Response $response The response
     * @param Request $request The request (for checking HTTPS)
     */
    private function addSecurityHeaders(Response $response, ?Request $request = null): void
    {
        // Content Security Policy
        if ($this->config['content_security_policy']['enabled']) {
            $this->addContentSecurityPolicy($response);
        }

        // X-Content-Type-Options
        if ($this->config['x_content_type_options']) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        // X-Frame-Options
        if ($this->config['x_frame_options']) {
            $response->headers->set('X-Frame-Options', $this->config['x_frame_options']);
        }

        // X-XSS-Protection
        if ($this->config['x_xss_protection']) {
            $response->headers->set('X-XSS-Protection', $this->config['x_xss_protection']);
        }

        // Strict-Transport-Security (only for HTTPS requests)
        if ($this->config['strict_transport_security']['enabled'] && $request && $request->isSecure()) {
            $this->addStrictTransportSecurity($response);
        }

        // Referrer-Policy
        if ($this->config['referrer_policy']) {
            $response->headers->set('Referrer-Policy', $this->config['referrer_policy']);
        }

        // Permissions-Policy
        if ($this->config['permissions_policy']['enabled']) {
            $this->addPermissionsPolicy($response);
        }
    }

    /**
     * Add Content Security Policy header
     *
     * @param Response $response The response
     */
    private function addContentSecurityPolicy(Response $response): void
    {
        $directives = [];

        foreach ($this->config['content_security_policy']['directives'] as $directive => $sources) {
            $directives[] = $directive . ' ' . implode(' ', $sources);
        }

        $headerName = $this->config['content_security_policy']['report_only']
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($headerName, implode('; ', $directives));
    }

    /**
     * Add Strict-Transport-Security header
     *
     * @param Response $response The response
     */
    private function addStrictTransportSecurity(Response $response): void
    {
        $config = $this->config['strict_transport_security'];
        $value = 'max-age=' . $config['max_age'];

        if ($config['include_subdomains']) {
            $value .= '; includeSubDomains';
        }

        if ($config['preload']) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    /**
     * Add Permissions-Policy header
     *
     * @param Response $response The response
     */
    private function addPermissionsPolicy(Response $response): void
    {
        $directives = [];

        foreach ($this->config['permissions_policy']['directives'] as $directive => $sources) {
            // Format the sources according to the spec
            $formattedSources = array_map(function ($source) {
                if ($source === 'self') {
                    return 'self';
                } elseif ($source === '*') {
                    return '*';
                } else {
                    return '"' . $source . '"';
                }
            }, $sources);

            $directives[] = $directive . '=(' . implode(' ', $formattedSources) . ')';
        }

        $response->headers->set('Permissions-Policy', implode(', ', $directives));
    }
}
