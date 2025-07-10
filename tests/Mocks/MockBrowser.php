<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Tests\Mocks;

use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Promise\Promise;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Mock Browser for testing ReactPHP HTTP client functionality
 */
final class MockBrowser
{
    private array $responses = [];
    private array $requests = [];
    private array $defaultHeaders = [];
    private bool $followRedirects = true;
    private float $timeout = 30.0;
    private bool $rejectErrorResponse = true;

    /**
     * Set predefined responses for specific URLs
     */
    public function setResponse(string $url, ResponseInterface $response): void
    {
        $this->responses[$url] = $response;
    }

    /**
     * Set multiple responses for different URLs
     */
    public function setResponses(array $responses): void
    {
        $this->responses = array_merge($this->responses, $responses);
    }

    /**
     * Set error response for specific URL
     */
    public function setError(string $url, \Exception $error): void
    {
        $this->responses[$url] = $error;
    }

    /**
     * Get all recorded requests
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * Get last recorded request
     */
    public function getLastRequest(): ?array
    {
        return end($this->requests) ?: null;
    }

    /**
     * Clear all recorded requests
     */
    public function clearRequests(): void
    {
        $this->requests = [];
    }

    /**
     * GET request mock
     */
    public function get(string $url, array $headers = []): PromiseInterface
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * POST request mock
     */
    public function post(string $url, array $headers = [], string|StreamInterface $body = ''): PromiseInterface
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * PUT request mock
     */
    public function put(string $url, array $headers = [], string|StreamInterface $body = ''): PromiseInterface
    {
        return $this->request('PUT', $url, $headers, $body);
    }

    /**
     * DELETE request mock
     */
    public function delete(string $url, array $headers = [], string|StreamInterface $body = ''): PromiseInterface
    {
        return $this->request('DELETE', $url, $headers, $body);
    }

    /**
     * PATCH request mock
     */
    public function patch(string $url, array $headers = [], string|StreamInterface $body = ''): PromiseInterface
    {
        return $this->request('PATCH', $url, $headers, $body);
    }

    /**
     * HEAD request mock
     */
    public function head(string $url, array $headers = []): PromiseInterface
    {
        return $this->request('HEAD', $url, $headers);
    }

    /**
     * Generic request method mock
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string|StreamInterface $body = ''
    ): PromiseInterface {
        // Record the request
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => array_merge($this->defaultHeaders, $headers),
            'body' => $body,
            'timestamp' => microtime(true),
        ];

        // Return predefined response or default
        if (isset($this->responses[$url])) {
            $response = $this->responses[$url];

            if ($response instanceof \Exception) {
                return new Promise(function ($resolve, $reject) use ($response) {
                    $reject($response);
                });
            }

            return new Promise(function ($resolve) use ($response) {
                $resolve($response);
            });
        }

        // Default successful response
        $defaultResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['mock' => true, 'url' => $url, 'method' => $method])
        );

        return new Promise(function ($resolve) use ($defaultResponse) {
            $resolve($defaultResponse);
        });
    }

    /**
     * Streaming request mock (same as regular request for testing)
     */
    public function requestStreaming(
        string $method,
        string $url,
        array $headers = [],
        string|StreamInterface $body = ''
    ): PromiseInterface {
        return $this->request($method, $url, $headers, $body);
    }

    /**
     * Configure redirect following
     */
    public function withFollowRedirects(bool|int $followRedirects): self
    {
        $new = clone $this;
        $new->followRedirects = is_bool($followRedirects) ? $followRedirects : ($followRedirects > 0);
        return $new;
    }

    /**
     * Configure error response rejection
     */
    public function withRejectErrorResponse(bool $rejectErrorResponse): self
    {
        $new = clone $this;
        $new->rejectErrorResponse = $rejectErrorResponse;
        return $new;
    }

    /**
     * Configure timeout
     */
    public function withTimeout(float $timeout): self
    {
        $new = clone $this;
        $new->timeout = $timeout;
        return $new;
    }

    /**
     * Set default header
     */
    public function withHeader(string $name, string $value): self
    {
        $new = clone $this;
        $new->defaultHeaders[$name] = $value;
        return $new;
    }

    /**
     * Get configuration for testing
     */
    public function getConfiguration(): array
    {
        return [
            'followRedirects' => $this->followRedirects,
            'timeout' => $this->timeout,
            'rejectErrorResponse' => $this->rejectErrorResponse,
            'defaultHeaders' => $this->defaultHeaders,
        ];
    }

    /**
     * Helper to create common test responses
     */
    public static function createJsonResponse(array $data, int $status = 200, array $headers = []): Response
    {
        $defaultHeaders = ['Content-Type' => 'application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        return new Response($status, $allHeaders, json_encode($data));
    }

    /**
     * Helper to create error response
     */
    public static function createErrorResponse(
        string $message,
        int $status = 500,
        array $headers = []
    ): Response {
        $defaultHeaders = ['Content-Type' => 'application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        return new Response($status, $allHeaders, json_encode(['error' => $message]));
    }
}
