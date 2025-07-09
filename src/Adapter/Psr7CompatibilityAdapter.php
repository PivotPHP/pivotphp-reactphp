<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Adapter;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use React\Http\Message\ServerRequest as ReactServerRequest;
use React\Http\Message\Response as ReactResponse;

/**
 * Adapter to bridge PSR-7 v1.x (ReactPHP) with v2.x (PivotPHP)
 *
 * This adapter wraps ReactPHP messages and ensures they comply with
 * PSR-7 v2.x type hints while maintaining compatibility.
 */
class Psr7CompatibilityAdapter
{
    /**
     * Wrap a React ServerRequest to ensure PSR-7 v2.x compatibility
     */
    public static function wrapServerRequest(ServerRequestInterface $reactRequest): ServerRequestInterface
    {
        return new class ($reactRequest) implements ServerRequestInterface {
            private ServerRequestInterface $wrapped;

            public function __construct(ServerRequestInterface $wrapped)
            {
                $this->wrapped = $wrapped;
            }

            public function getProtocolVersion(): string
            {
                return (string) $this->wrapped->getProtocolVersion();
            }

            public function withProtocolVersion(string $version): MessageInterface
            {
                return new self($this->wrapped->withProtocolVersion($version));
            }

            public function getHeaders(): array
            {
                return $this->wrapped->getHeaders();
            }

            public function hasHeader(string $name): bool
            {
                return $this->wrapped->hasHeader($name);
            }

            public function getHeader(string $name): array
            {
                return $this->wrapped->getHeader($name);
            }

            public function getHeaderLine(string $name): string
            {
                return (string) $this->wrapped->getHeaderLine($name);
            }

            public function withHeader(string $name, $value): MessageInterface
            {
                return new self($this->wrapped->withHeader($name, $value));
            }

            public function withAddedHeader(string $name, $value): MessageInterface
            {
                return new self($this->wrapped->withAddedHeader($name, $value));
            }

            public function withoutHeader(string $name): MessageInterface
            {
                return new self($this->wrapped->withoutHeader($name));
            }

            public function getBody(): StreamInterface
            {
                return $this->wrapped->getBody();
            }

            public function withBody(StreamInterface $body): MessageInterface
            {
                return new self($this->wrapped->withBody($body));
            }

            public function getRequestTarget(): string
            {
                return (string) $this->wrapped->getRequestTarget();
            }

            public function withRequestTarget(string $requestTarget): RequestInterface
            {
                return new self($this->wrapped->withRequestTarget($requestTarget));
            }

            public function getMethod(): string
            {
                return (string) $this->wrapped->getMethod();
            }

            public function withMethod(string $method): RequestInterface
            {
                return new self($this->wrapped->withMethod($method));
            }

            public function getUri(): UriInterface
            {
                return $this->wrapped->getUri();
            }

            public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
            {
                return new self($this->wrapped->withUri($uri, $preserveHost));
            }

            public function getServerParams(): array
            {
                return $this->wrapped->getServerParams();
            }

            public function getCookieParams(): array
            {
                return $this->wrapped->getCookieParams();
            }

            public function withCookieParams(array $cookies): ServerRequestInterface
            {
                return new self($this->wrapped->withCookieParams($cookies));
            }

            public function getQueryParams(): array
            {
                return $this->wrapped->getQueryParams();
            }

            public function withQueryParams(array $query): ServerRequestInterface
            {
                return new self($this->wrapped->withQueryParams($query));
            }

            public function getUploadedFiles(): array
            {
                return $this->wrapped->getUploadedFiles();
            }

            public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
            {
                return new self($this->wrapped->withUploadedFiles($uploadedFiles));
            }

            public function getParsedBody()
            {
                return $this->wrapped->getParsedBody();
            }

            public function withParsedBody($data): ServerRequestInterface
            {
                return new self($this->wrapped->withParsedBody($data));
            }

            public function getAttributes(): array
            {
                return $this->wrapped->getAttributes();
            }

            public function getAttribute(string $name, $default = null)
            {
                return $this->wrapped->getAttribute($name, $default);
            }

            public function withAttribute(string $name, $value): ServerRequestInterface
            {
                return new self($this->wrapped->withAttribute($name, $value));
            }

            public function withoutAttribute(string $name): ServerRequestInterface
            {
                return new self($this->wrapped->withoutAttribute($name));
            }
        };
    }

    /**
     * Wrap a React Response to ensure PSR-7 v2.x compatibility
     */
    public static function wrapResponse(ResponseInterface $reactResponse): ResponseInterface
    {
        return new class ($reactResponse) implements ResponseInterface {
            private ResponseInterface $wrapped;

            public function __construct(ResponseInterface $wrapped)
            {
                $this->wrapped = $wrapped;
            }

            public function getProtocolVersion(): string
            {
                return (string) $this->wrapped->getProtocolVersion();
            }

            public function withProtocolVersion(string $version): MessageInterface
            {
                return new self($this->wrapped->withProtocolVersion($version));
            }

            public function getHeaders(): array
            {
                return $this->wrapped->getHeaders();
            }

            public function hasHeader(string $name): bool
            {
                return $this->wrapped->hasHeader($name);
            }

            public function getHeader(string $name): array
            {
                return $this->wrapped->getHeader($name);
            }

            public function getHeaderLine(string $name): string
            {
                return (string) $this->wrapped->getHeaderLine($name);
            }

            public function withHeader(string $name, $value): MessageInterface
            {
                return new self($this->wrapped->withHeader($name, $value));
            }

            public function withAddedHeader(string $name, $value): MessageInterface
            {
                return new self($this->wrapped->withAddedHeader($name, $value));
            }

            public function withoutHeader(string $name): MessageInterface
            {
                return new self($this->wrapped->withoutHeader($name));
            }

            public function getBody(): StreamInterface
            {
                return $this->wrapped->getBody();
            }

            public function withBody(StreamInterface $body): MessageInterface
            {
                return new self($this->wrapped->withBody($body));
            }

            public function getStatusCode(): int
            {
                return (int) $this->wrapped->getStatusCode();
            }

            public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
            {
                return new self($this->wrapped->withStatus($code, $reasonPhrase));
            }

            public function getReasonPhrase(): string
            {
                return (string) $this->wrapped->getReasonPhrase();
            }
        };
    }

    /**
     * Unwrap a wrapped response to get the original React response
     */
    public static function unwrapResponse(ResponseInterface $wrappedResponse): ResponseInterface
    {
        // If it's our wrapper, extract the original
        if (method_exists($wrappedResponse, 'getWrapped')) {
            return $wrappedResponse->getWrapped();
        }

        // If it's already a React response, return as-is
        if ($wrappedResponse instanceof ReactResponse) {
            return $wrappedResponse;
        }

        // Otherwise, create a new React response from the wrapped one
        $reactResponse = new ReactResponse(
            $wrappedResponse->getStatusCode(),
            $wrappedResponse->getHeaders(),
            $wrappedResponse->getBody(),
            $wrappedResponse->getProtocolVersion(),
            $wrappedResponse->getReasonPhrase()
        );

        return $reactResponse;
    }
}
