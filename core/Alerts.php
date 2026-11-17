<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Operator alerts to e-mail (your mail server), a Microsoft Teams Workflows webhook and a Telegram bot.
 * notify() only enqueues one small job per configured channel (with a per-event cooldown), so an outage of a channel can never slow or
 * break a user's request, and a failing channel is retried on its own. Message text never contains secrets: callers pass event
 * descriptions only. Channel URLs/tokens are never logged (the Telegram token sits in its URL path).
 */
final class Alerts
{
    /** @return string[] names of configured channels */
    public static function channels(): array
    {
        $a = (array)Config::get('alerts');
        $c = [];
        if (!empty($a['email_to']) && Mailer::configured()) { $c[] = 'email'; }
        if ((string)$a['teams_webhook'] !== '') { $c[] = 'teams'; }
        if ((string)$a['telegram_token'] !== '' && (string)$a['telegram_chat_id'] !== '') { $c[] = 'telegram'; }
        return $c;
    }

    /** Never throws. $dedupe separates cooldowns of the same event (e.g. per username). */
    public static function notify(string $event, string $message, string $level = 'warning', string $dedupe = ''): void
    {
        try {
            $channels = self::channels();
            if ($channels === [] || !in_array($event, (array)Config::get('alerts.events'), true)) { return; }
            $r = RateLimiter::hit('alert:' . $event . ':' . $dedupe, 1, (int)Config::get('alerts.cooldown'));
            if (!$r['allowed']) { return; }
            foreach ($channels as $ch) {
                Jobs::enqueue('alert', ['channel' => $ch, 'event' => $event, 'message' => $message, 'level' => $level], null, 0, 4);
            }
        } catch (\Throwable $e) {
            Logger::error('alert_enqueue_failed', ['exception' => get_class($e)]);
        }
    }

    /** Count an occurrence; alert once when $threshold occurrences happen within $window seconds. Never throws. */
    public static function watch(string $key, int $threshold, int $window, string $event, string $message, string $level = 'warning'): void
    {
        try {
            $r = RateLimiter::hit('watch:' . $key, $threshold, $window);
            if ($r['remaining'] === 0 && $r['allowed']) { self::notify($event, $message, $level, $key); }
        } catch (\Throwable) {
        }
    }

    /** Send immediately on all channels (health check, `bin/alert-test.php`). @return array<string,string> channel => 'ok' | error */
    public static function sendNow(string $event, string $message, string $level = 'warning'): array
    {
        $out = [];
        foreach (self::channels() as $ch) {
            try { self::deliver($ch, $event, $message, $level); $out[$ch] = 'ok'; }
            catch (\Throwable $e) { $out[$ch] = $e instanceof JobRetryException || $e instanceof MailException ? $e->getMessage() : get_class($e); }
        }
        return $out;
    }

    /** Job handler for the 'alert' queue type. */
    public static function handleJob(array $p): array
    {
        self::deliver((string)($p['channel'] ?? ''), (string)($p['event'] ?? ''), (string)($p['message'] ?? ''), (string)($p['level'] ?? 'warning'));
        return ['message' => 'Alert sent', 'data' => ['channel' => $p['channel'] ?? '']];
    }

    private static function deliver(string $channel, string $event, string $message, string $level): void
    {
        $host = (string)(parse_url((string)Config::get('base_url'), PHP_URL_HOST) ?: 'sitewright');
        $title = strtoupper($level) . ' · ' . $event . ' · ' . $host;
        $message = mb_substr($message, 0, 1500);
        $a = (array)Config::get('alerts');
        try {
            match ($channel) {
                'email' => array_map(static fn (string $to) => Mailer::send($to, '[Sitewright] ' . $event . ' (' . $level . ')', "$title\n\n$message\n"), (array)$a['email_to']),
                'teams' => self::post((string)$a['teams_webhook'], self::teamsCard(
                    ucfirst(str_replace('_', ' ', $event)), $message, $level,
                    ['Event' => $event, 'Host' => $host, 'Time (UTC)' => gmdate('Y-m-d H:i:s')], (string)Config::get('base_url'))),
                'telegram' => self::post('https://api.telegram.org/bot' . self::telegramToken((string)$a['telegram_token']) . '/sendMessage',
                    ['chat_id' => (string)$a['telegram_chat_id'], 'text' => $title . "\n\n" . $message, 'disable_web_page_preview' => true]),
                default => throw new JobFailedException("Unknown alert channel", 500),
            };
        } catch (MailException $e) {
            throw new JobRetryException('email: ' . $e->getMessage(), 120);
        }
    }

    private static function telegramToken(string $t): string
    {
        if (!preg_match('/^[0-9A-Za-z:_\-]{10,100}$/D', $t)) { throw new JobFailedException('Telegram token has an unexpected format', 500); }
        return $t;   // the Bot API takes the token verbatim in the path (":" is legal there)
    }

    /**
     * Teams "Workflows" webhook: a message with one Adaptive Card attachment (the retired connector format is not used).
     * The header colour shows severity (the Level fact says it in words too); the button opens Sitewright, and only for an http(s) address.
     * @param array<string,string> $facts shown as a table under the message
     */
    public static function teamsCard(string $title, string $message, string $level = 'info', array $facts = [], string $url = ''): array
    {
        $style = ['error' => 'attention', 'warning' => 'warning'][$level] ?? 'emphasis';
        $label = in_array($level, ['error', 'warning', 'info'], true) ? ucfirst($level) : 'Info';
        $rows = [['title' => 'Level', 'value' => $label]];
        foreach ($facts as $k => $v) { $rows[] = ['title' => (string)$k, 'value' => (string)$v]; }
        $content = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4',
            'msteams' => ['width' => 'Full'],
            'body' => [
                ['type' => 'Container', 'style' => $style, 'bleed' => false, 'items' => [
                    ['type' => 'TextBlock', 'text' => $label . ': ' . $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true]]],
                ['type' => 'TextBlock', 'text' => $message, 'wrap' => true, 'spacing' => 'Medium'],
                ['type' => 'FactSet', 'facts' => $rows, 'spacing' => 'Medium'],
            ],
        ];
        if (preg_match('#^https?://#i', $url) === 1) {
            $content['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open Sitewright', 'url' => $url]];
        }
        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $content]]];
    }

    private static function post(string $url, array $json): void
    {
        try {
            $res = SafeHttpClient::default()->request('POST', $url, [
                'headers' => ['Content-Type: application/json'], 'body' => json_encode($json, JSON_UNESCAPED_UNICODE),
                'max_bytes' => 65536, 'timeout' => 15, 'accept' => 'application/json', 'follow' => false,
            ]);
        } catch (FetchException $e) {
            throw new JobRetryException('request failed: ' . $e->getMessage(), 180);   // FetchException text never contains the URL
        }
        if ($res['status'] >= 500 || $res['status'] === 429) { throw new JobRetryException('HTTP ' . $res['status'], 180); }
        if ($res['status'] >= 300) { throw new JobFailedException('Destination answered HTTP ' . $res['status'], 502); }
    }
}
