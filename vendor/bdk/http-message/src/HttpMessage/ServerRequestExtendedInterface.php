<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2024-2025 Brad Kent
 * @since     1.3
 */

namespace bdk\HttpMessage;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Extends standard server request with helpful methods
 *
 * Note: This interface is not part of the PSR-7 standard.
 *
 * Heavily inspired by Slim Framework's ServerRequest decorator
 *
 * We have opted not to provide the following methods:
 *  - getContentCharset  (simple wrapper for `getMediaTypeParams()['charset']`)
 *  - getContentLength   (simple wrapper for `getHeader('Content-Length')`)
 *  - getContentType     (simple wrapper for `getHeader('Content-Type')`)
 *  - isDelete           (`getMethod() === 'DELETE'`)
 *  - isGet              (`getMethod() === 'GET'`)
 *  - isHead             (`getMethod() === 'HEAD'`)
 *  - isMethod           (`getMethod() === $method`)
 *  - isOptions          (`getMethod() === 'DELETE'`)
 *  - isPatch            (`getMethod() === 'PATCH'`)
 *  - isPost             (`getMethod() === 'POST'`)
 *  - isPut              (`getMethod() === 'PUT'`)
 *
 * @link https://github.com/slimphp/Slim-Http/blob/master/src/ServerRequest.php "Forked" from Slim Framework's ServerRequest
 */
interface ServerRequestExtendedInterface extends ServerRequestInterface
{
    /**
     * Create a ServerRequestExtended object from ServerRequest
     *
     * If a `ServerRequestExtendedInterface` instance is passed to this method,
     * Implementation __should__ return it unmodified.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param ServerRequestInterface $serverRequest ServerRequest instance
     *
     * @return ServerRequestExtendedInterface
     */
    public static function fromServerRequest(ServerRequestInterface $serverRequest): ServerRequestExtendedInterface;

    /**
     * Fetch cookie value from cookies sent by the client to the server.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The attribute name.
     * @param mixed  $default Default value to return if the cookie value does not exist.
     *
     * @return mixed
     */
    public function getCookieParam(string $key, $default = null);

    /**
     * Get serverRequest media type, if known.
     *
     * Parses the Content-Type header and returns the "media type"
     *
     * example:
     * Content-Type: multipart/form-data; Charset=UTF-8; boundary=ExampleBoundaryString
     *
     * `getMediaType()` will return "multipart/form-data"
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return string|null The serverRequest media type, minus content-type params
     */
    public function getMediaType(): ?string;

    /**
     * Get serverRequest media type params, if known.
     *
     * Parses and returns the parameters found after the "media type" in the Content-Type header.
     *
     * example:
     * Content-Type: multipart/form-data; Charset=UTF-8; boundary=ExampleBoundaryString
     *
     * `getMediaTypeParams()` will return
     *
     *     [
     *       'charset' => 'utf-8',
     *       'boundary' => 'ExampleBoundaryString'
     *     ]
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return string[]
     *
     * @link https://www.rfc-editor.org/rfc/rfc7231#section-3.1.1.1
     * @link https://www.rfc-editor.org/rfc/rfc7230#section-3.2.6
     */
    public function getMediaTypeParams(): array;

    /**
     * Fetch request parameter value from body or query string (in that order).
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The parameter key.
     * @param string $default The default value.
     *
     * @return mixed The parameter value.
     */
    public function getParam(string $key, $default = null);

    /**
     * Fetch associative array of body and query string parameters.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return array
     */
    public function getParams(): array;

    /**
     * Fetch parameter value from request body.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The param key.
     * @param mixed  $default The default value
     *
     * @return mixed Body param value, or `$default` if not set
     */
    public function getParsedBodyParam(string $key, $default = null);

    /**
     * Fetch parameter value from query string.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The param key
     * @param mixed  $default The default value
     *
     * @return mixed Body query param value, or `$default` if not set
     */
    public function getQueryParam(string $key, $default = null);

    /**
     * Retrieve a server parameter.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The Param key
     * @param mixed  $default The default value
     *
     * @return mixed Server param value, or `$default` if not set
     */
    public function getServerParam(string $key, $default = null);

    /**
     * Is this a secured request?
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return bool
     */
    public function isSecure(): bool;

    /**
     * Is this an XHR (aka ajax) request?
     *
     * Note 1: This method is not part of the PSR-7 standard.
     * Note 2: This method likely relies on the non-standard X-Requested-With header
     * Note 3: Javascript's fetch API does not pass any distinguishing headers
     *
     * @return bool
     */
    public function isXhr(): bool;

    /**
     * Register media type parser.
     *
     * Define a custom body parser for a specific media type.
     *
     * Implementation MUST be implemented in such a way that
     * `getParsedBody()` will utilize registered parsers if
     * parsed-body not explicitly set
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string   $contentType A HTTP media type (excluding content-type params).
     * @param callable $callable    A callable that receives the request body as a string
     *                                and returns parsed contents
     *
     * @return static
     */
    public function registerMediaTypeParser(string $contentType, callable $callable): ServerRequestInterface;

    /**
     * Return an instance with the specified derived request attributes.
     *
     * This method allows setting multiple derived request attributes as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * upserted attributes.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param array<string,mixed> $attributes New attributes
     *
     * @return static
     */
    public function withAttributes(array $attributes): ServerRequestExtendedInterface;
}
