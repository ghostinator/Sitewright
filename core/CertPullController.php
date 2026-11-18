<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * What a server fetches (deploy/sw-cert-pull). No session: every request carries "Authorization: Bearer swc_..." for ONE server, checked against
 * its stored hash and optional address list. Any failure answers 403 with the same words, so a wrong id, a wrong token and a blocked address
 * look alike. The private key is only sent to a server that proves it holds the token, over HTTPS, never cached.
 */
final class CertPullController
{
    private const NO_CACHE = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

    private function auth(array $params): array
    {
        $ip = Request::ip();
        RateLimiter::enforce('certpull:' . $ip, 240, 60, 'Too many requests');
        $t = Certificates::authTarget(Validator::id($params['id'] ?? null), Request::header('Authorization'), $ip);
        if ($t === null) {
            RateLimiter::enforce('certpull-denied:' . $ip, 30, 300, 'Too many refused requests');   // guessing tokens runs into this
            Response::error('Not allowed', 403);
        }
        return $t;
    }

    public function fingerprint(array $params): never
    {
        $t = $this->auth($params);
        $b = Certificates::bundle((int)$t['certificate_id']);
        Response::raw($b === null ? "none\n" : $b['fingerprint'] . "\n", 'text/plain; charset=utf-8', self::NO_CACHE);
    }

    public function fullchain(array $params): never
    {
        $t = $this->auth($params);
        $b = Certificates::bundle((int)$t['certificate_id']) ?? Response::notFound('No certificate yet');
        Response::raw($b['chain'], 'application/x-pem-file', self::NO_CACHE);
    }

    public function privkey(array $params): never
    {
        $t = $this->auth($params);
        $b = Certificates::bundle((int)$t['certificate_id']) ?? Response::notFound('No certificate yet');
        Response::raw($b['key'], 'application/x-pem-file', self::NO_CACHE);
    }

    public function installed(array $params): never
    {
        $t = $this->auth($params);
        $in = Request::json();
        $fp = is_string($in['fingerprint'] ?? null) ? strtolower($in['fingerprint']) : '';
        $status = ($in['status'] ?? '') === 'ok' ? 'ok' : 'failed';
        $before = (string)($t['installed_fingerprint'] ?? '') . '|' . (string)($t['install_status'] ?? '');
        Certificates::reportInstalled((int)$t['id'], $fp, $status, is_string($in['error'] ?? null) ? $in['error'] : '');
        if ($before !== $fp . '|' . $status) { Audit::log($status === 'ok' ? 'cert_installed' : 'cert_install_failed', null, '', (string)$t['name'] . ($status === 'ok' ? '' : ': ' . mb_substr((string)($in['error'] ?? ''), 0, 120))); }
        Response::success('Thanks');
    }
}
