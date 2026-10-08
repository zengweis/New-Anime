<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 无状态令牌（HMAC-SHA256 签名的 JWT-like）。
 * 每次校验都回查数据库，保证封禁/改角色即时生效。
 */
final class Auth
{
    private const TTL = 604800; // 7 天

    private static ?array $cachedUser = null;
    private static bool $resolved = false;

    // ---------------- 密码 ----------------

    public static function hash(string $pwd): string
    {
        return password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function checkPassword(string $pwd, string $hash): bool
    {
        return password_verify($pwd, $hash);
    }

    // ---------------- 令牌 ----------------

    public static function issue(int $userId, string $role, ?int $ttl = null): string
    {
        $payload = [
            'uid'  => $userId,
            'role' => $role,
            'iat'  => time(),
            'exp'  => time() + ($ttl ?? self::TTL),
        ];
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::sign($body);
    }

    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        if (!hash_equals(self::sign($body), $sig)) {
            return null;
        }
        $d = json_decode(self::unb64($body), true);
        if (!is_array($d) || empty($d['uid']) || empty($d['exp'])) {
            return null;
        }
        if ((int) $d['exp'] < time()) {
            return null;
        }
        return $d;
    }

    private static function sign(string $body): string
    {
        return self::b64(hash_hmac('sha256', $body, (string) cfg('secret'), true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $enc): string
    {
        $pad = strlen($enc) % 4;
        if ($pad) {
            $enc .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($enc, '-_', '+/'), true);
    }

    // ---------------- 会话 ----------------

    public static function user(bool $fresh = false): ?array
    {
        if (self::$resolved && !$fresh) {
            return self::$cachedUser;
        }
        self::$resolved = true;
        self::$cachedUser = null;

        $payload = self::parse(Request::token());
        if ($payload === null) {
            return null;
        }
        $u = Database::one(
            'SELECT `id`,`username`,`email`,`nickname`,`avatar`,`role`,`status`,`created_at`
             FROM `user` WHERE `id` = :id LIMIT 1',
            ['id' => (int) $payload['uid']]
        );
        if ($u === null || (int) $u['status'] !== 1) {
            return null;
        }
        self::$cachedUser = $u;
        return $u;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if ($u === null) {
            Response::error('未登录或登录已过期', 401);
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if (($u['role'] ?? '') !== 'admin') {
            Response::error('需要管理员权限', 403);
        }
        return $u;
    }
}
