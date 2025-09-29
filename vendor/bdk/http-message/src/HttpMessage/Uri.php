<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage;

use bdk\HttpMessage\AbstractUri;
use bdk\HttpMessage\Utility\Uri as UriUtil;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Value object representing a URI.
 *
 * Represent a URI according to RFC 3986 and provide methods for most common operations.
 *
 * Instances of this interface are considered immutable; all methods that
 * might change state are implemented such that they retain the internal
 * state of the current instance and return an instance that contains the
 * changed state.
 *
 * @link http://tools.ietf.org/html/rfc3986 (the URI specification)
 */
class Uri extends AbstractUri implements UriInterface
{
    /** @var string Uri scheme. */
    private $scheme = '';

    /** @var string The URI user information, in "username[:password]" format. (or empty string) */
    private $userInfo = '';

    /** @var string Uri host. */
    private $host = '';

    /** @var null|int Uri port. */
    private $port;

    /** @var string Uri path. */
    private $path = '';

    /** @var string Uri query string. */
    private $query = '';

    /** @var string Uri fragment. */
    private $fragment = '';

    /**
     * Constructor
     *
     * @param string|null $uri Uri to wrap
     *
     * @throws InvalidArgumentException
     */
    public function __construct($uri = null)
    {
        if ($uri === null) {
            $uri = '';
        }
        $this->assertString($uri, 'uri');
        if ($uri === '') {
            return;
        }
        $parts = UriUtil::parseUrl($uri);
        if ($parts === false) {
            throw new InvalidArgumentException('Unable to parse URI: ' . $uri);
        }
        $this->setUrlParts($parts);
    }

