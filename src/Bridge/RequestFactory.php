<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Bridge;

use PivotPHP\Core\Http\Request as PivotRequest;
use PivotPHP\Core\Http\HeaderRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Factory for creating PivotPHP Request objects from PSR-7 ServerRequest objects
 *
 * This factory provides a cleaner API for creating PivotPHP requests without
 * relying heavily on reflection, making it more maintainable and robust.
 */
final class RequestFactory
{
    /**
     * Create a PivotPHP Request from a PSR-7 ServerRequest
     */
    public function createFromPsr7(ServerRequestInterface $psrRequest): PivotRequest
    {
        $uri = $psrRequest->getUri();
        $method = $psrRequest->getMethod();
        $path = $uri->getPath();

        // Create base PivotPHP Request using the standard constructor approach
        // but with controlled environment to avoid global state interference
        $pivotRequest = $this->createRequestSafely($method, $path, $path);

        // Set request data using available public/protected methods where possible
        $this->populateRequestData($pivotRequest, $psrRequest);

        return $pivotRequest;
    }

    /**
     * Create PivotPHP Request safely without global state interference
     */
    private function createRequestSafely(string $method, string $path, string $pathCallable): PivotRequest
    {
        // Store original globals
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalFiles = $_FILES;

        try {
            // Set minimal globals required for PivotPHP Request construction
            $_SERVER = array_merge($originalServer, [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $pathCallable,
                'QUERY_STRING' => '',
            ]);
            $_GET = [];
            $_POST = [];
            $_FILES = [];

            // Create the request using the standard constructor
            $request = new PivotRequest($method, $path, $pathCallable);

            return $request;
        } finally {
            // Always restore original globals
            $_SERVER = $originalServer;
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_FILES = $originalFiles;
        }
    }

    /**
     * Populate request data using public APIs and minimal reflection
     */
    private function populateRequestData(PivotRequest $pivotRequest, ServerRequestInterface $psrRequest): void
    {
        // Set attributes using public API
        foreach ($psrRequest->getAttributes() as $name => $value) {
            $pivotRequest->setAttribute($name, $value);
        }

        // Handle query parameters using reflection (unavoidable for this property)
        $queryParams = $psrRequest->getQueryParams();
        if (count($queryParams) > 0) {
            $this->setRequestProperty($pivotRequest, 'query', $this->convertArrayToObject($queryParams));
        }

        // Handle request body
        $this->setRequestBody($pivotRequest, $psrRequest);

        // Handle uploaded files
        $uploadedFiles = $psrRequest->getUploadedFiles();
        if (count($uploadedFiles) > 0) {
            $this->setRequestProperty($pivotRequest, 'files', $this->convertUploadedFiles($uploadedFiles));
        }

        // Handle headers using a cleaner approach
        $this->setRequestHeaders($pivotRequest, $psrRequest->getHeaders());
    }

    /**
     * Set request body using available information from PSR-7 request
     */
    private function setRequestBody(PivotRequest $pivotRequest, ServerRequestInterface $psrRequest): void
    {
        $method = $psrRequest->getMethod();

        // Skip body processing for GET requests
        if ($method === 'GET') {
            return;
        }

        $parsedBody = $psrRequest->getParsedBody();
        if ($parsedBody !== null) {
            // Use parsed body if available
            if (is_array($parsedBody)) {
                $this->setRequestProperty($pivotRequest, 'body', $this->convertArrayToObject($parsedBody));
            } elseif (is_object($parsedBody)) {
                $this->setRequestProperty($pivotRequest, 'body', $parsedBody);
            }
            return;
        }

        // Fall back to raw body content
        $bodyContent = (string) $psrRequest->getBody();
        if ($bodyContent !== '') {
            $contentType = $psrRequest->getHeaderLine('Content-Type');
            $this->parseAndSetBody($pivotRequest, $bodyContent, $contentType);
        }
    }

