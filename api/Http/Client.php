<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Exceptions\HttpException;

/**
 * HTTP Client
 *
 * A simple cURL-based HTTP client for making API requests.
 */
class Client
{
    /**
     * Default request options
     */
    private array $defaultOptions = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'headers' => [],
        'query' => [],
        'form_params' => [],
        'json' => null,
        'verify' => true,
        'sink' => null, // File path to save response body
    ];

    /**
     * Create a new Client instance
     *
     * @param array $defaultOptions Default options for all requests
     */
    public function __construct(array $defaultOptions = [])
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $defaultOptions);
    }

    /**
     * Send a GET request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * Send a POST request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * Send a PUT request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * Send a DELETE request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * Send a PATCH request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * Send an HTTP request
     *
     * @param string $method HTTP method
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponse
     * @throws HttpException
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        // Merge default options with request-specific options
        $options = array_merge($this->defaultOptions, $options);

        // Build URL with query parameters
        if (!empty($options['query'])) {
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $separator . http_build_query($options['query']);
        }

        // Initialize cURL
        $ch = curl_init();

        // Set common cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout']);

        // SSL verification
        if ($options['verify'] === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        // Set method-specific options
        switch (strtoupper($method)) {
            case 'GET':
                break;

            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                $this->setRequestBody($ch, $options);
                break;

            case 'PUT':
            case 'DELETE':
            case 'PATCH':
            case 'HEAD':
            case 'OPTIONS':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                $this->setRequestBody($ch, $options);
                break;

            default:
                throw new HttpException("Unsupported HTTP method: $method");
        }

        // Set request headers
        $headers = [];
        if (!empty($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[] = "$name: $value";
            }
        }

        // Add content type header if sending JSON
        if (isset($options['json']) && !isset($options['headers']['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Capture response headers
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }
            $responseHeaders[trim($header[0])] = trim($header[1]);
            return $len;
        });

        // If sink is set, write to file
        if (!empty($options['sink'])) {
            $fp = fopen($options['sink'], 'w');
            if ($fp === false) {
                throw new HttpException("Could not open file for writing: {$options['sink']}");
            }
            curl_setopt($ch, CURLOPT_FILE, $fp);
        }

        // Execute request
        $responseBody = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $errorCode = curl_errno($ch);

        // Close file pointer if sink was set
        if (!empty($options['sink']) && isset($fp)) {
            fclose($fp);
        }

        // Close cURL handle
        curl_close($ch);

        // Handle errors
        if ($errorCode !== 0) {
            throw new HttpException("cURL error ($errorCode): $error", $errorCode);
        }

        // Create response object
        $response = new HttpResponse(
            $responseCode,
            $responseHeaders,
            $responseBody !== false ? $responseBody : ''
        );

        // For sink option, return response with empty body
        if (!empty($options['sink'])) {
            return new HttpResponse(
                $responseCode,
                $responseHeaders,
                ''
            );
        }

        return $response;
    }

    /**
     * Set request body according to options
     *
     * @param \CurlHandle $ch cURL handle
     * @param array $options Request options
     */
    private function setRequestBody(\CurlHandle $ch, array $options): void
    {
        // JSON body has highest precedence
        if (isset($options['json'])) {
            $body = json_encode($options['json']);
            if ($body === false) {
                throw new HttpException('Failed to encode request body as JSON');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            return;
        }

        // Then form params
        if (!empty($options['form_params'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
            return;
        }

        // Finally raw body
        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
    }
}