    /**
     * Return stringified value
     *
     * @return string
     */
    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        $uri .= self::createUriPath($authority, $this->path);
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }
        return $uri;
    }

    /**
     * Get a Uri populated with values from $_SERVER.
     *
     * @return self
     *
     * @deprecated Use `\bdk\HttpMessage\Utiity\Uri::fromGlobals` instead
     */
    public static function fromGlobals(): UriInterface
    {
        return UriUtil::fromGlobals();
    }

    /**
     * Retrieve the scheme component of the URI.
     *
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Retrieve the authority component of the URI.
     *
     * If the port component is not set or is the standard port for the current
     * scheme, it will not be included
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.2
     *
     * @return string The URI authority, in "[user-info@]host[:port]" format. (or empty string)
     */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ':' . $port;
        }
        return $authority;
    }

    /**
     * Retrieve the user information component of the URI.
     *
     * If a user is present in the URI, this will return that value;
     * additionally, if the password is also present, it will be appended to the
     * user value, with a colon (":") separating the values.
     *
     * The trailing "@" character is not part of the user information and will not be included
     *
     * @return string The URI user information, in "username[:password]" format. (or empty string)
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * Retrieve the host component of the URI.
     *
     * The value returned will be normalized to lowercase, per RFC 3986
     * Section 3.2.2.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.2.2
     *
     * @return string The URI host (or empty string).
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Retrieve the port component of the URI.
     *
     * If the port is the standard port used with the current scheme, null will be returned.
     *
     * If no port is present, and no scheme is present, null will be returned
     *
     * If no port is present, but a scheme is present, null will be returned.
     *
     * @return null|int The URI port.
     */
    public function getPort(): ?int
    {
        return $this->isStandardPort($this->scheme, $this->port)
            ? null
            : $this->port;
    }

    /**
     * Retrieve the path component of the URI.
     *
     * The path can either be
     *    empty or
     *    absolute (starting with a slash)
     *    rootless (not starting with a slash).
     *
     * Normally, the empty path "" and absolute path "/" are considered equal as
     * defined in RFC 7230 Section 2.7.3. But this method does automatically
     * do this normalization because in contexts with a trimmed base path, e.g.
     * the front controller, this difference becomes significant.
     * It's the task of the user to handle both "" and "/".
     *
     * The value returned will be percent-encoded, but will not double-encode
     * any characters.
     * see RFC 3986, Sections 2 and 3.3.
     *
     * As an example, if the value should include a slash ("/") not intended as
     * delimiter between path segments, that value will be passed in encoded
     * form (e.g., "%2F") to the instance.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-2
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3
     *
     * @return string The URI path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieve the query string of the URI.
     *
     * The leading "?" character is not part of the query and will not be
     * included.
     *
     * The value returned will be percent-encoded,
     * but will not double-encode any characters.
     * see RFC 3986, Sections 2 and 3.4.
     *
     * As an example, if a value in a key/value pair of the query string should
     * include an ampersand ("&") not intended as a delimiter between values,
     * that value MUST be passed in encoded form (e.g., "%26") to the instance.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-2
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.4
     *
     * @return string The URI query string. (or empty string)
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retrieve the fragment component of the URI.
     *
     * The leading "#" character is not part of the fragment and MUST NOT be
     * added.
     *
     * The value returned will be percent-encoded,
     * but will not double-encode any characters.
     * see RFC 3986, Sections 2 and 3.5.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-2
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.5
     *
     * @return string The URI fragment. (or empty string)
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Return an instance with the specified scheme.
     *
     * An empty scheme is equivalent to removing the scheme.
     *
     * @param string $scheme The scheme to use with the new instance.
     *
     * @return static A new instance with the specified scheme.
     *
     * @throws InvalidArgumentException for invalid / unsupported schemes.
     */
    public function withScheme(string $scheme): UriInterface
    {
        $this->assertScheme($scheme);
        $scheme = self::lowercase($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }
        $new = clone $this;
        $new->scheme = $scheme;
        $new->port = $new->filterPort($new->port);
        return $new;
    }

    /**
     * Return an instance with the specified user information.
     *
     * Password is optional, but the user information MUST include the
     * user; an empty string for the user is equivalent to removing user
     * information.
     *
     * @param string      $user     The user name to use for authority.
     * @param null|string $password The password associated with $user.
     *
     * @return static A new instance with the specified user information.
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $this->assertString($user, 'user', true);
        $this->assertString($password, 'password', true, true);
        $userInfo = (string) $user; // for versions without type hint in method signature
        $password = (string) $password; // for versions without type hint in method signature
        if ($userInfo !== '' && $password !== '') {
            $userInfo .= ':' . $password;
        }
        if ($userInfo === $this->userInfo) {
            return $this;
        }
        $new = clone $this;
        $new->userInfo = $userInfo;
        return $new;
    }

    /**
     * Return an instance with the specified host.
     *
     * An empty host value is equivalent to removing the host.
     *
     * @param string $host The hostname to use with the new instance.
     *
     * @return static A new instance with the specified host.
     *
     * @throws InvalidArgumentException for invalid hostnames.
     */
    public function withHost(string $host): UriInterface
    {
        $this->assertHost($host);
        $host = self::lowercase($host);
        if ($host === $this->host) {
            return $this;
        }
        $new = clone $this;
        $new->host = $host;
        return $new;
    }

    /**
     * Return an instance with the specified port.
     *
     * A null value provided for the port is equivalent to removing the port information.
     *
     * @param null|int $port The port to use with the new instance;
     *                          a null value removes the port information.
     *
     * @return static A new instance with the specified port.
     * @throws InvalidArgumentException for invalid ports.
     */
    public function withPort(?int $port): UriInterface
    {
        $port = $this->filterPort($port);
        if ($port === $this->port) {
            return $this;
        }
        $new = clone $this;
        $new->port = $port;
        return $new;
    }

    /**
     * Return an instance with the specified path.
     *
     * The path can either be
     *     empty
     *     absolute (starting with a slash)
     *     rootless (not starting with a slash).
     *
     * If an HTTP path is intended to be host-relative rather than path-relative
     * then it must begin with a slash ("/"). HTTP paths not starting with a slash
     * are assumed to be relative to some base path known to the application or
     * consumer.
     *
     * Users can provide both encoded and decoded path characters.
     *
     * @param string $path The path to use with the new instance.
     *
     * @return static A new instance with the specified path.
     *
     * @throws InvalidArgumentException for invalid paths.
     */
    public function withPath(string $path): UriInterface
    {
        $path = $this->filterPath($path);
        if ($path === $this->path) {
            return $this;
        }
        $new = clone $this;
        $new->path = $path;
        return $new;
    }

    /**
     * Return an instance with the specified query string.
     *
     * Users can provide both encoded and decoded query characters.
     *
     * An empty query string value is equivalent to removing the query string.
     *
     * @param string $query The query string to use with the new instance.
     *
     * @return static A new instance with the specified query string.
     * @throws InvalidArgumentException for invalid query strings.
     */
    public function withQuery(string $query): UriInterface
    {
        $this->assertString($query, 'query', true);
        $query = $this->filterQueryAndFragment($query);
        if ($query === $this->query) {
            return $this;
        }
        $new = clone $this;
        $new->query = $query;
        return $new;
    }

    /**
     * Return an instance with the specified URI fragment.
     *
     * Users can provide both encoded and decoded fragment characters.
     *
     * An empty fragment value is equivalent to removing the fragment.
     *
     * @param string $fragment The fragment to use with the new instance.
     *
     * @return static A new instance with the specified fragment.
     */
    public function withFragment(string $fragment): UriInterface
    {
        $this->assertString($fragment, 'fragment');
        $fragment = $this->filterQueryAndFragment($fragment);
        if ($fragment === $this->fragment) {
            return $this;
        }
        $new = clone $this;
        $new->fragment = $fragment;
        return $new;
    }

    /**
     * Set properties from parsed url
     *
     * @param array<string,int|string> $urlParts Url parts parsed from parse_url
     *
     * @return void
     */
    private function setUrlParts(array $urlParts)
    {
        $uri = UriUtil::fromParsed($urlParts);
        $validKeys = ['fragment', 'host', 'path', 'port', 'query', 'scheme', 'userInfo'];
        foreach ($validKeys as $key) {
            $method = 'get' . \ucfirst($key);
            $this->{$key} = $uri->{$method}();
        }
    }
}