    /**
     * Parse and set body content based on content type
     */
    private function parseAndSetBody(PivotRequest $pivotRequest, string $bodyContent, string $contentType): void
    {
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($bodyContent);
            if ($decoded instanceof \stdClass) {
                $this->setRequestProperty($pivotRequest, 'body', $decoded);
            } elseif (is_array($decoded)) {
                $this->setRequestProperty($pivotRequest, 'body', $this->convertArrayToObject($decoded));
            }
        } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($bodyContent, $parsed);
            $this->setRequestProperty($pivotRequest, 'body', $this->convertArrayToObject($parsed));
        } else {
            // Try JSON first, then form data as fallback
            $decoded = json_decode($bodyContent);
            if ($decoded instanceof \stdClass) {
                $this->setRequestProperty($pivotRequest, 'body', $decoded);
            } else {
                parse_str($bodyContent, $parsed);
                if (count($parsed) > 0) {
                    $this->setRequestProperty($pivotRequest, 'body', $this->convertArrayToObject($parsed));
                }
            }
        }
    }

    /**
     * Set headers using a more structured approach
     */
    private function setRequestHeaders(PivotRequest $pivotRequest, array $headers): void
    {
        // Create headers in the format PivotPHP expects
        $pivotHeaders = [];
        foreach ($headers as $name => $values) {
            $camelCaseName = $this->convertHeaderToCamelCase($name);
            $pivotHeaders[$camelCaseName] = is_array($values) ? implode(', ', $values) : $values;
        }

        // Create HeaderRequest object and set headers using reflection
        // This is currently unavoidable due to PivotPHP's internal structure
        $headerRequest = new HeaderRequest();
        $this->setHeaderRequestHeaders($headerRequest, $pivotHeaders);

        // Set the HeaderRequest on the main request
        $this->setRequestProperty($pivotRequest, 'headers', $headerRequest);
    }

    /**
     * Convert header name to camelCase format that PivotPHP expects
     */
    private function convertHeaderToCamelCase(string $headerName): string
    {
        $parts = explode('-', strtolower($headerName));
        $camelCase = array_shift($parts) ?? '';

        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }

        return $camelCase;
    }

    /**
     * Set headers on HeaderRequest object using reflection
     * This is encapsulated here to limit reflection usage to specific areas
     */
    private function setHeaderRequestHeaders(HeaderRequest $headerRequest, array $headers): void
    {
        try {
            $reflection = new \ReflectionClass($headerRequest);
            if ($reflection->hasProperty('headers')) {
                $headersProperty = $reflection->getProperty('headers');
                $headersProperty->setAccessible(true);
                $headersProperty->setValue($headerRequest, $headers);
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, headers won't be set, but request will still work
            // This makes the factory more resilient to PivotPHP Core changes
        }
    }

    /**
     * Convert PSR-7 uploaded files to PHP $_FILES format
     */
    private function convertUploadedFiles(array $uploadedFiles): array
    {
        $files = [];

        foreach ($uploadedFiles as $name => $file) {
            if ($file instanceof UploadedFileInterface) {
                $files[$name] = [
                    'name' => $file->getClientFilename(),
                    'type' => $file->getClientMediaType(),
                    'size' => $file->getSize(),
                    'tmp_name' => $file->getStream()->getMetadata('uri') ?? '',
                    'error' => $file->getError(),
                ];
            }
        }

        return $files;
    }

    /**
     * Set private/protected properties using reflection
     * Isolated method to minimize reflection usage throughout the class
     */
    private function setRequestProperty(PivotRequest $request, string $property, mixed $value): void
    {
        try {
            $reflection = new \ReflectionClass($request);
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($request, $value);
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, the property won't be set, but the request will still work
            // This makes the factory more resilient to PivotPHP Core changes
        }
    }

    /**
     * Convert array to object recursively to handle nested structures
     */
    private function convertArrayToObject(array $array): \stdClass
    {
        $obj = new \stdClass();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $obj->$key = $this->convertArrayToObject($value);
            } else {
                $obj->$key = $value;
            }
        }
        return $obj;
    }

    /**
     * Factory method for creating instances
     */
    public static function create(): self
    {
        return new self();
    }
}
