# HttpMessage
PSR-7 (HttpMessage) & PSR-17 (HttpFactory) Implementations

## Notable features

* Ability to register per-media-type custom body parsers
* By default the following parsers are registered
   - application/x-www-form-urlencoded - Preserves "." and space in keys
   - application/json - decoded to array
   - application/xml, text/xml parsed to SimpleXMLElement obj
* parsedBody and queryParams preserves "." and spaces in keys
* `UploadedFile::getClientFullPath()`.  PHP 8.1 added a new file upload property (not included in PSR-7)
* `ServerRequestExtended` interface and implementation - Extends standard server request with helpful methods

### Utilities
* ContentType: common mime-type constants
* HttpFoundationBridge: create ServerRequest and Response from HttpFoundation request and response
* ParseStr: PHP's `parse_str()`, but does not convert dots and spaces to '_' by default
* Response: 
  * `emit(ResponseInterface $response)` - Output response headers and body 
  * `codePhrase(int|string $code): string` - Get standard code phrase for given HTTP status code
* ServerRequest:
  * `fromGlobals(): ServerRequestInterface`
* Stream
  * `getContent(StreamInterface): string` - Get stream contents without affecting pointer
* Uri: 
  * `fromGlobals(): UriInterface`
  * `fromParsed(array): UriInterface`
  * `isCrossOrigin(UriInterface $uri1, UriInterface $uri2): bool`
  * `parseUrl(string|UriInterface): array` - like php's `parse_url` but with bug fixes backported 
  * `resolve(UriInterface $base, UriInterface $rel): UriInterface` - Converts the relative URI into a new URI that is resolved against the base URI.

### Installation 

`composer require bdk/http-message`

### Documentation

http://bradkent.com/php/httpmessage

### 3 maintained versions:

| Version | http-message | http-factory | php | note |
|--|--|--|--|--|
|3.x | ^1.1 \| ^2.0 | ^1.0 | >= 8.0 | `static` returns
|2.x | ^1.1 \| ^2.0 | ^1.0 | >= 7.2 | `self` returns
|1.x | ~1.0.1 | -- | >= 5.4 | &nbsp; |

## Tests / Quality

![Supported PHP versions](https://img.shields.io/static/v1?label=PHP&message=5.4%20-%208.4&color=blue)
![Build Status](https://img.shields.io/github/actions/workflow/status/bkdotcom/HttpMessage/phpunit.yml.svg?logo=github)
[![Maintainability](https://img.shields.io/codeclimate/maintainability/bkdotcom/HttpMessage.svg?logo=codeclimate)](https://codeclimate.com/github/bkdotcom/HttpMessage)
[![Coverage](https://img.shields.io/codeclimate/coverage/bkdotcom/HttpMessage.svg?logo=codeclimate)](https://codeclimate.com/github/bkdotcom/HttpMessage)
