<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Settings page API (admin role; enforced by the route table). Secrets are write-only: no endpoint ever returns one. */
final class SettingsController
{
    public function list(array $params): never
    {
        $db = Database::getInstance();
        $meta = [];
        foreach ($db->fetchAll('SELECT s.skey, s.updated_at, u.username FROM `' . $db->t('settings') . '` s LEFT JOIN `' . $db->t('users') . '` u ON u.id = s.updated_by') as $r) { $meta[$r['skey']] = $r; }
        $groups = [];
        foreach (Settings::GROUPS as $id => [$label, $description]) { $groups[$id] = ['id' => $id, 'label' => $label, 'description' => $description, 'changed_count' => 0, 'settings' => []]; }
        foreach (Settings::registry() as $key => $d) {
            if (!isset($groups[$d['group']])) { continue; }
            $e = Settings::effective($key);
            $groups[$d['group']]['settings'][] = [
                'key' => $key, 'label' => $d['label'], 'help' => $d['help'], 'unit' => $d['unit'], 'type' => $d['type'], 'enum' => $d['enum'], 'min' => $d['min'], 'max' => $d['max'],
                'value' => $e['value'], 'default' => $d['secret'] ? null : $d['default'], 'source' => $e['source'], 'locked' => $e['locked'], 'env' => $d['env'], 'fallback' => $e['fallback'],
                'secret' => $d['secret'], 'danger' => $d['danger'], 'danger_effects' => $d['danger_effects'], 'apply' => $d['apply'],
                'changed_by' => $meta[$key]['username'] ?? null, 'changed_at' => $meta[$key]['updated_at'] ?? null,
            ];
            if ($e['source'] === 'saved') { $groups[$d['group']]['changed_count']++; }
        }
        $hidden = ['db.pass', 'app_key'];
        $readonly = array_map(static fn (string $k): array => [
            'key' => $k, 'value' => in_array($k, $hidden, true) ? '(hidden)' : Config::get($k), 'source' => Config::fileHas($k) ? 'file' : 'default',
        ], Settings::readonlyKeys());
        Response::success('Settings', ['groups' => array_values($groups), 'readonly' => $readonly, 'encryption_ready' => Crypto::configured()]);
    }

    public function save(array $params): never
    {
        $in = Request::json();
        $changes = $in['changes'] ?? null;
        if (!is_array($changes) || $changes === [] || array_is_list($changes)) { Response::error('Send {"changes": {key: value}}', 400); }
        if (($changes['security.require_mfa'] ?? false) === true && !(Auth::user()['mfa'] ?? false)) {
            Response::error('Turn on two-step verification for your own account first (My Account), then require it for everyone.', 409);
        }
        $p = Settings::prepare($changes, ($in['confirm'] ?? false) === true);
        if ($p['status'] !== 200) { Response::error($p['message'], $p['status'], $p['errors']); }
        $r = $p['values'] === [] ? ['changed' => [], 'cleared' => []] : Settings::commit($p, (int)Auth::user()['id']);
        Response::success($r['changed'] ? 'Changes saved' : 'Nothing changed', $r);
    }

    public function reset(array $params): never
    {
        $key = (string)($params['key'] ?? '');
        if (!Settings::reset($key, (int)Auth::user()['id'])) { Response::notFound('Unknown setting'); }
        Response::success('Reset', ['fallback' => Settings::effective($key)['fallback'], 'source' => Settings::effective($key)['source']]);
    }

    public function promote(array $params): never
    {
        $key = (string)($params['key'] ?? '');
        if (Settings::def($key) === null) { Response::notFound('Unknown setting'); }
        try { Settings::promote($key, (int)Auth::user()['id']); } catch (\RuntimeException $e) { Response::error($e->getMessage(), 409); }
        Response::success('Moved to encrypted storage');
    }

    public function test(array $params): never
    {
        $name = (string)($params['name'] ?? '');
        if ($name !== 'mail') { Response::notFound('No such test'); }
        $db = Database::getInstance();
        $to = (string)($db->fetchOne('SELECT email FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => (int)Auth::user()['id']])['email'] ?? '');
        if (!Mailer::configured()) { Response::success('Mail test', ['ok' => false, 'message' => 'Mail is not configured yet.']); }
        try {
            Mailer::sendTest($to);
            Response::success('Mail test', ['ok' => true, 'message' => "Sent to $to at " . gmdate('H:i') . ' UTC. Check the inbox.']);
        } catch (MailException $e) {
            Response::success('Mail test', ['ok' => false, 'message' => $e->getMessage()]);
        }
    }
}
