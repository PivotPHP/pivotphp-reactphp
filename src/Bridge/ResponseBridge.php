<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Bridge;

use PivotPHP\ReactPHP\Adapter\Psr7CompatibilityAdapter;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response as ReactResponse;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;

final class ResponseBridge
{
    public function convertToReact(ResponseInterface $psrResponse): ReactResponse
    {
        // Use the adapter to ensure we get a proper React response
        $reactResponse = Psr7CompatibilityAdapter::unwrapResponse($psrResponse);
        
        if ($reactResponse instanceof ReactResponse) {
            return $reactResponse;
        }
        
        // Fallback: create a new React response
        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = $psrResponse->getBody();
        
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $reactBody = '';
        if ($body->isReadable()) {
            $reactBody = $body->getContents();
        }

        return new ReactResponse(
            $psrResponse->getStatusCode(),
            $headers,
            $reactBody,
            $psrResponse->getProtocolVersion(),
            $psrResponse->getReasonPhrase()
        );
    }

    public function convertToReactStream(ResponseInterface $psrResponse): ReactResponse
    {
        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = $psrResponse->getBody();
        $stream = new ThroughStream();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($body->isReadable()) {
            $metaData = $body->getMetadata();
            if (isset($metaData['stream']) && is_resource($metaData['stream'])) {
                $reactStream = new ReadableResourceStream($metaData['stream']);
                $reactStream->pipe($stream);
            } else {
                $stream->write($body->getContents());
                $stream->end();
            }
        } else {
            $stream->end();
        }

        return new ReactResponse(
            $psrResponse->getStatusCode(),
            $headers,
            $stream,
            $psrResponse->getProtocolVersion(),
            $psrResponse->getReasonPhrase()
        );
    }
}