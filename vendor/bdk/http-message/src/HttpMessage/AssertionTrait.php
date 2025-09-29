<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2020-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

/**
 * Assertions for Message, Request, ServerRequest, & Response
 */
trait AssertionTrait
{
    /**
     * Valid HTTP version numbers.
     *
     * @var numeric-string[]
     */
    protected $validProtocolVers = [
        '0.9',
        '1.0',
        '1.1',
        '2',
        '2.0',
        '3',
        '3.0',
    ];

    /**
     * Test that value is a string (or optionally numeric)
     *
     * @param mixed  $value        The value to check.
     * @param string $what         The name of the value.
     * @param bool   $allowNumeric Allow float or int?
     * @param bool   $allowNull    Allow null?
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert string $value
     */
    protected function assertString($value, string $what = '', bool $allowNumeric = false, $allowNull = false): void
    {
        if (\is_string($value)) {
            return;
        }
        if ($allowNull && $value === null) {
            return;
        }
        if ($allowNumeric && \is_numeric($value)) {
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            '%s must be a string, %s provided.',
            \ucfirst($what),
            \bdk\HttpMessage\Utility\ParseStr::getDebugType($value)
        ));
    }

    /*
        Message assertions
    */

    /**
     * Test valid header name
     *
     * @param mixed $name header name
     *
     * @return void
     * @throws InvalidArgumentException
     *
     * @psalm-assert non-empty-string $name
     */
    private function assertHeaderName($name): void
    {
        $this->assertString($name, 'Header name', true);
        if ($name === '') {
            throw new InvalidArgumentException('Header name can not be empty.');
        }
        /*
            see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2.6
            alpha  => a-zA-Z
            digit  => 0-9
            others => !#$%&\'*+-.^_`|~
        */
        if (\preg_match('/^[a-zA-Z0-9!#$%&\'*+-.^_`|~]+$/D', $name) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                '"%s" is not valid header name, it must be an RFC 7230 compatible string.',
                $name
            ));
        }
    }

    /**
     * Test valid header value
     *
     * @param mixed $value Value to test
     *
     * @return void
     * @throws InvalidArgumentException
     *
     * @psalm-assert non-empty-string|int|float|string[]|int[]|float[] $value
     */
    private function assertHeaderValue($value): void
    {
        if (\is_scalar($value) && \is_bool($value) === false) {
            $value = [(string) $value];
        }
        if (\is_array($value) === false) {
            throw new InvalidArgumentException(\sprintf(
                'The header field value only accepts string and array, %s provided.',
                \bdk\HttpMessage\Utility\ParseStr::getDebugType($value)
            ));
        }
        if (empty($value)) {
            throw new InvalidArgumentException(
                'Header value can not be empty array.'
            );
        }
        foreach ($value as $item) {
            $this->assertHeaderValueLine($item);
        }
    }

    /**
     * Validate header value
     *
     * headers values should be ISO-8859-1 encoded by default
     *
     * field-value    = *( field-content / obs-fold )
     * field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
     * field-vchar    = VCHAR / obs-text
     * VCHAR          = %x21-7E
     * obs-text       = %x80-FF
     * obs-fold       = CRLF 1*( SP / HTAB )

     * @param mixed $value Header value to test
     *
     * @return void
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.2
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert string $value
     */
    private function assertHeaderValueLine($value): void
    {
        if ($value === '') {
            return;
        }
        $this->assertString($value, 'Header value', true);
        $value = \trim((string) $value, " \t");
        // The regular expression intentionally does not support the obs-fold production, because as
        // per RFC 7230#3.2.4:
        //
        // A sender MUST NOT generate a message that includes
        // line folding (i.e., that has any field-value that contains a match to
        // the obs-fold rule) unless the message is intended for packaging
        // within the message/http media type.
        //
        // Clients must not send a request with line folding and a server sending folded headers is
        // likely very rare. Line folding is a fairly obscure feature of HTTP/1.1 and thus not accepting
        // folding is not likely to break any legitimate use case.
        if (\preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                '"%s" is not valid header value.',
                $value
            ));
        }
    }

    /**
     * Check out whether a protocol version number is supported.
     *
     * @param mixed $version HTTP protocol version.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert numeric-string $version
     */
    private function assertProtocolVersion($version): void
    {
        if (\is_numeric($version) === false) {
            throw new InvalidArgumentException(\sprintf(
                'Unsupported HTTP protocol version number. %s provided.',
                \bdk\HttpMessage\Utility\ParseStr::getDebugType($version)
            ));
        }
        if (\in_array((string) $version, $this->validProtocolVers, true) === false) {
            throw new InvalidArgumentException(\sprintf(
                'Unsupported HTTP protocol version number. "%s" provided.',
                $version
            ));
        }
    }

    /*
        Request assertions
    */

    /**
     * Assert valid method
     *
     * @param mixed $method Value to assert
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert non-empty-string $method
     */
    protected function assertMethod($method): void
    {
        $this->assertString($method, 'HTTP method');
        if ($method === '') {
            throw new InvalidArgumentException('Method must be a non-empty string.');
        }
        if (\preg_match('/^[a-z]+$/i', $method) !== 1) {
            throw new InvalidArgumentException('Method name must contain only ASCII alpha characters');
        }
    }

    /*
        ServerRequest assertions
    */

    /**
     * Assert valid attribute name
     *
     * @param string $name  Attribute name
     * @param bool   $throw (false) Whether to throw InvalidArgumentException
     *
     * @return bool
     * @throws InvalidArgumentException if $throw === true
     */
    protected function assertAttributeName($name, bool $throw = true): bool
    {
        try {
            $this->assertString($name, 'Attribute name', true);
        } catch (InvalidArgumentException $e) {
            // for versions with string type hint (2.x & 3.x), this will never be reached
            if ($throw) {
                throw $e;
            }
            return false;
        }
        return true;
    }

    /**
     * Assert valid cookie parameters
     *
     * @param array $cookies Cookie parameters
     *
     * @return void
     * @throws InvalidArgumentException
     *
     * @see https://httpwg.org/http-extensions/draft-ietf-httpbis-rfc6265bis.html#name-syntax
     */
    protected function assertCookieParams(array $cookies): void
    {
        $nameRegex = '/^[!#-+\--:<-[\]-~]+$/';
        \array_walk(
            $cookies,
            /**
             * @param string $value cookie value
             * @param string $name  cookie name
             */
            function ($value, $name) use ($nameRegex) {
                if (\preg_match($nameRegex, (string) $name) !== 1) {
                    throw new InvalidArgumentException(\sprintf(
                        'Invalid cookie name specified: %s',
                        $name
                    ));
                }
                $this->assertString($value, 'Cookie value', true);
            }
        );
    }

    /**
     * Assert valid query parameters
     *
     * typically $_GET and parse_str will only return arrays of strings.
     *  We'll allow numeric, bool, and null values
     *
     * @param array $get Query parameters
     *
     * @return void
     * @throws InvalidArgumentException
     */
    protected function assertQueryParams(array $get): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator($get),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $value) {
            // treat object as an invalid leaf
            $hasChildren = $iterator->hasChildren() && \is_object($value) === false;
            if ($hasChildren || $value === null || \is_scalar($value)) {
                continue;
            }
            throw new InvalidArgumentException(\sprintf(
                'Query params must only contain scalar values, %s contains %s.',
                $this->iteratorPath($iterator),
                \bdk\HttpMessage\Utility\ParseStr::getDebugType($value)
            ));
        }
    }

    /**
     * Throw an exception if an unsupported argument type is provided.
     *
     * @param array|object|null $data The deserialized body data. This will
     *     typically be in an array or object.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    protected function assertParsedBody($data): void
    {
        if (
            $data === null ||
            \is_array($data) ||
            \is_object($data)
        ) {
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            'ParsedBody must be array, object, or null. %s provided.',
            \bdk\HttpMessage\Utility\ParseStr::getDebugType($data)
        ));
    }

    /**
     * Recursively validate the structure in an uploaded files array.
     *
     * @param array $uploadedFiles uploaded files tree
     *
     * @return void
     *
     * @throws InvalidArgumentException if any leaf is not an UploadedFileInterface instance.
     */
    protected function assertUploadedFiles(array $uploadedFiles): void
    {
        \array_walk_recursive($uploadedFiles, static function ($val) {
            if (!($val instanceof UploadedFileInterface)) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid file in uploaded files structure. Expected UploadedFileInterface, %s provided',
                    \bdk\HttpMessage\Utility\ParseStr::getDebugType($val)
                ));
            }
        });
    }

    /*
        Response assertions
    */

    /**
     * Validate reason phrase
     *
     * @param mixed $phrase Reason phrase to test
     *
     * @return void
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7230#section-3.1.2
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert string $phrase
     */
    protected function assertReasonPhrase($phrase): void
    {
        if ($phrase === '') {
            return;
        }
        $this->assertString($phrase, 'Reason-phrase');
        // Don't allow control characters (incl \r & \n)
        if (\preg_match('#[^\P{C}\t]#u', $phrase, $matches, PREG_OFFSET_CAPTURE) === 1) {
            throw new InvalidArgumentException(\sprintf(
                'Reason phrase contains a prohibited character at position %s.',
                $matches[0][1]
            ));
        }
    }

    /**
     * Validate status code
     *
     * @param int|string $code Status Code
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert numeric $code
     */
    protected function assertStatusCode($code): void
    {
        if (\is_string($code) && \preg_match('/^\d+$/', $code)) {
            $code = (int) $code;
        }
        if (\is_int($code) === false) {
            throw new InvalidArgumentException(\sprintf(
                'Status code must to be an integer, %s provided.',
                \bdk\HttpMessage\Utility\ParseStr::getDebugType($code)
            ));
        }
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException(\sprintf(
                'Status code has to be an integer between 100 and 599. A status code of %d was given',
                $code
            ));
        }
    }

    /*
        Helper methods
    */

    /**
     * Get the path to the current position of an iterator
     *
     * @param RecursiveIteratorIterator $iterator Iterator instance
     *
     * @return string Path to current position as a string
     */
    private function iteratorPath(RecursiveIteratorIterator $iterator): string
    {
        $path = [];
        for ($i = 0, $depth = $iterator->getDepth(); $i <= $depth; $i++) {
            $key = $iterator->getSubIterator($i)->key();
            $path[] = $i > 0
                ? '["' . \addslashes($key) . '"]'
                : $key;
        }
        return \implode('', $path);
    }
}
