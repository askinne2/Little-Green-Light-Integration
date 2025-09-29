<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2023-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage\Utility;

use bdk\HttpMessage\Uri as BdkUri;
use Psr\Http\Message\UriInterface;

/**
 * Uri Utilities
 *
 * @psalm-api
 */
class Uri
{
    /**
     * Get a Uri populated with values from `$_SERVER`.
     *
     * @return BdkUri
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function fromGlobals(): BdkUri
    {
        $parsed = \array_merge(
            array(
                'scheme' => isset($_SERVER['HTTPS']) && \filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN)
                    ? 'https'
                    : 'http',
            ),
            self::hostPortFromGlobals(),
            self::pathQueryFromGlobals()
        );
        return self::fromParsed($parsed);
    }

    /**
     * Get a Uri populated with component values
     *
     * Username & password accepted in multiple ways (highest precedence first):
     *  - userInfo ("username:password" or ["username", "password"])
     *  - user & pass
     *  - username & password
     *
     * @param array $parsed Url component values (ie from `parse_url()`)
     *
     * @return BdkUri
     *
     * @since x.3.2
     */
    public static function fromParsed(array $parsed): BdkUri
    {
        return self::withParsedValues(new BdkUri(), $parsed);
    }

    /**
     * Determines if two Uri's should be considered cross-origin
     *
     * @param UriInterface $uri1 Uri 1
     * @param UriInterface $uri2 Uri 2
     *
     * @return bool
     */
    public static function isCrossOrigin(UriInterface $uri1, UriInterface $uri2): bool
    {
        if (\strcasecmp($uri1->getHost(), $uri2->getHost()) !== 0) {
            return true;
        }
        if ($uri1->getScheme() !== $uri2->getScheme()) {
            return true;
        }
        return self::computePort($uri1) !== self::computePort($uri2);
    }

    /**
     * Parse URL (multi-byte safe)
     *
     * @param string|UriInterface $url The URL to parse.
     *
     * @return array<string,int|string>|false
     */
    public static function parseUrl($url)
    {
        if ($url instanceof UriInterface) {
            return self::uriInterfaceToParts($url);
        }
        // reserved chars
        $chars = '!*\'();:@&=$,/?#[]';
        $entities = \str_split(\urlencode($chars), 3);
        $chars = \str_split($chars);
        $urlEnc = \str_replace($entities, $chars, \urlencode($url));
        $parts = self::parseUrlPatched($urlEnc);
        return \is_array($parts)
            ? \array_map(static function ($val) {
                return \is_string($val)
                    ? \urldecode($val)
                    : $val;
            }, $parts)
            : $parts;
    }

    /**
     * Converts the relative URI into a new URI that is resolved against the base URI.
     *
     * @param UriInterface $base Base URI
     * @param UriInterface $rel  Relative URI
     *
     * @return UriInterface
     *
     * @link http://tools.ietf.org/html/rfc3986#section-5.2
     */
    public static function resolve(UriInterface $base, UriInterface $rel): UriInterface
    {
        if ((string) $rel === '') {
            // we can simply return the same base URI instance for this same-document reference
            return $base;
        }
        if ($rel->getScheme() !== '') {
            // rel specified scheme
            //   return rel (with path cleaned up)
            return $rel->withPath(self::pathRemoveDots($rel->getPath()));
        }
        $targetValues = array(
            'fragment' => $rel->getFragment(),
        );
        if ($rel->getAuthority() !== '') {
            // rel specified "authority"
            //   return base's scheme, rel's everything else (with path cleaned up)
            $targetValues['userInfo'] = $rel->getUserInfo();
            $targetValues['host'] = $rel->getHost();
            $targetValues['port'] = $rel->getPort();
            $targetValues['path'] = self::pathRemoveDots($rel->getPath());
            $targetValues['query'] = $rel->getQuery();
        } elseif ($rel->getPath() !== '') {
            // rel specified path
            //   return base with resolved path (cleaned up), rel's query & fragment
            $targetValues['path'] = self::pathRemoveDots(self::resolveTargetPath($base, $rel));
            $targetValues['query'] = $rel->getQuery();
        } elseif ($rel->getQuery() !== '') {
            // rel specified query
            $targetValues['query'] = $rel->getQuery();
        }
        return self::withParsedValues($base, $targetValues);
    }

