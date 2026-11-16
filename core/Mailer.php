<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Minimal SMTP client (AUTH PLAIN/LOGIN, STARTTLS or implicit TLS, plain-text UTF-8 mail) for your own mail server, e.g. Postal.
 * Deliberately NOT routed through SafeHttpClient: the server is operator configuration (mail.host), not user input, and is usually on
 * a private network. TLS certificates are verified unless mail.verify_tls is switched off. Header values are checked for CR/LF, and the
 * body is dot-stuffed, so message content can never inject SMTP commands or headers.
 */
final class Mailer
{
    public static function configured(): bool
    {
        $m = (array)Config::get('mail');
        if ($m['from'] === '') { return false; }
        return $m['driver'] === 'postal' ? ($m['postal_url'] !== '' && $m['postal_key'] !== '') : $m['host'] !== '';
    }

    /** Job handler for the 'mail' queue type. */
    public static function handleJob(array $p): array
    {
        try {
            self::send((string)($p['to'] ?? ''), (string)($p['subject'] ?? ''), (string)($p['text'] ?? ''));
        } catch (MailException $e) {
            throw new JobRetryException('mail: ' . $e->getMessage(), 120);
        }
        return ['message' => 'Mail sent', 'data' => null];
    }

    /** Queue a message (never blocks the request). */
    public static function queue(string $to, string $subject, string $text, ?int $userId = null): void
    {
        if (!self::configured()) {
            Logger::warn('mail_not_configured', ['subject' => $subject]);
            return;
        }
        Jobs::enqueue('mail', ['to' => $to, 'subject' => $subject, 'text' => $text], null, 0, 4);
    }

    /** Send a visible test message right now (not queued). @throws MailException */
    public static function sendTest(string $to): void
    {
        self::send($to, 'Sitewright test message', "This is a test message from Sitewright, sent " . gmdate('c') . " UTC.\nIf you can read it, mail is working.\n");
    }

    /** @throws MailException */
    public static function send(string $to, string $subject, string $text): void
    {
        $cfg = (array)Config::get('mail');
        foreach ([$to, $subject, (string)$cfg['from'], (string)$cfg['from_name']] as $v) {
            if (preg_match('/[\r\n\0]/', $v)) { throw new MailException('Invalid characters in a mail header'); }
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) { throw new MailException('Invalid sender or recipient address'); }
        if (!self::configured()) { throw new MailException('Mail is not configured'); }
        if ($cfg['driver'] === 'postal') { self::sendViaPostal($cfg, $to, $subject, $text); return; }

        $domain = substr((string)strrchr((string)$cfg['from'], '@'), 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($cfg['from_name'] !== '' ? self::encodeHeader((string)$cfg['from_name']) . ' <' . $cfg['from'] . '>' : $cfg['from']),
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'Auto-Submitted: auto-generated',
        ];
        // Line breaks must be CRLF *before* encoding: PHP encodes a bare LF as "=0A" instead of ending the line.
        $body = quoted_printable_encode(str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $text)));
        $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', $body);   // dot-stuffing

        $conn = new SmtpConnection($cfg);
        try {
            $conn->open();
            $conn->command('MAIL FROM:<' . $cfg['from'] . '>', [250]);
            $conn->command('RCPT TO:<' . $to . '>', [250, 251]);
            $conn->command('DATA', [354]);
            $conn->command($data . "\r\n.", [250]);
            $conn->command('QUIT', [221], true);
        } finally {
            $conn->close();
        }
    }

    /**
     * Postal HTTP API: POST {postal_url}/api/v1/send/message with the server API key in X-Server-API-Key. Goes through SafeHttpClient
     * (https only for public hosts, SSRF-guarded like every other outbound call); the key is never logged or put in an error message.
     * The From address must belong to a domain that is verified on that Postal server.
     */
    private static function sendViaPostal(array $cfg, string $to, string $subject, string $text): void
    {
        $url = (string)$cfg['postal_url'];
        if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#D', $url)) { throw new MailException('mail.postal_url must be an https:// address'); }
        if (preg_match('/[\s\x00-\x1F]/', (string)$cfg['postal_key'])) { throw new MailException('Invalid Postal API key'); }
        $from = $cfg['from_name'] !== '' ? $cfg['from_name'] . ' <' . $cfg['from'] . '>' : $cfg['from'];
        try {
            $res = SafeHttpClient::default()->request('POST', $url . '/api/v1/send/message', [
                'headers' => ['Content-Type: application/json', 'X-Server-API-Key: ' . $cfg['postal_key']],
                'body' => (string)json_encode(['to' => [$to], 'from' => $from, 'subject' => $subject, 'plain_body' => $text, 'tag' => 'sitewright'], JSON_UNESCAPED_UNICODE),
                'max_bytes' => 65536, 'timeout' => 20, 'accept' => 'application/json', 'follow' => false,
            ]);
        } catch (FetchException $e) {
            throw new MailException('Could not reach the Postal server: ' . $e->getMessage());
        }
        $j = json_decode((string)$res['body'], true);
        if ($res['status'] === 200 && is_array($j) && ($j['status'] ?? '') === 'success') { return; }
        $code = is_array($j) && is_string($j['data']['code'] ?? null) ? preg_replace('/[^A-Za-z0-9_]/', '', $j['data']['code']) : '';
        $why = match (true) {
            $res['status'] === 401 || $res['status'] === 403 || $code === 'InvalidServerAPIKey' => 'the API key was rejected',
            $code === 'UnauthenticatedFromAddress' => 'the From address is not on a domain verified in Postal',
            default => 'HTTP ' . $res['status'] . ($code !== '' ? " ($code)" : ''),
        };
        throw new MailException('Postal refused the message: ' . $why);
    }

    private static function encodeHeader(string $v): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $v) ? $v : '=?UTF-8?B?' . base64_encode($v) . '?=';
    }
}

