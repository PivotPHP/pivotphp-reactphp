<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Bridge;

use Psr\Http\Message\ResponseInterface;
use PivotPHP\ReactPHP\Helpers\HeaderHelper;
use React\Http\Message\Response as ReactResponse;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;

final class ResponseBridge
{
    public function convertToReact(ResponseInterface $psrResponse): ReactResponse
    {
        // Convert PSR-7 Response to ReactPHP Response
        $headers = HeaderHelper::convertPsrToArray($psrResponse->getHeaders());

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
        $headers = HeaderHelper::convertPsrToArray($psrResponse->getHeaders());

        $body = $psrResponse->getBody();
        $stream = new ThroughStream();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($body->isReadable()) {
            $metaData = $body->getMetadata();
            if (is_array($metaData) && isset($metaData['stream']) && is_resource($metaData['stream'])) {
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
