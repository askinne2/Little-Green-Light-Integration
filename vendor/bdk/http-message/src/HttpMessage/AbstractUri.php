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
use InvalidArgumentException;

/**
 * Extended by Uri
 *
 * All the non-public Uri bits
 *
 * @psalm-consistent-constructor
 */
abstract class AbstractUri
{
    use AssertionTrait;

    /**
     * @var string
     *
     * @internal
     */
    private const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';

    /**
     * @var string
     *
     * @internal
     */
    private const CHAR_UNRESERVED = 'a-zA-Z0-9_\-\.~';

    /** @var array<string,int> */
    private static $schemes = array(
        'ftp' => 21,
        'http' => 80,
        'https' => 443,
    );

    /**
     * Throw exception if invalid host string.
     *
     * @param string $host The host string to of a URI.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    protected function assertHost($host): void
    {
        $this->assertString($host, 'host');
        if (\in_array($host, ['', 'localhost'], true)) {
            // An empty host value is equivalent to removing the host.
            // No validation required
            return;
        }
        if ($this->isFqdn($host)) {
            return;
        }
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            // only if php < 7.0
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            '"%s" is not a valid host',
            $host
        ));
    }

    /**
     * Throw exception if invalid port value
     *
     * @param mixed $port port value
     *
     * @return void
     *
     * @throws InvalidArgumentException
     *
     * @psalm-assert int $port
     */
    protected function assertPort($port): void
    {
        if (\is_int($port) === false) {
            // for versions with int type-hint, this will never be reached
            throw new InvalidArgumentException(\sprintf(
                'Port must be a int, %s provided.',
                \bdk\HttpMessage\Utility\ParseStr::getDebugType($port)
            ));
        }
        if ($port < 1 || $port > 0xffff) {
            throw new InvalidArgumentException(\sprintf('Invalid port: %d. Must be between 0 and 65535', $port));
        }
    }

    /**
     * Assert valid scheme
     *
     * @param string $scheme Scheme to validate
     *
     * @return void
     * @throws InvalidArgumentException
     *
     * @psalm-assert string $scheme
     */
    protected function assertScheme($scheme): void
    {
        $this->assertString($scheme, 'scheme');
        if ($scheme === '') {
            return;
        }
        if (\preg_match('/^[a-z][-a-z0-9.+]*$/i', $scheme) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid scheme: "%s"',
                $scheme
            ));
        }
    }

    /**
     * Create path component of Uri
     *
     * @param string $authority Authority [user-info@]host[:port]
     * @param string $path      Path
     *
     * @return string
     */
    protected static function createUriPath(string $authority, string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if ($path[0] !== '/' && $authority !== '') {
            // If the path is rootless and an authority is present,
            // the path MUST be prefixed by "/"
            return '/' . $path;
        }
        if (\substr($path, 0, 2) === '//' && $authority === '') {
            // If the path is starting with more than one "/" and no authority is present,
            // starting slashes MUST be reduced to one.
            return '/' . \ltrim($path, '/');
        }
        return $path;
    }

    /**
     * Filter/validate path
     *
     * @param string $path URI path
     *
     * @return string
     * @throws InvalidArgumentException
     */
    protected function filterPath(string $path): string
    {
        $this->assertString($path, 'path');
        $specPattern = '%:@\/';
        $encodePattern = '%(?![A-Fa-f0-9]{2})';
        $regex = '/(?:[^' . self::CHAR_UNRESERVED . self::CHAR_SUB_DELIMS . $specPattern . ']+|' . $encodePattern . ')/';
        return $this->regexEncode($regex, $path);
    }

    /**
     * Filter/validate port
     *
     * @param null|int|string $port Port
     *
     * @return int|null
     * @throws InvalidArgumentException
     */
    protected function filterPort($port): ?int
    {
        if ($port === null) {
            return null;
        }
        if (\is_string($port) && \preg_match('/^\d+$/', $port)) {
            $port = (int) $port;
        }
        $this->assertPort($port);
        return $port;
    }

    /**
     * Filter/validate query and fragment
     *
     * @param string $str query or fragment
     *
     * @return string
     */
    protected function filterQueryAndFragment(string $str): string
    {
        $specPattern = '%:@\/\?';
        $encodePattern = '%(?![A-Fa-f0-9]{2})';
        $regex = '/(?:[^' . self::CHAR_UNRESERVED . self::CHAR_SUB_DELIMS . $specPattern . ']+|' . $encodePattern . ')/';
        return $this->regexEncode($regex, $str);
    }

    /**
     * Test if hostname is a fully-qualified domain name (FQDN)
     *
     * @param string $host Hostname to test
     *
     * @return bool
     *
     * @see https://www.regextester.com/103452
     */
    private function isFqdn(string $host): bool
    {
        if (PHP_VERSION_ID >= 70000) {
            return \filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        }
        $regexPartialHostname = '(?!-)[a-zA-Z0-9-]{0,62}[a-zA-Z0-9]';
        $regex1 = '/(?=^.{4,253}$)(^(' . $regexPartialHostname . '\.)+[a-zA-Z]{2,63}$)/';
        $regex2 = '/^' . $regexPartialHostname . '$/';
        return \preg_match($regex1, $host) === 1 || \preg_match($regex2, $host) === 1;
    }

    /**
     * Is a given port standard for the given scheme?
     *
     * @param string   $scheme Scheme
     * @param int|null $port   Port
     *
     * @return bool
     */
    protected static function isStandardPort(string $scheme, ?int $port): bool
    {
        return isset(self::$schemes[$scheme]) && $port === self::$schemes[$scheme];
    }

    /**
     * Perform Locale-independent lowercasing
     *
     * @param string $str String to lowercase
     *
     * @return string
     */
    protected static function lowercase(string $str): string
    {
        return \strtr(
            $str,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz'
        );
    }

    /**
     * Call rawurlencode on match
     *
     * @param non-empty-string $regex Regular expression
     * @param string           $str   string
     *
     * @return string
     */
    private static function regexEncode(string $regex, string $str): string
    {
        return \preg_replace_callback($regex, static function ($matches) {
            return \rawurlencode($matches[0]);
        }, $str);
    }
}