/** One SMTP session. Replies never include credentials; error text is the server's own last reply line, trimmed. */
final class SmtpConnection
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(private array $cfg) {}

    public function open(): void
    {
        $implicit = $this->cfg['encryption'] === 'tls';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => (bool)$this->cfg['verify_tls'], 'verify_peer_name' => (bool)$this->cfg['verify_tls'], 'SNI_enabled' => true, 'peer_name' => (string)$this->cfg['host']]]);
        $this->fp = @stream_socket_client(($implicit ? 'tls://' : 'tcp://') . $this->cfg['host'] . ':' . (int)$this->cfg['port'], $en, $es, (float)$this->cfg['timeout'], STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) { throw new MailException('Cannot connect to the mail server'); }
        stream_set_timeout($this->fp, (int)$this->cfg['timeout']);
        $this->expect([220]);
        $host = (string)(gethostname() ?: 'localhost');
        $caps = $this->command('EHLO ' . preg_replace('/[^A-Za-z0-9.\-]/', '', $host), [250]);
        if ($this->cfg['encryption'] === 'starttls') {
            if (!str_contains($caps, 'STARTTLS')) { throw new MailException('Server does not offer STARTTLS'); }
            $this->command('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) { throw new MailException('TLS negotiation failed (certificate not trusted?)'); }
            $caps = $this->command('EHLO ' . preg_replace('/[^A-Za-z0-9.\-]/', '', $host), [250]);
        }
        if ((string)$this->cfg['username'] !== '') {
            if ($this->cfg['encryption'] === 'none') { /* credentials over plaintext are the operator's explicit choice */ }
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
                $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->cfg['username'] . "\0" . $this->cfg['password']), [235], false, true);
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode((string)$this->cfg['username']), [334], false, true);
                $this->command(base64_encode((string)$this->cfg['password']), [235], false, true);
            }
        }
    }

    /** @return string the full reply text */
    public function command(string $line, array $ok, bool $lenient = false, bool $secret = false): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($ok, $lenient, $secret);
    }

    private function expect(array $ok, bool $lenient = false, bool $secret = false): string
    {
        $text = ''; $code = 0;
        while (($l = fgets($this->fp, 2048)) !== false) {
            $text .= $l; $code = (int)substr($l, 0, 3);
            if (strlen($l) < 4 || $l[3] !== '-') { break; }
        }
        if ($l === false && $text === '') { throw new MailException('Mail server closed the connection'); }
        if (!in_array($code, $ok, true) && !$lenient) {
            throw new MailException($secret ? 'Authentication was rejected' : 'Unexpected reply: ' . mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 120));
        }
        return $text;
    }

    public function close(): void
    {
        if (is_resource($this->fp)) { @fclose($this->fp); }
        $this->fp = null;
    }
}