    /**
     * Apply component values to a Uri
     *
     * @param UriInterface $uri    UriInterface instance
     * @param array        $values Component values
     *
     * @return UriInterface
     *
     * @since x3.4
     */
    public static function withParsedValues(UriInterface $uri, array $values): UriInterface
    {
        $keys = ['fragment', 'host', 'path', 'port', 'query', 'scheme', 'userInfo'];
        $nullToStringKeys = ['fragment', 'host', 'path', 'query', 'scheme'];
        $values = \array_intersect_key(self::parsedPartsPrep($values), \array_flip($keys));
        \array_walk($values, static function ($value, $key) use ($nullToStringKeys, &$uri) {
            if ($value === null && \in_array($key, $nullToStringKeys, true)) {
                $value = '';
            }
            $method = 'with' . \ucfirst($key);
            // using call_user_func_array...  some methods (withUserInfo) accept multiple arguments
            $uri = \call_user_func_array([$uri, $method], $value === null
                ? array(null)
                : (array) $value);
        });
        return $uri;
    }

    /**
     * @param UriInterface $uri Uri instance
     *
     * @return int
     */
    private static function computePort(UriInterface $uri): int
    {
        $port = $uri->getPort();
        if ($port !== null) {
            return $port;
        }
        return $uri->getScheme() === 'https' ? 443 : 80;
    }

    /**
     * Converted parsed values to keys used by `fromParsed()`
     *
     *  (`fromParsed()` accepts multiple key names for username & password)
     *
     * @param array $parsed Url values as you would obtain from from `parse_url()`
     *
     * @return array
     */
    private static function parsedPartsPrep(array $parsed): array
    {
        $map = array(
            'passwd' => 'pass',
            'password' => 'pass',
            'username' => 'user',
        );
        \ksort($parsed);
        $rename = \array_intersect_key($parsed, $map);
        $keysNew = \array_values(\array_intersect_key($map, $rename));
        $renamed = \array_combine($keysNew, \array_values($rename));
        $parsed = \array_merge(array(
            'pass' => '',
            'user' => '',
        ), $renamed, $parsed);
        if (\array_key_exists('userInfo', $parsed) === false) {
            $parsed['userInfo'] = [$parsed['user'], $parsed['pass']];
        }
        if (\is_array($parsed['userInfo']) === false) {
            $parsed['userInfo'] = \explode(':', (string) $parsed['userInfo'], 2);
        }
        if ((string) $parsed['userInfo'][0] === '') {
            unset($parsed['userInfo']);
        }
        return $parsed;
    }

    /**
     * Parse URL with latest `parse_url` fixes / behavior
     *
     * PHP < 8.0 : return empty query and fragment
     * PHP < 5.5 : handle urls that don't have schema
     *
     * @param string $url The URL to parse.
     *
     * @return array<string,int|string>|false
     */
    private static function parseUrlPatched(string $url)
    {
        $hasTempScheme = false;
        if (PHP_VERSION_ID < 50500 && \strpos($url, '//') === 0) {
            // php 5.4 chokes without the scheme
            $hasTempScheme = true;
            $url = 'http:' . $url;
        }
        $parts = \parse_url($url);
        if ($parts === false) {
            return false;
        }
        \ksort($parts);
        if ($hasTempScheme) {
            // only applicable for php 5.4
            unset($parts['scheme']);
        }
        return self::parseUrlAddEmpty($parts, $url);
    }

    /**
     * PHP < 8.0 does not return query & fragment if empty
     *
     * @param array<string,int|string> $parts Url components from `parse_url`
     * @param string                   $url   Unparsed url
     *
     * @return array<string,int|string>
     */
    private static function parseUrlAddEmpty(array $parts, string $url): array
    {
        $parts = \array_merge(array(
            'fragment' => \strpos($url, '#') !== false ? '' : null,
            'query' => \strpos($url, '?') !== false ? '' : null,
        ), $parts);
        \ksort($parts);
        return \array_filter($parts, static function ($val) {
            return $val !== null;
        });
    }

