<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2024-2025 Brad Kent
 * @since     1.3
 */

namespace bdk\HttpMessage;

use bdk\HttpMessage\ServerRequestExtendedInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Extends standard server request with helpful methods
 *
 * Note: This class is not part of the PSR-7 standard.
 */
class ServerRequestExtended extends ServerRequest implements ServerRequestExtendedInterface
{
    /**
     * {@inheritDoc}
     */
    public static function fromServerRequest(ServerRequestInterface $serverRequest): ServerRequestExtendedInterface
    {
        if ($serverRequest instanceof ServerRequestExtendedInterface) {
            return $serverRequest;
        }

        $instance = new static(
            $serverRequest->getMethod(),
            $serverRequest->getUri(),
            $serverRequest->getServerParams()
        );

        $instance = $instance
            ->withProtocolVersion($serverRequest->getProtocolVersion())
            ->withAttributes($serverRequest->getAttributes())
            ->withBody($serverRequest->getBody())
            ->withCookieParams($serverRequest->getCookieParams())
            ->withParsedBody($serverRequest->getParsedBody())
            ->withQueryParams($serverRequest->getQueryParams())
            ->withRequestTarget($serverRequest->getRequestTarget())
            ->withUploadedFiles($serverRequest->getUploadedFiles());

        foreach ($serverRequest->getHeaders() as $name => $value) {
            $instance = $instance->withHeader($name, $value);
        }

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function getCookieParam(string $key, $default = null)
    {
        $cookies = $this->getCookieParams();
        return isset($cookies[$key])
            ? $cookies[$key]
            : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function getMediaType(): ?string
    {
        $contentType = $this->getHeaderLine('Content-Type');
        $contentTypeParts = \preg_split('/\s*[;,]\s*/', $contentType);
        return $contentTypeParts[0] !== ''
            ? $contentTypeParts[0]
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getMediaTypeParams(): array
    {
        $contentType = $this->getHeader('Content-Type');
        $params = array(
            'charset' => null,
        );

        if ($contentType === []) {
            return $params;
        }

        /*
        https://www.rfc-editor.org/rfc/rfc7231#section-3.1.1.1
        media-type = type "/" subtype *( OWS ";" OWS parameter )
        type       = token
        subtype    = token

        parameter      = token "=" ( token / quoted-string )

        https://www.rfc-editor.org/rfc/rfc7230#section-3.2.6
        */

        $paramString = \preg_replace('/^.*?[;,]\s*/', '', $contentType[0]);
        $regexToken = '[^\\s";,]+';
        $regexQuotedString = '"(?:\\\\"|[^"])*"';   // \" or not "
        $regex = '/
            (?P<key>' . $regexToken . ')
            \s*=\s*   # standard does not allow whitespace around =
            (?P<value>' . $regexQuotedString . '|' . $regexToken . ')
            /x';

        \preg_match_all($regex, $paramString, $matches, PREG_SET_ORDER);

        foreach ($matches as $kvp) {
            $key = \strtolower($kvp['key']);
            $value = \stripslashes(\trim($kvp['value'], '"'));
            if ($key === 'charset') {
                $value = \strtolower($value);
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     */
    public function getParam(string $key, $default = null)
    {
        $parsedBodyVal = $this->getParsedBodyParam($key);
        return $parsedBodyVal !== null
            ? $parsedBodyVal
            : $this->getQueryParam($key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function getParams(): array
    {
        $queryParams = $this->getQueryParams();
        $bodyParams = $this->getParsedBody() ?: array();

        $params = \array_merge($queryParams, (array) $bodyParams);
        \ksort($params);

        return $params;
    }

    /**
     * {@inheritDoc}
     */
    public function getParsedBodyParam(string $key, $default = null)
    {
        $bodyParams = $this->getParsedBody();
        if (\is_array($bodyParams) && isset($bodyParams[$key])) {
            return $bodyParams[$key];
        }
        if (\is_object($bodyParams) && \property_exists($bodyParams, $key)) {
            return $bodyParams->$key;
        }
        return $default;
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryParam(string $key, $default = null)
    {
        $queryParams = $this->getQueryParams();
        return isset($queryParams[$key])
            ? $queryParams[$key]
            : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerParam(string $key, $default = null)
    {
        $serverParams = $this->getServerParams();
        return isset($serverParams[$key])
            ? $serverParams[$key]
            : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function isSecure(): bool
    {
        return $this->getServerParam('HTTPS') === 'on';
    }

    /**
     * {@inheritDoc}
     */
    public function isXhr(): bool
    {
        return $this->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Create a new instance with the specified attributes.
     *
     * This method allows setting multiple derived request attributes as
     * described in getAttributes().
     *
     * This method is implemented in such a way as to retain the
     * immutability of the message, and returns an instance that has the
     * upserted attributes.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param array<string,mixed> $attributes New attributes
     *
     * @return static
     */
    public function withAttributes(array $attributes): ServerRequestExtendedInterface
    {
        $new = clone $this;
        foreach ($attributes as $attribute => $value) {
            $new = $new->withAttribute($attribute, $value);
        }
        return $new;
    }
}
