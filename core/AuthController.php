<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

final class AuthController
{
    /** Public, minimal, no version/time/host details. */
    public function health(array $params): never
    {
        Response::success('ok');
    }

    public function login(array $params): never
    {
        $in = Request::json();
        $username = Validator::str($in, 'username', 60, true);
        $password = $in['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            Response::error('Username and password are required', 400);
        }
        $user = Auth::login($username, $password);
        if ($user === null) {
            Response::error('Invalid username or password', 401);
        }
        Response::success('Logged in', ['user' => $user, 'csrf_token' => Auth::csrfToken()]);
    }

    public function logout(array $params): never
    {
        Auth::logout();
        Response::success('Logged out');
    }

    public function me(array $params): never
    {
        Response::success('ok', ['user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
    }
}
