<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2025 Brad Kent
 * @since     2.3 since 2.3 & 3.3
 */

namespace bdk\HttpFactory;

use bdk\HttpMessage\Request;
use bdk\HttpMessage\Response;
use bdk\HttpMessage\ServerRequestExtended;
use bdk\HttpMessage\Stream;
use bdk\HttpMessage\UploadedFile;
use bdk\HttpMessage\Uri;
use InvalidArgumentException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * PSR-17 PSR-7 Factories.
 *
 * @psalm-api
 */
class Factory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
    /**
     * Create a new request.
     *
     * @param string              $method The HTTP method associated with the request.
     * @param UriInterface|string $uri    The URI associated with the request.
     *
     * @return Request
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }

    /**
     * Create a new response.
     *
     * @param int    $code         The HTTP status code. Defaults to 200.
     * @param string $reasonPhrase The reason phrase to associate with the status code
     *     in the generated response. If none is provided, implementations MAY use
     *     the defaults as suggested in the HTTP specification.
     *
     * @return Response
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, $reasonPhrase);
    }

    /**
     * Create a new server request.
     *
     * Note that server parameters are taken precisely as given - no parsing/processing
     * of the given values is performed. In particular, no attempt is made to
     * determine the HTTP method or URI, which must be provided explicitly.
     *
     * @param string              $method       The HTTP method associated with the request.
     * @param UriInterface|string $uri          The URI associated with the request.
     * @param array<string,mixed> $serverParams An array of Server API (SAPI) parameters with
     *     which to seed the generated request instance.
     *
     * @return ServerRequestExtended
     */
    public function createServerRequest(string $method, $uri, array $serverParams = array()): ServerRequestInterface
    {
        return new ServerRequestExtended($method, $uri, $serverParams);
    }

    /**
     * Create a new stream from a string.
     *
     * The stream SHOULD be created with a temporary resource.
     *
     * @param string $content String content with which to populate the stream.
     *
     * @return Stream
     */
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = \fopen('php://temp', 'wb+');
        \fwrite($resource, $content);
        \rewind($resource);
        return $this->createStreamFromResource($resource);
    }

    /**
     * Create a stream from an existing file.
     *
     * The file MUST be opened using the given mode, which may be any mode
     * supported by the `fopen` function.
     *
     * The `$filename` MAY be any string supported by `fopen()`.
     *
     * @param string $filename The filename or stream URI to use as basis of stream.
     * @param string $mode     The mode with which to open the underlying filename/stream.
     *
     * @throws RuntimeException If the file cannot be opened.
     * @throws InvalidArgumentException If the mode is invalid.
     *
     * @return Stream
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        \set_error_handler(static function () {
            return true; // Don't execute PHP internal error handler
        });
        $resource = \fopen($filename, $mode);
        \restore_error_handler();
        if ($resource === false) {
            if ($mode === '' || \in_array($mode[0], ['r', 'w', 'a', 'x', 'c'], true) === false) {
                throw new InvalidArgumentException('The mode "' . $mode . '" is invalid.');
            }
            throw new RuntimeException(\sprintf(
                'The file %s cannot be opened.',
                $filename
            ));
        }
        return $this->createStreamFromResource($resource);
    }

    /**
     * Create a new stream from an existing resource.
     *
     * The stream MUST be readable and may be writable.
     *
     * @param resource $resource The PHP resource to use as the basis for the stream.
     *
     * @return Stream
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }

    /**
     * Create a new uploaded file.
     *
     * If a size is not provided it will be determined by checking the size of
     * the stream.
     *
     * @param StreamInterface $stream          The underlying stream
     *              representing the uploaded file content.
     * @param int|null        $size            The size of the file in bytes.
     * @param int             $error           The PHP file upload error.
     * @param string|null     $clientFilename  The filename as provided by the client, if any.
     * @param string|null     $clientMediaType The media type as provided by the client, if any.
     * @param string|null     $clientFullPath  The full-path as provided by the client, if any.
     *
     * @return UploadedFile
     *
     * @throws InvalidArgumentException If the file resource is not readable.
     *
     * @link http://php.net/manual/features.file-upload.post-method.php
     * @link http://php.net/manual/features.file-upload.errors.php
     */
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
        ?string $clientFullPath = null
    ): UploadedFileInterface
    {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType, $clientFullPath);
    }

    /**
     * Create a new URI.
     *
     * @param string $uri The URI to parse.
     *
     * @throws InvalidArgumentException If the given URI cannot be parsed.
     *
     * @return Uri
     */
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
