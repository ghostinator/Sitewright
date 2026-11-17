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

/** A background job ended unsuccessfully. getMessage() is safe to show to the operator who started it. */
final class JobFailedException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}

/** Throw from a job handler to try again later (counts as an attempt). */
final class JobRetryException extends \RuntimeException
{
    public function __construct(string $reason, public readonly int $delaySeconds = 60)
    {
        parent::__construct($reason);
    }
}

/** SMTP delivery problem; the message is safe to show to the operator (no credentials). */
final class MailException extends \RuntimeException {}

/** Google OAuth / Search Console problem; the message is safe to show to the administrator (never contains tokens). */
final class GscException extends \RuntimeException {}

/** A page analysis could not be completed; the message is safe to show to the operator. $httpStatus is the status an HTTP caller should answer with. */
final class AnalysisException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

/** An audit of this site is already queued or running. */
final class AuditBusyException extends \RuntimeException {}
