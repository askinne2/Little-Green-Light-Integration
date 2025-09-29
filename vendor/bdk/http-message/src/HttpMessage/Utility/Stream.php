<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2024-2025 Brad Kent
 * @since     x.3.3
 */

namespace bdk\HttpMessage\Utility;

use Exception;
use Psr\Http\Message\StreamInterface;

/**
 * Stream Utilities
 *
 * @psalm-api
 */
class Stream
{
    /**
     * Get stream contents without affecting pointer
     *
     * @param StreamInterface $stream StreamInterface
     *
     * @return string
     */
    public static function getContents(StreamInterface $stream): string
    {
        try {
            $pos = $stream->tell();
            $body = (string) $stream; // __toString() is like getContents(), but without throwing exceptions
            $stream->seek($pos);
            return $body;
            // @codeCoverageIgnoreStart
        } catch (Exception $e) {
            return '';
            // @codeCoverageIgnoreEnd
        }
    }
}
