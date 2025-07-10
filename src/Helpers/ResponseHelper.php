<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Helpers;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response as ReactResponse;

/**
 * Helper for creating standardized HTTP responses
 */
final class ResponseHelper
{
    /**
     * Create standardized JSON error response
     */
    public static function createErrorResponse(
        int $statusCode,
        string $message,
        array $details = [],
        ?string $errorId = null
    ): ResponseInterface {
        $errorData = [
            'error' => [
                'code' => $statusCode,
                'message' => $message,
            ],
        ];

        // @phpstan-ignore-next-line Array check for additional data
        if (count($details) > 0) {
            $errorData['error']['details'] = $details;
        }

        if ($errorId !== null) {
            $errorData['error']['error_id'] = $errorId;
        } else {
            $errorData['error']['error_id'] = 'err_' . uniqid(more_entropy: true);
        }

        $body = JsonHelper::encode($errorData, '{"error":true,"message":"Internal Error"}');

        return new ReactResponse(
            $statusCode,
            [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) strlen($body),
            ],
            $body
        );
    }

    /**
     * Create standardized JSON success response
     */
    public static function createJsonResponse(
        array $data,
        int $statusCode = 200,
        array $meta = []
    ): ResponseInterface {
        $responseData = $data;

        // @phpstan-ignore-next-line Array check for metadata
        if (count($meta) > 0) {
            $responseData = [
                'data' => $data,
                'meta' => $meta,
            ];
        }

        $body = JsonHelper::encode($responseData, '{"error":true,"message":"Encoding Error"}');

        return new ReactResponse(
            $statusCode,
            [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) strlen($body),
            ],
            $body
        );
    }

    /**
     * Add security headers to response
     */
    public static function addSecurityHeaders(
        ResponseInterface $response,
        bool $isProduction = false
    ): ResponseInterface {
        $securityHeaders = HeaderHelper::getSecurityHeaders($isProduction);

        foreach ($securityHeaders as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        // Remove potentially dangerous headers
        if ($response->hasHeader('Server')) {
            $response = $response->withoutHeader('Server');
        }

        if ($response->hasHeader('X-Powered-By')) {
            $response = $response->withoutHeader('X-Powered-By');
        }

        return $response;
    }

    /**
     * Create text response
     */
    public static function createTextResponse(
        string $content,
        int $statusCode = 200
    ): ResponseInterface {
        return new ReactResponse(
            $statusCode,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Length' => (string) strlen($content),
            ],
            $content
        );
    }

    /**
     * Create HTML response
     */
    public static function createHtmlResponse(
        string $html,
        int $statusCode = 200
    ): ResponseInterface {
        return new ReactResponse(
            $statusCode,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Length' => (string) strlen($html),
            ],
            $html
        );
    }

    /**
     * Create redirect response
     */
    public static function createRedirectResponse(
        string $location,
        int $statusCode = 302
    ): ResponseInterface {
        return new ReactResponse(
            $statusCode,
            [
                'Location' => $location,
                'Content-Length' => '0',
            ],
            ''
        );
    }

    /**
     * Create CORS preflight response
     */
    public static function createCorsResponse(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization']
    ): ResponseInterface {
        return new ReactResponse(
            200,
            [
                'Access-Control-Allow-Origin' => implode(', ', $allowedOrigins),
                'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
                'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
                'Access-Control-Max-Age' => '86400',
                'Content-Length' => '0',
            ],
            ''
        );
    }
}
