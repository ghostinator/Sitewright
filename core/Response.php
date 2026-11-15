<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Response helpers. Every helper THROWS a ResponseException; App::run() emits it.
 * This keeps controllers testable (no exit()) and guarantees one output path.
 */
final class Response
{
    private const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        throw new ResponseException(
            $status,
            (string)json_encode($data, self::JSON_FLAGS),
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }

    public static function success(string $message, mixed $data = [], int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, array $errors = [], array $headers = []): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors], $status, $headers);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::error($message, 401);
    }

    public static function serverError(string $message = 'Internal error'): never
    {
        self::error($message, 500);
    }

    /** Non-JSON body (XML, CSV, plain text). */
    public static function raw(string $body, string $contentType, array $headers = [], int $status = 200): never
    {
        throw new ResponseException($status, $body, ['Content-Type' => $contentType] + $headers);
    }

    public static function emit(ResponseException $r): void
    {
        if (!headers_sent()) {
            http_response_code($r->status);
            foreach ($r->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $r->body;
    }
}
