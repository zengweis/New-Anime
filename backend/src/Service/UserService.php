<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Log;
use App\Core\Request;
use App\Core\Setting;

/**
 * 用户注册 / 登录 / 邮箱验证 / 找回密码。
 */
final class UserService
{
    public const VERIFY_TTL = 86400;  // 24h
    public const RESET_TTL  = 3600;   // 1h

    // ------------------------------------------------------------------
    //  注册
    // ------------------------------------------------------------------

    /**
     * @param array{username:string,email:string,password:string,invite_code?:string} $in
     * @return array{ok:bool,msg:string,need_verify?:bool,need_approve?:bool}
     */
    public static function register(array $in, string $ip): array
    {
        if (Setting::get('register_enabled', '1') !== '1') {
            return ['ok' => false, 'msg' => '本站已关闭注册'];
        }

        $username = trim((string) ($in['username'] ?? ''));
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        $invite   = trim((string) ($in['invite_code'] ?? ''));

        // --- 字段校验 ---
        if (!preg_match('/^[\p{Han}A-Za-z][\p{Han}A-Za-z0-9_\-]{1,19}$/u', $username)) {
            return ['ok' => false, 'msg' => '用户名需 2-20 位，以中文或字母开头，可含数字、下划线、短横线'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '邮箱格式不正确'];
        }
        $perr = self::checkPasswordStrength($password);
        if ($perr !== null) {
            return ['ok' => false, 'msg' => $perr];
        }

        // --- 频率限制 ---
        if ($ip !== '' && $ip !== '0.0.0.0') {
            $recent = (int) Database::val(
                'SELECT COUNT(*) FROM `user` WHERE `reg_ip` = :ip AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                ['ip' => $ip]
            );
            if ($recent >= 5) {
                return ['ok' => false, 'msg' => '注册过于频繁，请一小时后再试'];
            }
        }

        // --- 唯一性 ---
        if (Database::val('SELECT 1 FROM `user` WHERE `username` = :u', ['u' => $username]) !== null) {
            return ['ok' => false, 'msg' => '该用户名已被占用'];
        }
        if (Database::val('SELECT 1 FROM `user` WHERE `email` = :e', ['e' => $email]) !== null) {
            return ['ok' => false, 'msg' => '该邮箱已被注册'];
        }

        // --- 邀请码 ---
        $inviteRow  = null;
        $needInvite = Setting::get('register_invite', '0') === '1';
        if ($needInvite) {
            if ($invite === '') {
                return ['ok' => false, 'msg' => '本站需要邀请码才能注册'];
            }
            $inviteRow = self::validateInvite($invite, $email);
            if ($inviteRow === null) {
                return ['ok' => false, 'msg' => '邀请码无效或已失效'];
            }
        }

        // --- 决定初始状态 ---
        $needVerify  = Setting::get('register_verify_email', '0') === '1';
        $needApprove = Setting::get('register_need_approve', '0') === '1';
        $status = $needVerify ? 0 : ($needApprove ? 2 : 1);

        try {
            Database::beginTransaction();

            $uid = Database::insert('user', [
                'username'       => $username,
                'email'          => $email,
                'password_hash'  => Auth::hash($password),
                'nickname'       => $username,
                'role'           => (string) Setting::get('register_default_role', 'user') === 'admin' ? 'user' : 'user',
                'status'         => $status,
                'invite_code_id' => $inviteRow ? (int) $inviteRow['id'] : null,
                'reg_ip'         => $ip,
            ]);

            if ($inviteRow !== null) {
                Database::run(
                    'UPDATE `invite_code` SET `used_count` = `used_count` + 1 WHERE `id` = :id',
                    ['id' => (int) $inviteRow['id']]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            Log::write('auth', '注册失败: ' . $e->getMessage(), ['username' => $username]);
            return ['ok' => false, 'msg' => '注册失败，请稍后重试'];
        }

        $user = Database::one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $uid]);

        if ($needVerify && $user !== null) {
            $rawToken = self::issueVerifyToken($user);
            MailService::verifyEmail($user, $rawToken);
        }

        Log::write('auth', '新用户注册', ['uid' => $uid, 'username' => $username, 'status' => $status, 'ip' => $ip]);

        return [
            'ok'           => true,
            'msg'          => $needVerify ? '注册成功，请前往邮箱完成验证' : '注册成功',
            'need_verify'  => $needVerify,
            'need_approve' => $needApprove && !$needVerify,
        ];
    }

