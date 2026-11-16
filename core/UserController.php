<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Account administration (admin role only; enforced by the route table). */
final class UserController
{
    private function rows(): array
    {
        $db = Database::getInstance();
        return array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'username' => $r['username'], 'email' => $r['email'], 'role' => $r['role'],
            'disabled' => (bool)$r['disabled'], 'mfa' => (bool)$r['mfa'], 'created_at' => $r['created_at'],
        ], $db->fetchAll('SELECT id, username, email, role, disabled, totp_secret IS NOT NULL AS mfa, created_at FROM `' . $db->t('users') . '` ORDER BY id'));
    }

    public function list(array $params): never
    {
        Response::success('Users', $this->rows());
    }

    public function create(array $params): never
    {
        $in = Request::json();
        $username = Validator::str($in, 'username', 60, true);
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/D', $username)) { Validator::fail('username', 'must be 3-60 characters: letters, digits, . _ -'); }
        $email = strtolower(Validator::str($in, 'email', 100, true));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Validator::fail('email', 'must be a valid address'); }
        $role = Validator::enum($in, 'role', array_keys(Accounts::ROLES), (string)Config::get('users.default_role'));
        $password = $in['password'] ?? null;
        $invite = $password === null || $password === '';
        if (!$invite) {
            if (!is_string($password)) { Validator::fail('password', 'must be text'); }
            try { Accounts::validatePassword($password); } catch (\InvalidArgumentException $e) { Response::error($e->getMessage(), 400); }
        } elseif (!Mailer::configured() || Config::get('base_url', '') === '') {
            Response::error('Without a password the person is invited by e-mail, which needs mail and base_url to be configured', 400);
        }
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT id FROM `' . $db->t('users') . '` WHERE username = :u OR email = :e', ['u' => $username, 'e' => $email])) { Response::error('That username or e-mail is already in use', 409); }
        // An invited account gets a random password nobody knows until the person sets theirs through the invite link.
        $id = (int)$db->insert('users', [
            'username' => $username, 'email' => $email, 'display_name' => $username, 'role' => $role,
            'password' => password_hash($invite ? bin2hex(random_bytes(24)) : (string)$password, PASSWORD_DEFAULT),
        ]);
        if ($invite) { Accounts::sendLink(['id' => $id, 'email' => $email], 'invite'); }
        Audit::log('user_created', null, '', "$username as $role" . ($invite ? ' (invited)' : ''));
        Alerts::notify('user_created', "Account $username ($role) was created by " . (Auth::user()['username'] ?? '?') . '.', 'info', $username);
        Response::success($invite ? 'User created; an invitation e-mail was sent' : 'User created', ['id' => $id], 201);
    }

    public function update(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $in = Request::json();
        $me = Auth::user();
        $target = $this->find($id);
        $data = [];
        if (array_key_exists('role', $in)) { $data['role'] = Validator::enum($in, 'role', array_keys(Accounts::ROLES), $target['role']); }
        if (array_key_exists('disabled', $in)) { $data['disabled'] = !empty($in['disabled']) ? 1 : 0; }
        if (array_key_exists('email', $in)) {
            $e = strtolower(Validator::str($in, 'email', 100, true));
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) { Validator::fail('email', 'must be a valid address'); }
            $data['email'] = $e;
        }
        if (!$data) { Response::error('Nothing to change', 400); }
        $losesAdmin = $target['role'] === 'admin' && (int)$target['disabled'] === 0
            && ((isset($data['role']) && $data['role'] !== 'admin') || !empty($data['disabled']));
        if ($id === $me['id'] && (isset($data['role']) && $data['role'] !== $target['role'] || !empty($data['disabled']))) { Response::error('You cannot change your own role or disable yourself', 409); }
        if ($losesAdmin && Accounts::enabledAdminCount() <= 1) { Response::error('There must always be at least one enabled administrator', 409); }
        $db = Database::getInstance();
        try { $db->update('users', $data, ['id' => $id]); } catch (DatabaseException) { Response::error('That e-mail is already in use', 409); }
        Audit::log('user_updated', null, '', "{$target['username']}: " . json_encode(array_keys($data)) . (isset($data['role']) ? " role {$target['role']}->{$data['role']}" : '') . (!empty($data['disabled']) ? ' disabled' : ''));
        if (isset($data['role']) || isset($data['disabled'])) { Alerts::notify('account_changed', "Account {$target['username']} changed by {$me['username']}: " . json_encode($data), 'warning', $target['username']); }
        Response::success('User updated');
    }

    public function delete(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $target = $this->find($id);
        if ($id === Auth::user()['id']) { Response::error('You cannot delete your own account', 409); }
        if ($target['role'] === 'admin' && (int)$target['disabled'] === 0 && Accounts::enabledAdminCount() <= 1) { Response::error('There must always be at least one enabled administrator', 409); }
        $db = Database::getInstance();
        $db->delete('recovery_codes', ['user_id' => $id]);
        $db->delete('password_resets', ['user_id' => $id]);
        $db->delete('users', ['id' => $id]);
        Audit::log('user_deleted', null, '', $target['username']);
        Alerts::notify('account_deleted', "Account {$target['username']} was deleted by " . Auth::user()['username'] . '.', 'warning', $target['username']);
        Response::success('User deleted');
    }

    public function resetMfa(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $target = $this->find($id);
        $db = Database::getInstance();
        $db->query('UPDATE `' . $db->t('users') . '` SET totp_secret = NULL, totp_last_step = 0 WHERE id = :i', ['i' => $id]);
        $db->delete('recovery_codes', ['user_id' => $id]);
        Audit::log('mfa_reset_by_admin', null, '', $target['username']);
        Alerts::notify('mfa_reset', "MFA was removed from {$target['username']} by " . Auth::user()['username'] . '.', 'warning', $target['username']);
        Response::success('MFA removed for that user');
    }

    public function sendReset(array $params): never
    {
        $target = $this->find(Validator::id($params['id'] ?? null));
        if ((int)$target['disabled'] === 1) { Response::error('That account is disabled', 409); }
        if (!Accounts::sendLink($target, 'reset')) { Response::error('E-mail is not configured (mail settings and base_url are required)', 409); }
        Audit::log('password_reset_sent_by_admin', null, '', $target['username']);
        Response::success('Reset e-mail queued');
    }

    private function find(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT id, username, email, role, disabled FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('User not found');
    }
}
