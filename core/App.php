<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Front controller: bootstrap, security middleware (host, origin, auth, content-type, CSRF, rate limit), dispatch. */
final class App
{
    private static string $requestId = '';

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(6));
    }

    /** Error handling, timezone, ini. Safe to call more than once. */
    public static function boot(): void
    {
        self::$requestId = bin2hex(random_bytes(6));
        error_reporting(E_ALL);
        $debug = (bool)Config::get('debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        libxml_use_internal_errors(true);

        set_exception_handler(static function (\Throwable $e): void {
            self::logThrowable($e);
            self::emitGeneric500();
        });
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log('Fatal error [' . self::requestId() . ']: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
                if (!headers_sent()) { self::emitGeneric500(); }
            }
        });
    }

    /** URL path prefix the app is mounted under ('' for the web root). Never derived from the Host header. */
    public static function basePath(): string
    {
        $base = (string)Config::get('base_url', '');
        if ($base !== '') {
            return rtrim((string)parse_url($base, PHP_URL_PATH), '/');
        }
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (in_array(basename($script), ['index.php', 'api.php'], true)) {
            $dir = str_replace('\\', '/', dirname($script));
            return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
        }
        return '';
    }

    /** Request path relative to the mount point, e.g. "/api/health" or "/seo-analysis". */
    public static function relativePath(?string $uri = null): string
    {
        $path = (string)parse_url($uri ?? (string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode($path);
        $base = self::basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        foreach (['/api.php', '/index.php'] as $script) {
            if ($path === $script || str_starts_with($path, $script . '/')) {
                $path = substr($path, strlen($script));
            }
        }
        return '/' . ltrim($path, '/');
    }

    /** @return array<int,array> */
    public static function routes(): array
    {
        return require Config::root() . '/config/routes.php';
    }

    /** Headers for JSON/API responses. */
    public static function apiHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ] + self::hstsHeader();
    }

    /** Headers for the HTML shell. */
    public static function pageHeaders(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; "
                . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cache-Control' => 'no-store',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ] + self::hstsHeader();
    }

    private static function hstsHeader(): array
    {
        $https = str_starts_with((string)Config::get('base_url', ''), 'https://') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return Config::get('hsts') && $https ? ['Strict-Transport-Security' => 'max-age=31536000'] : [];
    }

    /** Entry point of public/api.php. */
    public static function run(): void
    {
        self::boot();
        $t0 = microtime(true);
        $method = Request::method();
        try {
            self::handle($method === 'HEAD' ? 'GET' : $method, self::relativePath());
        } catch (ResponseException $r) {
            if (!headers_sent()) {
                foreach (self::apiHeaders() as $k => $v) { header("$k: $v"); }
            }
            Response::emit($r);
            self::logRequest($method, $r->status, $t0);
            if ($r->status >= 500) { Alerts::notify('server_error', "HTTP {$r->status} on {$method} " . self::relativePath() . ' (request ' . self::requestId() . ')', 'error', (string)$r->status); }
        } catch (\Throwable $e) {
            self::logThrowable($e);
            self::emitGeneric500();
            self::logRequest($method, 500, $t0);
            Alerts::notify('server_error', 'Unhandled ' . get_class($e) . ' on ' . $method . ' ' . self::relativePath() . ' (request ' . self::requestId() . ')', 'error', 'unhandled');
        }
    }

    /** All middleware + dispatch. Always ends by throwing a ResponseException. */
    public static function handle(string $method, string $path): never
    {
        if (!Config::hostAllowed((string)Request::header('Host'))) {
            Response::error('Invalid host', 400);
        }
        $match = (new Router(self::routes()))->match($method, $path);
        $route = $match['route'];
        $opts = $route['opts'];
        $safe = in_array($method, ['GET', 'HEAD'], true);

        if (!$safe) {
            self::checkOrigin();
        }

        $public = !empty($opts['public']);
        if (!$public) {
            $user = Auth::user();
            if ($user === null) {
                Response::unauthorized();
            }
            // Least privilege by default: reading needs "viewer", changing anything needs "editor"; routes can demand more ("role" option).
            if (!Auth::can((string)($opts['role'] ?? ($safe ? 'viewer' : 'editor')))) {
                Audit::log('permission_denied', null, '', "$method $path");
                Response::error('You do not have permission to do that', 403);
            }
            // "Require two-step verification" is on and this administrator has none: only the way to fix that (and to sign out) is open.
            if (Auth::needsMfaEnrolment() && !in_array($route['handler'], [
                'Sitewright\\Core\\AuthController@logout', 'Sitewright\\Core\\AuthController@me', 'Sitewright\\Core\\AuthController@changePassword',
                'Sitewright\\Core\\AuthController@mfaSetup', 'Sitewright\\Core\\AuthController@mfaEnable', 'Sitewright\\Core\\AuthController@mfaDisable',
            ], true)) {
                Response::error('Two-step verification is required for administrators. Set it up under My Account.', 403, ['code' => 'mfa_required']);
            }
        } elseif (!empty($opts['session'])) {
            Auth::startSession();
        }

        if (!$safe) {
            $ct = strtolower((string)Request::header('Content-Type'));
            $want = !empty($opts['upload']) ? 'multipart/form-data' : 'application/json';
            if (!str_starts_with($ct, $want)) {
                Response::error("Content-Type must be $want", 415);
            }
            if (!Auth::csrfValid(Request::header('X-CSRF-Token'))) {
                Response::error('Missing or invalid CSRF token', 403);
            }
        }

        if (!empty($opts['limit'])) {
            RateLimiter::enforce(
                'expensive:' . $opts['limit'] . ':' . (Auth::user()['id'] ?? 0),
                (int)Config::get('limits.expensive_per_min'), 60, 'Rate limit reached for this operation'
            );
        }

        [$class, $fn] = explode('@', $route['handler']);
        $controller = new $class();
        $controller->$fn($match['params']);
        throw new \LogicException('Handler produced no response');
    }

    /** Same-origin enforcement for state-changing requests (CSRF-001). */
    private static function checkOrigin(): void
    {
        $site = Request::header('Sec-Fetch-Site');
        if ($site !== null && !in_array(strtolower($site), ['same-origin', 'none'], true)) {
            Response::error('Cross-site requests are not allowed', 403);
        }
        $origin = Request::header('Origin');
        if ($origin === null) {
            return;
        }
        $p = parse_url($origin);
        $oHost = isset($p['host']) ? strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') : '';
        if ($origin === 'null' || $oHost === '' || $oHost !== strtolower(trim((string)Request::header('Host')))) {
            Response::error('Cross-origin requests are not allowed', 403);
        }
    }

    /** One structured line per request: method, path WITHOUT query string, status, duration, user id, client IP. */
    private static function logRequest(string $method, int $status, float $t0): void
    {
        if (!Config::get('log.requests')) { return; }
        Logger::info('request', [
            'method' => $method, 'path' => self::relativePath(), 'status' => $status, 'ms' => (int)((microtime(true) - $t0) * 1000),
            'uid' => $_SESSION['uid'] ?? null, 'ip' => Request::ip(),
        ]);
    }

    private static function logThrowable(\Throwable $e): void
    {
        error_log(sprintf('Unhandled %s [%s]: %s in %s:%d', get_class($e), self::requestId(), $e->getMessage(), $e->getFile(), $e->getLine()));
        Logger::error('unhandled_exception', ['exception' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    }

    private static function emitGeneric500(): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            foreach (self::apiHeaders() as $k => $v) { header("$k: $v"); }
        }
        echo json_encode(['success' => false, 'message' => 'Internal error', 'request_id' => self::requestId()]);
    }
}