    // ------------------------------------------------------------------
    //  登录
    // ------------------------------------------------------------------

    /**
     * @return array{ok:bool,msg:string,token?:string,user?:array}
     */
    public static function login(string $account, string $password, string $ip): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号和密码'];
        }

        // 限流：同 IP 10 分钟内 20 次失败
        $fails = (int) Database::val(
            "SELECT COUNT(*) FROM `job_log`
             WHERE `job` = 'login_fail' AND `message` LIKE :ip AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            ['ip' => '%' . $ip . '%']
        );
        if ($fails >= 20) {
            return ['ok' => false, 'msg' => '尝试次数过多，请 10 分钟后再试'];
        }

        $u = Database::one(
            'SELECT * FROM `user` WHERE `username` = :a OR `email` = :a LIMIT 1',
            ['a' => $account]
        );

        if ($u === null || !Auth::checkPassword($password, (string) $u['password_hash'])) {
            Database::insert('job_log', [
                'job'     => 'login_fail',
                'ok'      => 0,
                'message' => "失败 {$ip} account={$account}",
            ]);
            return ['ok' => false, 'msg' => '账号或密码错误'];
        }

        $status = (int) $u['status'];
        if ($status === 0) {
            return ['ok' => false, 'msg' => '邮箱尚未验证，请先完成邮箱验证', ];
        }
        if ($status === 2) {
            return ['ok' => false, 'msg' => '账号正在等待管理员审核'];
        }
        if ($status === 9) {
            return ['ok' => false, 'msg' => '账号已被禁用'];
        }

        Database::update('user', [
            'last_login' => date('Y-m-d H:i:s'),
            'last_ip'    => $ip,
        ], '`id` = :id', ['id' => (int) $u['id']]);

        $token = Auth::issue((int) $u['id'], (string) $u['role']);

        unset($u['password_hash'], $u['reg_ip']);

        return ['ok' => true, 'msg' => '登录成功', 'token' => $token, 'user' => $u];
    }

    // ------------------------------------------------------------------
    //  邮箱验证
    // ------------------------------------------------------------------

    public static function issueVerifyToken(array $user): string
    {
        return self::issueToken((int) $user['id'], (string) $user['email'], 'verify', self::VERIFY_TTL);
    }

    private static function issueToken(int $userId, string $email, string $type, int $ttl): string
    {
        $raw = bin2hex(random_bytes(32));

        // 同类旧令牌作废，避免一个账号堆积多个有效链接
        Database::run(
            'UPDATE `email_token` SET `used_at` = NOW()
             WHERE `user_id` = :uid AND `type` = :t AND `used_at` IS NULL',
            ['uid' => $userId, 't' => $type]
        );

        Database::insert('email_token', [
            'user_id'    => $userId,
            'email'      => $email,
            'token_hash' => hash('sha256', $raw),
            'type'       => $type,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return $raw;
    }

    /**
     * 消费令牌，返回用户行。
     */
    private static function consumeToken(string $raw, string $type): ?array
    {
        if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM `email_token`
             WHERE `token_hash` = :h AND `type` = :t AND `used_at` IS NULL AND `expires_at` > NOW()
             LIMIT 1',
            ['h' => hash('sha256', $raw), 't' => $type]
        );
        return $row ?: null;
    }

    public static function verifyEmail(string $rawToken): array
    {
        $tok = self::consumeToken($rawToken, 'verify');
        if ($tok === null) {
            return ['ok' => false, 'msg' => '验证链接无效或已过期'];
        }

        Database::run('UPDATE `email_token` SET `used_at` = NOW() WHERE `id` = :id', ['id' => (int) $tok['id']]);

        $u = Database::one('SELECT * FROM `user` WHERE `id` = :id', ['id' => (int) $tok['user_id']]);
        if ($u === null) {
            // 账号已被删除（管理员清理等），顺手清掉这条孤儿令牌，给明确提示
            Database::run('DELETE FROM `email_token` WHERE `id` = :id', ['id' => (int) $tok['id']]);
            return ['ok' => false, 'msg' => '该验证链接对应的账号已不存在，请重新注册'];
        }
        if ((int) $u['status'] === 1) {
            return ['ok' => true, 'msg' => '邮箱已验证，请直接登录'];
        }

        // 验证通过后若还需人工审核，则进入待审核
        $next = Setting::get('register_need_approve', '0') === '1' ? 2 : 1;
        Database::update('user', ['status' => $next], '`id` = :id', ['id' => (int) $u['id']]);

        return [
            'ok'  => true,
            'msg' => $next === 2 ? '邮箱验证完成，账号正在等待管理员审核' : '邮箱验证完成，现在可以登录了',
        ];
    }

    public static function resendVerify(string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        $u = Database::one('SELECT * FROM `user` WHERE `email` = :e LIMIT 1', ['e' => $email]);

        // 统一话术，避免邮箱枚举
        $okMsg = '若该邮箱已注册且未验证，验证邮件已重新发送';

        if ($u === null || (int) $u['status'] !== 0) {
            return ['ok' => true, 'msg' => $okMsg];
        }

        // 同邮箱 60 秒 / 每天 5 封
        $tooSoon = (int) Database::val(
            "SELECT COUNT(*) FROM `mail_queue`
             WHERE `to_email` = :e AND `created_at` > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            ['e' => $email]
        );
        if ($tooSoon > 0) {
            return ['ok' => false, 'msg' => '发送过于频繁，请 60 秒后再试'];
        }
        $today = (int) Database::val(
            'SELECT COUNT(*) FROM `mail_queue` WHERE `to_email` = :e AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 DAY)',
            ['e' => $email]
        );
        if ($today >= 5) {
            return ['ok' => false, 'msg' => '今日验证邮件发送次数已达上限'];
        }

        $rawToken = self::issueVerifyToken($u);
        MailService::verifyEmail($u, $rawToken);
        return ['ok' => true, 'msg' => $okMsg];
    }

    // ------------------------------------------------------------------
    //  找回密码
    // ------------------------------------------------------------------

    public static function forgotPassword(string $email): array
    {
        $email = strtolower(trim($email));
        $okMsg = '若该邮箱已注册，重置链接已发送';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '邮箱格式不正确'];
        }

        $u = Database::one('SELECT * FROM `user` WHERE `email` = :e AND `status` <> 9 LIMIT 1', ['e' => $email]);
        if ($u === null) {
            return ['ok' => true, 'msg' => $okMsg];
        }

        $today = (int) Database::val(
            'SELECT COUNT(*) FROM `mail_queue` WHERE `to_email` = :e AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 DAY)',
            ['e' => $email]
        );
        if ($today >= 5) {
            return ['ok' => false, 'msg' => '今日邮件发送次数已达上限'];
        }

        $raw = self::issueToken((int) $u['id'], $email, 'reset', self::RESET_TTL);
        MailService::passwordReset($u, $raw);

        return ['ok' => true, 'msg' => $okMsg];
    }

    public static function resetPassword(string $rawToken, string $newPassword): array
    {
        $perr = self::checkPasswordStrength($newPassword);
        if ($perr !== null) {
            return ['ok' => false, 'msg' => $perr];
        }

        $tok = self::consumeToken($rawToken, 'reset');
        if ($tok === null) {
            return ['ok' => false, 'msg' => '重置链接无效或已过期'];
        }

        $uid = (int) $tok['user_id'];
        Database::run('UPDATE `email_token` SET `used_at` = NOW() WHERE `id` = :id', ['id' => (int) $tok['id']]);
        // 作废该用户全部重置令牌
        Database::run(
            'UPDATE `email_token` SET `used_at` = NOW()
             WHERE `user_id` = :id AND `type` = \'reset\' AND `used_at` IS NULL',
            ['id' => $uid]
        );

        Database::update('user', ['password_hash' => Auth::hash($newPassword)], '`id` = :id', ['id' => $uid]);

        return ['ok' => true, 'msg' => '密码已重置，请用新密码登录'];
    }

    public static function changePassword(int $uid, string $old, string $new): array
    {
        $u = Database::one('SELECT * FROM `user` WHERE `id` = :id', ['id' => $uid]);
        if ($u === null || !Auth::checkPassword($old, (string) $u['password_hash'])) {
            return ['ok' => false, 'msg' => '原密码不正确'];
        }
        $perr = self::checkPasswordStrength($new);
        if ($perr !== null) {
            return ['ok' => false, 'msg' => $perr];
        }
        Database::update('user', ['password_hash' => Auth::hash($new)], '`id` = :id', ['id' => $uid]);
        return ['ok' => true, 'msg' => '密码已修改'];
    }

    // ------------------------------------------------------------------
    //  邀请码
    // ------------------------------------------------------------------

    private static function validateInvite(string $code, string $email): ?array
    {
        $row = Database::one(
            'SELECT * FROM `invite_code` WHERE UPPER(`code`) = :c LIMIT 1',
            ['c' => strtoupper($code)]
        );
        if ($row === null) {
            return null;
        }
        if ((int) $row['status'] !== 1) {
            return null;
        }
        if ((int) $row['used_count'] >= (int) $row['max_uses']) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        if ($row['bound_email'] !== null && $row['bound_email'] !== '' && strtolower((string) $row['bound_email']) !== $email) {
            return null;
        }
        return $row;
    }

    /** 生成 n 个邀请码 */
    public static function createInvites(int $count, int $maxUses, ?int $expireDays, ?string $note, ?int $createdBy): array
    {
        $codes = [];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混字符
        for ($i = 0; $i < max(1, min(500, $count)); $i++) {
            for ($try = 0; $try < 5; $try++) {
                $code = '';
                for ($k = 0; $k < 8; $k++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $code = 'AN-' . substr($code, 0, 4) . '-' . substr($code, 4, 4);
                if (Database::val('SELECT 1 FROM `invite_code` WHERE `code` = :c', ['c' => $code]) === null) {
                    Database::insert('invite_code', [
                        'code'        => $code,
                        'max_uses'    => max(1, $maxUses),
                        'expires_at'  => $expireDays ? date('Y-m-d H:i:s', time() + $expireDays * 86400) : null,
                        'note'        => $note,
                        'created_by'  => $createdBy,
                    ]);
                    $codes[] = $code;
                    break;
                }
            }
        }
        return $codes;
    }

    // ------------------------------------------------------------------

    public static function checkPasswordStrength(string $p): ?string
    {
        if (strlen($p) < 8) {
            return '密码至少 8 位';
        }
        if (strlen($p) > 72) {
            return '密码过长';
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            return '密码需同时包含字母和数字';
        }
        return null;
    }

    /** 给前端渲染注册表单用 */
    public static function registerConfig(): array
    {
        return [
            'enabled'       => Setting::get('register_enabled', '1') === '1',
            'need_invite'   => Setting::get('register_invite', '0') === '1',
            'need_verify'   => Setting::get('register_verify_email', '0') === '1',
            'need_approve'  => Setting::get('register_need_approve', '0') === '1',
            'site_name'     => (string) Setting::get('site_name', 'Anime New'),
        ];
    }

    public static function ip(): string
    {
        return Request::ip();
    }
}
