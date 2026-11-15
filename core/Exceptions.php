<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Carries a finished HTTP response out of controller code. Not an error. */
final class ResponseException extends \Exception
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
        parent::__construct('response', $status);
    }
}

/** Misconfiguration. Message is for the server log only. */
final class ConfigException extends \RuntimeException {}

/** Database failure. Message is for the server log only; never shown to clients. */
final class DatabaseException extends \RuntimeException {}

/**
 * Outbound fetch failure. getMessage() is safe to show to the (authenticated) operator:
 * it never contains resolved IPs, response bodies or internals.
 */
class FetchException extends \RuntimeException
{
    public string $reason = 'transport';
    public int $httpStatus = 502;
}

final class UrlRejectedException extends FetchException
{
    public string $reason = 'invalid_url';
    public int $httpStatus = 400;
}

final class BlockedAddressException extends FetchException
{
    public string $reason = 'blocked_address';
    public int $httpStatus = 400;
}

final class FetchTimeoutException extends FetchException
{
    public string $reason = 'timeout';
    public int $httpStatus = 504;
}

final class ResponseTooLargeException extends FetchException
{
    public string $reason = 'too_large';
    public int $httpStatus = 502;
}

final class TooManyRedirectsException extends FetchException
{
    public string $reason = 'too_many_redirects';
    public int $httpStatus = 502;
}
