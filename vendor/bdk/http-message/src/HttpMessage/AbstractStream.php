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
use RuntimeException;

/**
 * Extended by Stream
 */
abstract class AbstractStream
{
    /** @var array<string,string> */
    protected $strings = array(
        'detached' => 'Stream is detached',
        'fopenFail' => 'The file %s cannot be opened.',
        'posUnknown' => 'Unable to determine stream position',
        'readFail' => 'Unable to read from stream',
        'readFailNonReadable' => 'Unable to read from non-readable stream',
        'readLengthNegative' => 'Length parameter cannot be negative',
        'resourceInvalidType' => 'Expected resource, filename, or string. %s provided.',
        'seekFail' => 'Unable to seek to stream position %s with whence %s',
        'seekNonSeekable' => 'Stream is not seekable',
        'writeFail' => 'Unable to write to stream',
        'writeFailNonWritable' => 'Unable to write to a non-writable stream',
    );

    /** @var resource|closed-resource|null A resource reference */
    protected $resource;

    /**
     * Safely test if value is a file
     *
     * @param mixed $value The value to check
     *
     * @return bool
     *
     * @psalm-assert-if-true non-empty-string $value
     */
    protected static function isFile($value): bool
    {
        return \is_string($value)
            && \preg_match('#(://|[\r\n\x00])#', $value) !== 1
            && \is_file($value);
    }

    /**
     * Is resource open?
     *
     * @return bool
     *
     * @psalm-assert-if-true resource $this->resource
     */
    protected function isResourceOpen(): bool
    {
        return isset($this->resource) && \is_resource($this->resource);
    }

    /**
     * Set resource
     *
     * @param mixed $value Resource, filepath, or string content to wrap.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function setResource($value): void
    {
        if ($value === null) {
            $this->resource = \fopen('php://temp', 'wb+');
            return;
        }
        if (\is_resource($value)) {
            $this->resource = $value;
            return;
        }
        if ($this->isFile($value)) {
            $this->setResourceFile($value);
            return;
        }
        if (\is_string($value)) {
            $this->resource = \fopen('php://temp', 'wb+');
            \fwrite($this->resource, $value);
            \rewind($this->resource);
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            $this->strings['resourceInvalidType'],
            \bdk\HttpMessage\Utility\ParseStr::getDebugType($value)
        ));
    }

    /**
     * Set resource to the specified file
     *
     * @param string $file filepath
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function setResourceFile(string $file): void
    {
        \set_error_handler(static function () {
            return true; // Don't execute PHP internal error handler
        });
        $this->resource = \fopen($file, 'r');
        \restore_error_handler();
        if ($this->resource === false) {
            throw new RuntimeException(\sprintf(
                $this->strings['fopenFail'],
                $file
            ));
        }
    }
}
