<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2020-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage;

use bdk\HttpMessage\AssertionTrait;
use bdk\HttpMessage\Stream;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Http Message
 *
 * HTTP messages consist of requests from a client to a server and responses
 * from a server to a client. This interface defines the methods common to
 * each.
 *
 * Messages are considered immutable; all methods that might change state are
 * implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 *
 * @link http://www.ietf.org/rfc/rfc7230.txt
 * @link http://www.ietf.org/rfc/rfc7231.txt
 *
 * @psalm-consistent-constructor
 */
class Message implements MessageInterface
{
    use AssertionTrait;

    /**
     * @var StreamInterface|null
     */
    private $body;

    /**
     * @var array<string,string[]> Map of all registered headers, as name => array of values
     */
    private $headers = array();

    /**
     * @var array<string,string> Map of lowercase header name => original name at registration
     */
    private $headerNames = array();

    /** @var string */
    protected $protocolVersion = '1.1';

    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * @return string HTTP protocol version (e.g., "1.1", "1.0").
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     *
     * The version string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @param string $version HTTP protocol version
     *
     * @return static
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        $this->assertProtocolVersion($version);
        if ($version === $this->protocolVersion) {
            return $this;
        }
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    /**
     * {@inheritDoc}
     *
     * @return string[][] Returns an associative array of the message's headers. Each
     *     key is a header name, and each value is an array of strings for that header.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name Case-insensitive header field name.
     *
     * @return bool Returns true if any header names match the given header
     *     name using a case-insensitive string comparison. Returns false if
     *     no matching header name is found in the message.
     */
    public function hasHeader(string $name): bool
    {
        $nameLower = \strtolower($name);
        return isset($this->headerNames[$nameLower]);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * @param string $name header name
     *
     * @return string[] An array of string values as provided for the given
     *    header. If the header does not appear in the message, an empty array is returned.
     */
    public function getHeader(string $name): array
    {
        $nameLower = \strtolower($name);
        if (!isset($this->headerNames[$nameLower])) {
            return [];
        }
        $name = $this->headerNames[$nameLower];
        return $this->headers[$name];
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all of the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * *NOTE*: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use `getHeader()` instead
     * and supply your own delimiter when concatenating.
     *
     * If the header does not appear in the message, this method will return
     * an empty string.
     *
     * @param string $name Case-insensitive header field name.
     *
     * @return string A string of values as provided for the given header
     *    concatenated together using a comma. If the header does not appear in
     *    the message, this method will return an empty string.
     */
    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * @param string          $name  Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     *
     * @return static
     * @throws InvalidArgumentException for invalid header name or value
     */
    public function withHeader(string $name, $value): MessageInterface
    {
        $this->assertHeaderName($name);
        $name = $this->normalizeHeaderName($name);
        $this->assertHeaderValue($value);
        $values = $this->normalizeHeaderValue($value);
        $nameLower = \strtolower($name);
        $new = clone $this;
        if (isset($new->headerNames[$nameLower])) {
            // remove previous header-name
            $namePrev = $new->headerNames[$nameLower];
            unset($new->headers[$namePrev]);
        }
        $new->headerNames[$nameLower] = $name;
        $new->headers[$name] = $values;
        if ($nameLower === 'host') {
            $new->afterUpdateHost();
        }
        return $new;
    }

    /**
     * Return an instance with the specified header values appended to the current value
     *
     * Existing values for the specified header will be maintained.
     * The new value(s) will be appended to the existing list.
     * If the header did not exist previously, it will be added.
     *
     * @param string          $name  Case-insensitive header field name to add.
     * @param string|string[] $value Header value(s).
     *
     * @return static
     * @throws InvalidArgumentException for invalid header name or value
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        // assert before using as array key (which will typecast)
        $this->assertHeaderName($name);
        $new = clone $this;
        $new->setHeaders(array(
            $name => $value,
        ));
        return $new;
    }

    /**
     * Return an instance without the specified header.
     *
     * @param string $name Case-insensitive header field name to remove.
     *
     * @return static
     */
    public function withoutHeader(string $name): MessageInterface
    {
        $nameLower = \strtolower($name);
        if (!isset($this->headerNames[$nameLower])) {
            return $this;
        }
        $new = clone $this;
        unset($new->headers[$name], $new->headerNames[$nameLower]);
        return $new;
    }

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface The body as a stream.
     */
    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            $this->body = new Stream();
        }
        return $this->body;
    }

    /**
     * Return an instance with the specified message body.
     *
     * @param StreamInterface $body Body
     *
     * @return static
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        if ($body === $this->body) {
            return $this;
        }
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /**
     * Assert only one Host value / sort Host header to beginning
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private function afterUpdateHost(): void
    {
        $this->headers['Host'] = \array_unique($this->headers['Host']);
        if (\count($this->headers['Host']) > 1) {
            throw new InvalidArgumentException(
                'Only one Host header is allowed.'
            );
        }
        // Ensure Host is the first header.
        // See: https://datatracker.ietf.org/doc/html/rfc7230#section-5.4
        if (isset($this->headers['Host'])) {
            $this->headers = \array_replace(
                array('Host' => $this->headers['Host']),
                $this->headers
            );
        }
    }

    /**
     * Normalize header name
     *
     * @param string $name header name
     *
     * @return string
     */
    private function normalizeHeaderName(string $name): string
    {
        $nameLower = \strtolower($name);
        return $nameLower === 'host'
            ? 'Host'
            : $name;
    }

    /**
     * Trims whitespace from the header value(s).
     *
     * Spaces and tabs ought to be excluded by parsers when extracting the field value from a header field.
     *
     * header-field = field-name ":" OWS field-value OWS
     * OWS          = *( SP / HTAB )
     *
     * @param non-empty-string|int|float|string[]|int[]|float[] $value header value
     *
     * @return string[] Trimmed header values
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2.4
     */
    private function normalizeHeaderValue($value): array
    {
        $values = (array) $value;
        $values = \array_map(static function ($value) {
            return \trim((string) $value, " \t");
        }, $values);
        return \array_values($values);
    }

    /**
     * Set header values
     *
     * @param array $headers header name/value pairs
     *
     * @return void
     */
    protected function setHeaders(array $headers): void
    {
        \array_walk($headers, function ($value, $name) {
            if (\is_int($name)) {
                // Numeric array keys are converted to int by PHP but having a header name '123' is not forbidden by the spec
                // and also allowed in withHeader(). So we need to cast it to string again for the following assertion to pass.
                $name = (string) $name;
            }
            $this->assertHeaderName($name);
            $this->assertHeaderValue($value);
            $name = $this->normalizeHeaderName($name);
            $values = $this->normalizeHeaderValue($value);
            $this->setHeaderValues($name, $values);
        });
    }

    /**
     * Append the given values
     *
     * @param string   $name   header name
     * @param string[] $values header values
     *
     * @return void
     */
    private function setHeaderValues(string $name, array $values): void
    {
        $nameLower = \strtolower($name);
        if (isset($this->headerNames[$nameLower])) {
            $name = $this->headerNames[$nameLower];
            $values = \array_merge($this->headers[$name], $values);
        }
        $this->headerNames[$nameLower] = $name;
        $this->headers[$name] = $values;
        if ($nameLower === 'host') {
            $this->afterUpdateHost();
        }
    }
}
