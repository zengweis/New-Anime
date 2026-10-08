<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Service\UserService;

final class AuthController
{
    /** GET /api/auth/config —— 前端据此渲染注册表单 */
    public function config(): void
    {
        Response::ok(UserService::registerConfig());
    }

    /** POST /api/auth/register */
    public function register(): void
    {
        $r = UserService::register(Request::body(), Request::ip());
        $r['ok'] ? Response::ok($r, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/auth/login */
    public function login(): void
    {
        $r = UserService::login(
            Request::str('account') ?: Request::str('username'),
            (string) Request::input('password', ''),
            Request::ip()
        );
        if (!$r['ok']) {
            Response::fail($r['msg']);
        }
        Response::ok(['token' => $r['token'], 'user' => $r['user']], $r['msg']);
    }

    /** GET /api/auth/me */
    public function me(): void
    {
        Response::ok(Auth::requireUser());
    }

    /** POST /api/auth/verify-email */
    public function verifyEmail(): void
    {
        $r = UserService::verifyEmail(Request::str('token'));
        $r['ok'] ? Response::ok(null, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/auth/resend-verify */
    public function resendVerify(): void
    {
        $r = UserService::resendVerify(Request::str('email'), Request::ip());
        $r['ok'] ? Response::ok(null, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/auth/forgot-password */
    public function forgotPassword(): void
    {
        $r = UserService::forgotPassword(Request::str('email'));
        $r['ok'] ? Response::ok(null, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/auth/reset-password */
    public function resetPassword(): void
    {
        $r = UserService::resetPassword(
            Request::str('token'),
            (string) Request::input('password', '')
        );
        $r['ok'] ? Response::ok(null, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/auth/change-password */
    public function changePassword(): void
    {
        $u = Auth::requireUser();
        $r = UserService::changePassword(
            (int) $u['id'],
            (string) Request::input('old_password', ''),
            (string) Request::input('new_password', '')
        );
        $r['ok'] ? Response::ok(null, $r['msg']) : Response::fail($r['msg']);
    }
}