    /**
     * Removes dot segments from a path and returns the new path.
     *
     * @param string $path Path component
     *
     * @return string
     *
     * @link http://tools.ietf.org/html/rfc3986#section-5.2.4
     */
    private static function pathRemoveDots(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = \explode('/', $path);
        $segmentsNew = self::pathSegments($segments);
        $pathNew = \implode('/', $segmentsNew);

        if ($path[0] === '/' && (!isset($pathNew[0]) || $pathNew[0] !== '/')) {
            // Re-add the leading slash if necessary for cases like "/.."
            $pathNew = '/' . $pathNew;
        } elseif ($pathNew !== '' && \in_array(\end($segments), ['.', '..'], true)) {
            // Add the trailing slash if necessary
            $pathNew .= '/';
        }

        return $pathNew;
    }

    /**
     * Remove '..' & '.' from path segments
     *
     * @param string[] $segments Path segments
     *
     * @return string[]
     */
    private static function pathSegments(array $segments): array
    {
        $segmentsNew = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                \array_pop($segmentsNew);
            } elseif ($segment !== '.') {
                $segmentsNew[] = $segment;
            }
        }
        return $segmentsNew;
    }

    /**
     * Resolve target path
     *
     * @param UriInterface $base Base URI
     * @param UriInterface $rel  Relative URI
     *
     * @return string
     */
    private static function resolveTargetPath(UriInterface $base, UriInterface $rel): string
    {
        $relPath = $rel->getPath();
        $lastSlashPos = \strrpos($base->getPath(), '/');
        $targetPath = $lastSlashPos === false
            ? $relPath
            : \substr($base->getPath(), 0, $lastSlashPos + 1) . $relPath;
        if ($relPath[0] === '/') {
            $targetPath = $relPath;
        } elseif ($base->getAuthority() !== '' && $base->getPath() === '') {
            $targetPath = '/' . $relPath;
        }
        return $targetPath;
    }

    /**
     * `parse_url`, but for UriInterface
     *
     * @param UriInterface $url Uri instance
     *
     * @return array<string,int|string>
     */
    private static function uriInterfaceToParts(UriInterface $url): array
    {
        $userInfo = \array_replace([null, null], \explode(':', $url->getUserInfo(), 2));
        $parts = array(
            'pass' => $userInfo[1],
            'user' => $userInfo[0],
        );
        foreach (['fragment', 'host', 'path', 'port', 'query', 'scheme'] as $key) {
            $method = 'get' . \ucfirst($key);
            $parts[$key] = $url->{$method}();
        }
        \ksort($parts);
        return \array_filter($parts, static function ($val) {
            return \strlen((string) $val) > 0;
        });
    }

    /**
     * Get host and port from `$_SERVER` values
     *
     * @return array{host:string,port:int|null} host & port
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private static function hostPortFromGlobals(): array
    {
        $hostPort = array(
            'host' => '',
            'port' => null,
        );
        if (isset($_SERVER['HTTP_HOST'])) {
            $parts = \parse_url('http://' . $_SERVER['HTTP_HOST']); // may return false
            $parts = \array_merge($hostPort, (array) $parts);
            return \array_intersect_key($parts, $hostPort);
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $hostPort['host'] = $_SERVER['SERVER_NAME'];
        } elseif (isset($_SERVER['SERVER_ADDR'])) {
            $hostPort['host'] = $_SERVER['SERVER_ADDR'];
        }
        if (isset($_SERVER['SERVER_PORT'])) {
            $hostPort['port'] = (int) $_SERVER['SERVER_PORT'];
        }
        return $hostPort;
    }

    /**
     * Get request uri and query from `$_SERVER`
     *
     * @return array{path:string,query:string} path & query
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     *
     * @psalm-suppress InvalidReturnType
     */
    private static function pathQueryFromGlobals(): array
    {
        $path = '/';
        $query = null;
        if (isset($_SERVER['REQUEST_URI'])) {
            $exploded = \explode('?', $_SERVER['REQUEST_URI'], 2);
            // exploded is an array of length 1 or 2
            // use array_shift to avoid testing if exploded[1] exists
            $path = \array_shift($exploded);
            $query = \array_shift($exploded); // string|null
        } elseif (isset($_SERVER['QUERY_STRING'])) {
            $query = $_SERVER['QUERY_STRING'];
        }
        return array(
            'path' => $path,
            'query' => $query !== null
                ? $query
                : \http_build_query($_GET),
        );
    }
}
