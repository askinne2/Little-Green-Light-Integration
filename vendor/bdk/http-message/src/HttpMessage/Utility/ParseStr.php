<?php

/**
 * @package   bdk/http-message
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2024-2025 Brad Kent
 * @since     1.0
 */

namespace bdk\HttpMessage\Utility;

use InvalidArgumentException;

/**
 * PHP's `parse_str()`, but does not convert dots and spaces to '_' by default
 *
 * PHP's maintains a side-effect of the long-removed register_globals directive that affects `$_POST` and `$_GET`
 * Spaces and '.'s are converted to '_' for top level keys.
 *
 *     $input = 'foo_bar=baz+1&foo+bar=baz+2&foo%2Bbar=baz+3&foo.bar=baz+4';
 *     parse_str($input, $vals);
 *     var_dump($vals);
 *
 *     $vals = \bdk\HttpMessage\Utility\ParseStr::parse($input);
 *     var_dump($vals);
 *
 * **Output:**
 *
 *     array(
 *        'foo_bar' => 'baz 4',
 *        'foo+bar' => 'baz 3',
 *     )
 *     array(
 *        'foo_bar' => 'baz 1',
 *        'foo bar' => 'baz 2',
 *        'foo+bar' => 'baz 3',
 *        'foo.bar' => 'baz 4',
 *     )
 *
 * @psalm-api
 */
class ParseStr
{
    /** @var array  */
    private static $parseStrOpts = array(
        'convDot' => false,     // whether to convert '.' to '_'  (php's default is true)
        'convSpace' => false,   // whether to convert ' ' to '_'  (php's default is true)
    );

    /**
     * Gets the type name of a variable in a way that is suitable for debugging
     *
     * @param mixed $value Value to inspect
     *
     * @return string
     */
    public static function getDebugType($value): string
    {
        if (\is_object($value)) {
            return \get_class($value);
        }
        return \strtr(\strtolower(\gettype($value)), array(
            'boolean' => 'bool',
            'double' => 'float',
            'integer' => 'int',
        ));
    }

    /**
     * like PHP's `parse_str()`
     *
     * Key difference: by default this does not convert root key dots and spaces to '_'
     *
     * @param string|null $str  input string
     * @param array       $opts parse options (default: {convDot:false, convSpace:false})
     *
     * @return array
     *
     * @see https://github.com/api-platform/core/blob/main/src/Core/Util/RequestParser.php#L50
     */
    public static function parse($str, array $opts = array()): array
    {
        $str = (string) $str;
        $opts = \array_merge(self::$parseStrOpts, $opts);
        $useParseStr = ($opts['convDot'] || \preg_match('/(.|%2E)/', $str) !== 1)
            && ($opts['convSpace'] || \preg_match('/( |\+|%20)/', $str) !== 1);
        if ($useParseStr) {
            // there are no spaces or dots in serialized data
            //   and/or we're not interested in converting them
            // just use parse_str
            $params = array();
            \parse_str($str, $params);
            return $params;
        }
        return self::parseStrCustom($str, $opts);
    }

    /**
     * Set default parseStr option(s)
     *
     *     parseStrOpts('convDot', true)
     *     parseStrOpts(array('convDot' => true, 'convSpace' => true))
     *
     * @param array|string $mixed key=>value array or key
     * @param mixed        $val   new value
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public static function setOpts($mixed, $val = null): void
    {
        if (\is_string($mixed)) {
            $mixed = array($mixed => $val);
        }
        if (\is_array($mixed) === false) {
            throw new InvalidArgumentException(\sprintf(
                'parseStrOpts expects string or array. %s provided.',
                self::getDebugType($mixed)
            ));
        }
        $mixed = \array_intersect_key($mixed, self::$parseStrOpts);
        self::$parseStrOpts = \array_merge(self::$parseStrOpts, $mixed);
    }

    /**
     * Parses request parameters from the specified string
     *
     * @param string $str  input string
     * @param array  $opts parse options
     *
     * @return array
     */
    private static function parseStrCustom(string $str, array $opts): array
    {
        // Use a regex to replace keys with a bin2hex'd version
        // this will prevent parse_str from modifying the keys
        // '[' is urlencoded ('%5B') in the input, but we must urldecode it in order
        // to find it when replacing names with the regexp below.
        $str = \str_replace('%5B', '[', $str);
        $str = \preg_replace_callback(
            '/(^|(?<=&))[^=[&]+/',
            static function ($matches) {
                return \bin2hex(\urldecode($matches[0]));
            },
            $str
        );

        // parse_str url-decodes both keys and values in resulting array
        \parse_str($str, $params);

        $replace = array();
        if ($opts['convDot']) {
            $replace['.'] = '_';
        }
        if ($opts['convSpace']) {
            $replace[' '] = '_';
        }
        $keys = \array_map(static function ($key) use ($replace) {
            return \strtr(\hex2bin((string) $key), $replace);
        }, \array_keys($params));
        return \array_combine($keys, $params);
    }
}
