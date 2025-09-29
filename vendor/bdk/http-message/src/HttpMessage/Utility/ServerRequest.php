<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2024-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage\Utility;

use bdk\HttpMessage\ServerRequestExtended;
use bdk\HttpMessage\Stream;
use bdk\HttpMessage\UploadedFile;
use bdk\HttpMessage\Utility\ContentType;
use bdk\HttpMessage\Utility\ParseStr;
use bdk\HttpMessage\Utility\Uri as UriUtility;
use InvalidArgumentException;

/**
 * Build ServerRequest from globals (`$_SERVER`, `$_COOKIE`, `$_POST`, `$_FILES`)
 */
class ServerRequest
{
    /** @var non-empty-string used for unit tests */
    public static $inputStream = 'php://input';

    /**
     * Instantiate ServerRequest instance from superglobals
     *
     * @param array $parseStrOpts Parse options (default: {convDot:false, convSpace:false})
     *
     * @return ServerRequestExtended
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function fromGlobals($parseStrOpts = array()): ServerRequestExtended
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : 'GET';
        $uri = UriUtility::fromGlobals();
        $files = self::filesFromGlobals($_FILES);

        $serverRequest = new ServerRequestExtended($method, $uri, $_SERVER);
        $serverRequest->registerMediaTypeParser(ContentType::FORM, static function ($input) use ($parseStrOpts) {
            return ParseStr::parse($input, $parseStrOpts);
        });

        $contentType = $serverRequest->getHeaderLine('Content-Type');
        $parsedBody = self::parsedBodyFromGlobals($contentType, $method);
        $query = $uri->getQuery();
        $queryParams = ParseStr::parse($query, $parseStrOpts);

        return $serverRequest
            ->withBody(new Stream(
                PHP_VERSION_ID < 70000
                    ? \stream_get_contents(\fopen(self::$inputStream, 'r+')) // prev 5.6 is not seekable / read once.. still not reliable in 5.6
                    : \fopen(self::$inputStream, 'r+')
            ))
            ->withCookieParams($_COOKIE)
            ->withParsedBody($parsedBody)
            ->withQueryParams($queryParams)
            ->withUploadedFiles($files);
    }

    /**
     * Create UploadedFiles tree from $_FILES
     *
     * @param array    $phpFiles $_FILES type array
     * @param string[] $path     {@internal} Path to current value
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    private static function filesFromGlobals(array $phpFiles, array $path = []): array
    {
        $files = array();
        /** @var mixed $value */
        foreach ($phpFiles as $key => $value) {
            $pathCurKey = $path;
            $pathCurKey[] = (string) $key;
            if (\is_array($value) === false) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid value in files specification at %s.  Array expected.  %s provided.',
                    \implode('.', $pathCurKey),
                    \bdk\HttpMessage\Utility\ParseStr::getDebugType($value)
                ));
            }
            if (self::isUploadFileInfoArray($value)) {
                $files[$key] = self::fileFromGlobalCreate($value);
                continue;
            }
            $files[$key] = self::filesFromGlobals($value, $pathCurKey);
        }
        return $files;
    }

    /**
     * Create UploadedFile(s) from $_FILES entry
     *
     * @param array{
     *   name: array|string,
     *   type: array|string,
     *   tmp_name: array|string,
     *   size: array|int,
     *   error: array|int,
     *   full_path: array|string} $fileInfo $_FILES entry
     *
     * @return UploadedFile|array
     *
     * @psalm-suppress PossiblyInvalidArrayAccess
     * @psalm-suppress PossiblyInvalidArrayOffset
     * @psalm-suppress MixedArgumentTypeCoercion doesn't trust array being passed to fileFromGlobalCreate
     */
    private static function fileFromGlobalCreate(array $fileInfo)
    {
        if (\is_array($fileInfo['tmp_name']) === false) {
            return new UploadedFile($fileInfo);
        }
        /*
        <input type="file" name="foo[bar][a]">
        <input type="file" name="bar[baz][a]">
        will create something like
            'foo' => [
                'name' => [
                    'bar' => [
                        'a' => 'test2.jpg',
                        'b' => 'test3.jpg',
                    ],
                ],
                'type' => [
                    'bar' => []
                        'a' => 'image/jpeg',
                        'b' => 'image/jpeg',
                    ],
                ],
                ...
            ]
        */
        $files = array();
        $keys = \array_keys($fileInfo['tmp_name']);
        foreach ($keys as $key) {
            $files[$key] = self::fileFromGlobalCreate(array(
                'error'    => $fileInfo['error'][$key],
                'full_path' => isset($fileInfo['full_path'][$key])
                    ? $fileInfo['full_path'][$key]
                    : null,
                'name'     => $fileInfo['name'][$key],
                'size'     => $fileInfo['size'][$key],
                'tmp_name' => $fileInfo['tmp_name'][$key],
                'type'     => $fileInfo['type'][$key],
            ));
        }
        return $files;
    }

    /**
     * Are we uploaded file info array?  ('tmp_name', 'size', 'error', name', 'type'...
     *
     * Don't base this off a single key like 'tmp_name'.
     *   <input type="file" name="tmp_name" "some dingus named this tmp_name" />
     *
     * @param array $array branch of $_FILES structure
     *
     * @return bool
     *
     * @psalm-assert-if-true array{
     *   name: array|string,
     *   type: array|string,
     *   tmp_name: array|string,
     *   size: array|int,
     *   error: array|int,
     *   full_path: array|string} $array
     */
    private static function isUploadFileInfoArray(array $array): bool
    {
        $keysMustHave = ['name', 'type', 'tmp_name', 'size', 'error'];
        $keysMayHave = ['full_path'];
        $keys = \array_keys($array);
        if (\array_intersect($keysMustHave, $keys) !== $keysMustHave) {
            // missing must have
            return false;
        }
        // return true if no unknown keys
        return \array_diff($keys, \array_merge($keysMustHave, $keysMayHave)) === [];
    }

    /**
     * Get parsed body (POST data)
     *
     * Only return if content-type is "multipart/form-data"...
     * We can use custom parser for other content-types (incl application/x-www-form-urlencoded)
     *
     * @param string $contentType Content-Type header value
     * @param string $method      Request method
     *
     * @return array|null
     */
    private static function parsedBodyFromGlobals(string $contentType, string $method): ?array
    {
        if ($method === 'GET') {
            return null;
        }
        $contentType = \preg_replace('/\s*[;,].*$/', '', $contentType);
        $contentType = \strtolower($contentType);
        return $contentType === ContentType::FORM_MULTIPART
            ? $_POST
            : null;
    }
}
